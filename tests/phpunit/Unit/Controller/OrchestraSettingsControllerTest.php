<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\CAFEVDB\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use OCP\IDateTimeFormatter;
use OCP\IRequest;

use OCA\CAFEVDB\Controller;
use OCA\CAFEVDB\Exceptions;
use OCA\CAFEVDB\Service\CalDavService;
use OCA\CAFEVDB\Service\CloudUserConnectorService;
use OCA\CAFEVDB\Service\ConfigCheckService;
use OCA\CAFEVDB\Service\EmailAddressService;
use OCA\CAFEVDB\Service\Finance\FinanceService;
use OCA\CAFEVDB\Service\FuzzyInputService;
use OCA\CAFEVDB\Service\PhoneNumberService;
use OCA\CAFEVDB\Service\ProjectService;
use OCA\CAFEVDB\Service\L10N\TranslationService;
use OCA\CAFEVDB\Storage\UserStorage;
use OCA\CAFEVDB\Tests\MockProvider;
use OCA\CAFEVDB\Service\WikiRPC;
use OCA\CAFEVDB\Service\WebPagesRPC;
use OCA\RotDrop\Tests\DeprecationException;

/** Test aspects of the OrchestraSettingsController. */
#[Attributes\CoversClass(Controller\OrchestraSettingsController::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\HaliteCryptoFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\HaliteSymmetricStreamCryptor::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Events\EncryptionServiceBound::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ConfigService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\EncryptionService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\L10N\L10NFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\Registration::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Controller\DTO\MessagesResponse::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\AppInfo\AbstractApplication::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Exceptions\EnduserNotificationException::class)]
class OrchestraSettingsControllerTest extends TestCase
{
  use TestRoutesAreDefinedTrait;
  use \OCA\CAFEVDB\Tests\Unit\Database\Doctrine\EnableDeprecationsTrait;

  private const CONTROLLER_CLASS = Controller\OrchestraSettingsController::class;
  private const EXPECTED_ROUTES = [
    'getapp',
    'setapp',
  ];

  private MockProvider $mockProvider;

  private Controller\OrchestraSettingsController $controller;

  private array $postData = [];

  /** {@inheritdoc} */
  public function setup(): void
  {
    self::enableDeprecations();

    $this->mockProvider = $this->mockProvider ?? MockProvider::create($this);

    /** @var IRequest $request */
    $request = $this->createStub(IRequest::class);
    $request->method('getParam')->willReturnCallback(
      function(string $key, mixed $default = null) {
        return $this->postData[$key] ?? $default;
      },
    );

    $appContainer = $this->mockProvider->getAppContainer();

    $this->controller = new Controller\OrchestraSettingsController(
      appName: $this->mockProvider->appName,
      request: $request,
      configService: $this->mockProvider->getConfigService(),
      appContainer: $appContainer,
      calDavService: $this->createStub(CalDavService::class),
      configCheckService: $this->createStub(ConfigCheckService::class),
      emailAddressService: $this->createStub(EmailAddressService::class),
      financeService: $this->createStub(FinanceService::class),
      fuzzyInputService: $this->createStub(FuzzyInputService::class),
      phoneNumberService: $this->createStub(PhoneNumberService::class),
      projectService: $this->createStub(ProjectService::class),
      translationService: $this->createStub(TranslationService::class),
      userStorage: $this->createStub(UserStorage::class),
      webPagesRPC: $this->createStub(WebPagesRPC::class),
      wikiRPC: $this->createStub(WikiRPC::class),
    );
  }

  #[Attributes\DataProvider('userSqlConfigurationResults')]
  public function testRecreateViewsReportsConfigurationFailureAsWarning(bool $configurationFails): void
  {
    $connector = $this->createMock(CloudUserConnectorService::class);
    $connector->expects($this->once())->method('checkRequirements')->willReturn(['hints' => []]);
    $connector->expects($this->once())->method('updateUserSqlViews')->with(null, null, true);
    $connector->expects($this->once())->method('writeUserSqlConfig')->with(null);
    $configuration = $connector->expects($this->once())->method('configureCloudUserBackend');
    if ($configurationFails) {
      $configuration->willThrowException(new RuntimeException('user_sql settings unavailable'));
    } else {
      $configuration->willReturn([]);
    }
    $connector->expects($this->once())->method('setCloudUserSubAdmins');
    $this->mockProvider->registerClassInstance(CloudUserConnectorService::class, $connector);

    $response = $this->controller->setApp('userSqlBackendRecreateViews');
    $data = $response->getData();
    self::assertInstanceOf(Controller\DTO\MessagesResponse::class, $data);
    self::assertNotEmpty($data->messages);
    self::assertCount($configurationFails ? 1 : 0, $data->hints);
    if ($configurationFails) {
      self::assertStringContainsString('user_sql', $data->hints[0]);
    }
  }

  public static function userSqlConfigurationResults(): array
  {
    return ['configured' => [false], 'unavailable' => [true]];
  }

  public function testRecreateViewsStillReportsDatabaseErrors(): void
  {
    $connector = $this->createMock(CloudUserConnectorService::class);
    $connector->method('checkRequirements')->willReturn(['hints' => []]);
    $connector->expects($this->once())->method('updateUserSqlViews')
      ->willThrowException(new Exceptions\DatabaseCloudConnectorViewException('View creation failed'));
    $connector->expects($this->never())->method('configureCloudUserBackend');
    $this->mockProvider->registerClassInstance(CloudUserConnectorService::class, $connector);

    $this->expectException(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Exceptions\EnduserNotificationException::class);
    $this->controller->setApp('userSqlBackendRecreateViews');
  }
}
