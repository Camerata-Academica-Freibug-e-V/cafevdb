<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\CAFEVDB\Tests\Unit\Settings;

use DateTimeZone;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use TypeError;

use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IInitialStateService;

use OCA\CAFEVDB\AddressBook\AddressBookProvider;
use OCA\CAFEVDB\Service\CloudUserConnectorService;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\GeoCodingService;
use OCA\CAFEVDB\Service\ProjectService;
use OCA\CAFEVDB\Service\WebPagesRPC;
use OCA\CAFEVDB\Settings\OrchestraSettingsInitialState;
use OCA\CAFEVDB\Storage\UserStorage;

#[Attributes\CoversClass(OrchestraSettingsInitialState::class)]
class OrchestraSettingsInitialStateTest extends TestCase
{
  private OrchestraSettingsInitialState $form;

  private array $published = [];

  protected function setUp():void
  {
    $this->form = (new ReflectionClass(OrchestraSettingsInitialState::class))->newInstanceWithoutConstructor();
    $l10n = $this->createStub(IL10N::class);
    $l10n->method('t')->willReturnCallback(fn(string $text) => $text);
    (new ReflectionProperty(OrchestraSettingsInitialState::class, 'l'))->setValue($this->form, $l10n);
  }

  /** @return void */
  public function testHolidayRegionsUseLocaleAndLocalizedNames():void
  {
    $geoCodingService = $this->createMock(GeoCodingService::class);
    $geoCodingService->expects($this->once())->method('getRegionNames')
      ->with('DE')->willReturn(['BW' => 'Baden-Wuerttemberg']);
    (new ReflectionProperty(OrchestraSettingsInitialState::class, 'geoCodingService'))->setValue($this->form, $geoCodingService);

    $options = (new ReflectionMethod(OrchestraSettingsInitialState::class, 'bankHolidayOptions'))->invoke($this->form, 'de_DE.UTF-8');
    $this->assertSame(['value' => 'DE-NATIONAL', 'name' => 'national holidays'], $options[0]);
    $this->assertContains(['value' => 'DE-BW', 'name' => 'Baden-Wuerttemberg'], $options);
    foreach ($options as $option) {
      $this->assertStringStartsWith('DE-', $option['value']);
    }
    $this->assertSame(array_values($options), $options);
  }

  /** @return void */
  public function testMissingRegionNamesRetainSelectableRegionCodes():void
  {
    $geoCodingService = $this->createMock(GeoCodingService::class);
    $geoCodingService->expects($this->once())->method('getRegionNames')
      ->with('DE')->willReturn(null);
    (new ReflectionProperty(OrchestraSettingsInitialState::class, 'geoCodingService'))->setValue($this->form, $geoCodingService);

    $options = (new ReflectionMethod(OrchestraSettingsInitialState::class, 'bankHolidayOptions'))->invoke($this->form, 'de_DE');
    $this->assertContains(['value' => 'DE-BW', 'name' => 'DE-BW'], $options);
  }

  /** @return void */
  public function testLocaleWithoutCountryHasNoHolidayRegions():void
  {
    $options = (new ReflectionMethod(OrchestraSettingsInitialState::class, 'bankHolidayOptions'))->invoke($this->form, 'de');
    $this->assertSame([], $options);
  }

  /** @return array<string, array{bool, bool, bool}> */
  public static function deniedUsers():array
  {
    return [
      'not an orchestra member' => [false, true, true],
      'not an orchestra sub-admin' => [true, false, true],
      'no valid encryption key' => [true, true, false],
    ];
  }

  #[Attributes\DataProvider('deniedUsers')]
  public function testOrchestraStateIsNotPublishedWithoutMembershipRightsAndKey(
    bool $member,
    bool $subAdmin,
    bool $validKey,
  ):void {
    $form = $this->getMockBuilder(OrchestraSettingsInitialState::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['inGroup', 'isSubAdminOfGroup', 'encryptionKeyValid', 'buildSettings'])
      ->getMock();
    $form->method('inGroup')->willReturn($member);
    $form->method('isSubAdminOfGroup')->willReturn($subAdmin);
    $form->method('encryptionKeyValid')->willReturn($validKey);
    $form->expects($this->never())->method('buildSettings');

    $this->preparePublishing($form);
    $form->publish();
    $this->assertSame(['enabled' => false], $this->published['orchestraSettings']);
    $this->assertArrayNotHasKey('about', $this->published);
    $this->assertArrayNotHasKey('adminSettings', $this->published);
  }

