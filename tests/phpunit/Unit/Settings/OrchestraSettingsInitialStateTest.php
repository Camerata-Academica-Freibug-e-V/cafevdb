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

use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

use OCP\IL10N;
use OCP\IInitialStateService;
use OCP\App\IAppManager;

use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\GeoCodingService;
use OCA\CAFEVDB\Settings\OrchestraSettingsInitialState;

#[Attributes\CoversClass(OrchestraSettingsInitialState::class)]
class OrchestraSettingsInitialStateTest extends TestCase
{
  private OrchestraSettingsInitialState $form;

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
      ->onlyMethods(['inGroup', 'isSubAdminOfGroup', 'encryptionKeyValid'])
      ->getMock();
    $form->method('inGroup')->willReturn($member);
    $form->method('isSubAdminOfGroup')->willReturn($subAdmin);
    $form->method('encryptionKeyValid')->willReturn($validKey);

    $config = $this->createMock(ConfigService::class);
    $config->method('getAppName')->willReturn('cafevdb');
    $config->expects($this->never())->method('getAppContainer');
    (new ReflectionProperty(OrchestraSettingsInitialState::class, 'configService'))->setValue($form, $config);
    $apps = $this->createStub(IAppManager::class);
    $apps->method('getAppInfo')->willReturn(['licence' => 'AGPL-3.0']);
    (new ReflectionProperty(OrchestraSettingsInitialState::class, 'appManager'))->setValue($form, $apps);
    $published = [];
    $state = $this->createMock(IInitialStateService::class);
    $state->expects($this->exactly(2))->method('provideInitialState')
      ->willReturnCallback(function(string $app, string $key, mixed $value) use (&$published) {
        $published[$key] = $value;
      });
    (new ReflectionProperty(OrchestraSettingsInitialState::class, 'initialStateService'))->setValue($form, $state);

    $form->publish();
    $this->assertSame(['enabled' => false], $published['orchestraSettings']);
    $this->assertArrayHasKey('about', $published);
    $this->assertArrayNotHasKey('adminSettings', $published);
  }
}
