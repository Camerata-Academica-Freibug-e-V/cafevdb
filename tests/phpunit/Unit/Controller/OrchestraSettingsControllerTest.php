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

  /**
   * @param bool $configurationFails
   *
   * @return void
   */
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

  /** @return array */
  public static function userSqlConfigurationResults(): array
  {
    return ['configured' => [false], 'unavailable' => [true]];
  }

  /** @return void */
  public function testRecreateViewsReportsEndpointWarning(): void
  {
    $connector = $this->createMock(CloudUserConnectorService::class);
    $connector->method('checkRequirements')->willReturn(['hints' => []]);
    $connector->expects($this->once())->method('updateUserSqlViews');
    $connector->expects($this->once())->method('writeUserSqlConfig');
    $connector->expects($this->once())->method('configureCloudUserBackend')->willReturn(['Check user_sql endpoint']);
    $connector->expects($this->once())->method('setCloudUserSubAdmins');
    $this->mockProvider->registerClassInstance(CloudUserConnectorService::class, $connector);

    $data = $this->controller->setApp('userSqlBackendRecreateViews')->getData();
    self::assertSame(['Check user_sql endpoint'], $data->hints);
  }

  /** @return void */
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

  /** @return void */
  public function testConnectorAccountStoresValidatedCredentialsWithoutReturningPassword(): void
  {
    $account = ['user' => 'restricted', 'accountHost' => '%'];
    $credentials = ['host' => 'db', 'user' => 'restricted', 'accountHost' => '%', 'password' => 'private-password'];
    $connector = $this->createMock(CloudUserConnectorService::class);
    $connector->method('normalizeConnectorAccount')->willReturn($account);
    $connector->expects($this->once())->method('saveConnectorAccount')->with($account);
    $credentialService = $this->createMock(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class);
    $credentialService->expects($this->once())->method('prepare')->willReturn($credentials);
    $credentialService->expects($this->once())->method('save')->with(
      \OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::CLOUD,
      $credentials,
    );
    $credentialService->expects($this->once())->method('markCloudManaged');
    $this->mockProvider->registerClassInstance(CloudUserConnectorService::class, $connector);
    $this->mockProvider->registerClassInstance(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class, $credentialService);
    $this->mockProvider->registerClassInstance(\OCA\CAFEVDB\Database\Connection::class, $this->createStub(\OCA\CAFEVDB\Database\Connection::class));

    $response = $this->controller->setApp('cloudConnectorAccount', $account + ['password' => 'private-password']);
    self::assertStringNotContainsString('private-password', json_encode($response->getData(), JSON_THROW_ON_ERROR));
  }

  /** @return void */
  public function testConnectorAccountRejectsWrongAuthenticatedHost(): void
  {
    restore_error_handler();
    self::enableDeprecations('::grumble');
    $account = ['user' => 'restricted', 'accountHost' => '%'];
    $connector = $this->createMock(CloudUserConnectorService::class);
    $connector->method('normalizeConnectorAccount')->willReturn($account);
    $connector->expects($this->never())->method('saveConnectorAccount');
    $credentialService = $this->createMock(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class);
    $credentialService->method('prepare')->willReturn(['accountHost' => 'localhost']);
    $credentialService->expects($this->never())->method('save');
    $this->mockProvider->registerClassInstance(CloudUserConnectorService::class, $connector);
    $this->mockProvider->registerClassInstance(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class, $credentialService);
    $this->mockProvider->registerClassInstance(\OCA\CAFEVDB\Database\Connection::class, $this->createStub(\OCA\CAFEVDB\Database\Connection::class));

    $response = $this->controller->setApp('cloudConnectorAccount', $account + ['password' => 'private-password']);
    self::assertSame(400, $response->getStatus());
    self::assertStringNotContainsString('private-password', json_encode($response->getData(), JSON_THROW_ON_ERROR));
  }

  /** @return void */
  public function testWopiAccountKeepsPasswordAndValidatesHost(): void
  {
    $credentials = ['user' => 'restricted', 'accountHost' => '%', 'password' => 'private-password'];
    $service = $this->createMock(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class);
    $service->expects($this->once())->method('load')->with(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::WOPI)->willReturn($credentials);
    $service->expects($this->once())->method('prepare')->with(
      $this->anything(),
      'restricted',
      'private-password',
    )->willReturn($credentials);
    $service->expects($this->once())->method('save')->with(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::WOPI, $credentials);
    $this->mockProvider->registerClassInstance(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class, $service);
    $this->mockProvider->registerClassInstance(\OCA\CAFEVDB\Database\Connection::class, $this->createStub(\OCA\CAFEVDB\Database\Connection::class));

    $response = $this->controller->setApp('wopiConnectorCredentials', ['user' => 'restricted', 'accountHost' => '%', 'password' => '']);
    self::assertSame(200, $response->getStatus());
    self::assertStringNotContainsString('private-password', json_encode($response->getData(), JSON_THROW_ON_ERROR));
  }

  /** @return void */
  public function testWopiAccountRejectsWrongAuthenticatedHost(): void
  {
    restore_error_handler();
    self::enableDeprecations('::grumble');
    $service = $this->createMock(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class);
    $service->method('prepare')->willReturn(['accountHost' => 'localhost']);
    $service->expects($this->never())->method('save');
    $this->mockProvider->registerClassInstance(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class, $service);
    $this->mockProvider->registerClassInstance(\OCA\CAFEVDB\Database\Connection::class, $this->createStub(\OCA\CAFEVDB\Database\Connection::class));

    $response = $this->controller->setApp('wopiConnectorCredentials', ['user' => 'restricted', 'accountHost' => '%', 'password' => 'private-password']);
    self::assertSame(400, $response->getStatus());
    self::assertStringNotContainsString('private-password', json_encode($response->getData(), JSON_THROW_ON_ERROR));
  }

  /** @return void */
  public function testResetClearsSharedCredentialsAndMarksAccountManaged(): void
  {
    $connector = $this->createMock(CloudUserConnectorService::class);
    $connector->expects($this->once())->method('saveConnectorAccount')->with([]);
    $credentialService = $this->createMock(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class);
    $credentialService->expects($this->never())->method('prepare');
    $credentialService->expects($this->once())->method('save')->with(
      \OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::CLOUD,
      [],
    );
    $credentialService->expects($this->once())->method('markCloudManaged');
    $this->mockProvider->registerClassInstance(CloudUserConnectorService::class, $connector);
    $this->mockProvider->registerClassInstance(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class, $credentialService);

    $response = $this->controller->setApp('cloudConnectorAccount', null);
    self::assertSame(200, $response->getStatus());
  }

  /** @return void */
  public function testFailedAccountSaveRestoresCredentialsAndAccount(): void
  {
    restore_error_handler();
    self::enableDeprecations('::grumble');
    $previous = ['user' => 'previous', 'accountHost' => '%'];
    $account = ['user' => 'restricted', 'accountHost' => '%'];
    $credentials = ['user' => 'restricted', 'accountHost' => '%', 'password' => 'private-password'];
    $snapshot = ['cloudConnectorCredentials' => 'encrypted-old', 'cloudConnectorCredentialsManaged' => '1'];
    $connector = $this->createMock(CloudUserConnectorService::class);
    $connector->method('getConnectorAccount')->willReturn($previous);
    $connector->method('normalizeConnectorAccount')->willReturn($account);
    $attempt = 0;
    $connector->expects($this->exactly(2))->method('saveConnectorAccount')
      ->willReturnCallback(static function(array $saved) use (&$attempt, $account, $previous): void {
        if ($attempt++ === 0) {
          self::assertSame($account, $saved);
          throw new RuntimeException('private-password');
        }
        self::assertSame($previous, $saved);
      });
    $credentialService = $this->createMock(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class);
    $credentialService->method('prepare')->willReturn($credentials);
    $credentialService->method('snapshotCloud')->willReturn($snapshot);
    $credentialService->expects($this->once())->method('restoreCloud')->with($snapshot);
    $this->mockProvider->registerClassInstance(CloudUserConnectorService::class, $connector);
    $this->mockProvider->registerClassInstance(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class, $credentialService);
    $this->mockProvider->registerClassInstance(\OCA\CAFEVDB\Database\Connection::class, $this->createStub(\OCA\CAFEVDB\Database\Connection::class));

    $response = $this->controller->setApp('cloudConnectorAccount', $account + ['password' => 'private-password']);
    self::assertSame(400, $response->getStatus());
    self::assertStringNotContainsString('private-password', json_encode($response->getData(), JSON_THROW_ON_ERROR));
  }
}