  /** @return array<string, array{string, Throwable}> */
  public static function configurationFailures():array
  {
    return [
      'unbound encryption credentials' => ['encryptionKeyValid', new RuntimeException('Credentials are not bound')],
      'configuration exception' => ['buildSettings', new RuntimeException('Database unavailable')],
      'configuration error' => ['buildSettings', new TypeError('Invalid configuration data')],
    ];
  }

  #[Attributes\DataProvider('configurationFailures')]
  public function testConfigurationFailuresAreLoggedWithoutPublishingPartialSettings(
    string $method,
    Throwable $failure,
  ):void {
    $form = $this->getMockBuilder(OrchestraSettingsInitialState::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['inGroup', 'isSubAdminOfGroup', 'encryptionKeyValid', 'buildSettings', 'logException'])
      ->getMock();
    $form->method('inGroup')->willReturn(true);
    $form->method('isSubAdminOfGroup')->willReturn(true);
    if ($method === 'encryptionKeyValid') {
      $form->expects($this->once())->method('encryptionKeyValid')->willThrowException($failure);
      $form->expects($this->never())->method('buildSettings');
    } else {
      $form->method('encryptionKeyValid')->willReturn(true);
      $form->expects($this->once())->method('buildSettings')->willThrowException($failure);
    }
    $form->expects($this->once())->method('logException')->with($failure);

    $this->preparePublishing($form);
    $form->publish();
    $this->assertFailedConfiguration();
  }

  /** @return array<string, array{string}> */
  public static function unavailableGroups():array
  {
    return [
      'deleted orchestra group' => ['missing'],
      'member backend unavailable' => ['members'],
      'group search unavailable' => ['search'],
    ];
  }

  #[Attributes\DataProvider('unavailableGroups')]
  public function testGroupFailuresDisableOrchestraSettingsRatherThanPublishingEmptyOptions(string $operation):void
  {
    $failure = new RuntimeException('Group backend unavailable');
    $form = $this->getMockBuilder(OrchestraSettingsInitialState::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['inGroup', 'isSubAdminOfGroup', 'encryptionKeyValid', 'group', 'groupManager', 'logException'])
      ->getMock();
    $form->method('inGroup')->willReturn(true);
    $form->method('isSubAdminOfGroup')->willReturn(true);
    $form->method('encryptionKeyValid')->willReturn(true);
    $group = $this->createStub(IGroup::class);
    $form->method('group')->willReturn($operation === 'missing' ? null : $group);
    if ($operation === 'members') {
      $group->method('getUsers')->willThrowException($failure);
    } else {
      $group->method('getUsers')->willReturn([]);
    }
    $manager = $this->createStub(IGroupManager::class);
    $manager->method('search')->willThrowException($failure);
    $form->method('groupManager')->willReturn($manager);
    $form->expects($this->once())->method('logException')->with(
      $operation === 'missing' ? $this->isInstanceOf(RuntimeException::class) : $failure,
    );

    $this->preparePublishing($form);
    $form->publish();
    $this->assertFailedConfiguration();
  }

  /** @return array<string, array{string, Throwable}> */
  public static function participantFailures():array
  {
    return [
      'club members exception' => ['memberProjectId', new RuntimeException('Participant lookup failed')],
      'club members error' => ['memberProjectId', new TypeError('Participant lookup failed')],
      'executive board exception' => ['executiveBoardProjectId', new RuntimeException('Participant lookup failed')],
      'executive board error' => ['executiveBoardProjectId', new TypeError('Participant lookup failed')],
    ];
  }

  #[Attributes\DataProvider('participantFailures')]
  public function testParticipantFailuresAreReportedRatherThanPublishedAsEmptyOptions(string $projectKey, Throwable $failure):void
  {
    $form = $this->getMockBuilder(OrchestraSettingsInitialState::class)
      ->disableOriginalConstructor()
      ->onlyMethods([
        'inGroup', 'isSubAdminOfGroup', 'encryptionKeyValid', 'group', 'groupManager',
        'di', 'databaseConfigured', 'getConfigValue', 'getDateTimeZone', 'logDebug', 'logException',
      ])
      ->getMock();
    $form->method('inGroup')->willReturn(true);
    $form->method('isSubAdminOfGroup')->willReturn(true);
    $form->method('encryptionKeyValid')->willReturn(true);
    $group = $this->createStub(IGroup::class);
    $group->method('getUsers')->willReturn([]);
    $form->method('group')->willReturn($group);
    $manager = $this->createStub(IGroupManager::class);
    $manager->method('search')->willReturn([]);
    $form->method('groupManager')->willReturn($manager);
    $form->method('databaseConfigured')->willReturn(true);
    $form->method('getDateTimeZone')->willReturn(new DateTimeZone('UTC'));
    $form->method('getConfigValue')->willReturnCallback(
      fn(string $key, mixed $default = null) => $key === $projectKey ? 42 : $default,
    );
    $projects = $this->createMock(ProjectService::class);
    $projects->method('projectOptions')->willReturn([]);
    $projects->expects($this->once())->method('participantOptions')->willThrowException($failure);
    $services = [
      ProjectService::class => $projects,
      WebPagesRPC::class => $this->createStub(WebPagesRPC::class),
      AddressBookProvider::class => $this->createStub(AddressBookProvider::class),
      UserStorage::class => $this->createStub(UserStorage::class),
      CloudUserConnectorService::class => $this->createStub(CloudUserConnectorService::class),
      GeoCodingService::class => $this->createStub(GeoCodingService::class),
    ];
    $form->method('di')->willReturnCallback(fn(string $class) => $services[$class]);
    $form->expects($this->once())->method('logException')->with($failure);

    $this->preparePublishing($form);
    $form->publish();
    $this->assertFailedConfiguration();
  }

  /** @return void */
  public function testOrchestraSectionsAreEnabledOnlyAfterTheirConfigurationIsPublished():void
  {
    $form = $this->getMockBuilder(OrchestraSettingsInitialState::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['inGroup', 'isSubAdminOfGroup', 'encryptionKeyValid', 'buildSettings', 'logException'])
      ->getMock();
    $form->method('inGroup')->willReturn(true);
    $form->method('isSubAdminOfGroup')->willReturn(true);
    $form->method('encryptionKeyValid')->willReturn(true);
    $form->method('buildSettings')->willReturn(['orchestraName' => 'Test orchestra']);
    $form->expects($this->never())->method('logException');
    $this->preparePublishing($form, 2);

    $form->publish();
    $this->assertSame(['adminSettings', 'orchestraSettings'], array_keys($this->published));
    $this->assertSame(['orchestraName' => 'Test orchestra'], $this->published['adminSettings']);
    $this->assertSame(['enabled' => true], $this->published['orchestraSettings']);
  }

  /** @return void */
  private function assertFailedConfiguration():void
  {
    $this->assertArrayNotHasKey('about', $this->published);
    $this->assertArrayNotHasKey('adminSettings', $this->published);
    $this->assertSame([
      'enabled' => false,
      'error' => 'Unable to load orchestra settings. The remaining administration settings are still available. See the server log for details.',
    ], $this->published['orchestraSettings']);
  }

  /**
   * @param OrchestraSettingsInitialState $form Publisher under test.
   *
   * @param int $publications Expected initial-state publications.
   *
   * @return void
   */
  private function preparePublishing(OrchestraSettingsInitialState $form, int $publications = 1):void
  {
    $config = $this->createMock(ConfigService::class);
    $config->method('getAppName')->willReturn('cafevdb');
    $config->expects($this->never())->method('getAppContainer');
    (new ReflectionProperty(OrchestraSettingsInitialState::class, 'configService'))->setValue($form, $config);
    $l10n = $this->createStub(IL10N::class);
    $l10n->method('t')->willReturnArgument(0);
    (new ReflectionProperty(OrchestraSettingsInitialState::class, 'l'))->setValue($form, $l10n);
    $state = $this->createMock(IInitialStateService::class);
    $state->expects($this->exactly($publications))->method('provideInitialState')
      ->willReturnCallback(function(string $app, string $key, mixed $value) {
        $this->published[$key] = $value;
      });
    (new ReflectionProperty(OrchestraSettingsInitialState::class, 'initialStateService'))->setValue($form, $state);
  }
}
