<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2025, 2026 Claus-Justus Heine <himself@claus-justus-heine.de>
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

namespace OCA\CAFEVDB\Tests\Unit\Service;

use ArrayObject;
use ErrorException;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Container\ContainerInterface;

use OCA\RotDrop\Tests\DatabaseProvider;
use OCA\RotDrop\Tests\EnumDatabasePurpose;

use OCA\CAFEVDB\Constants;
use OCA\CAFEVDB\Service\CloudUserConnectorService;
use OCA\CAFEVDB\Service\ConnectorDatabaseCredentials;
use OCA\CAFEVDB\Service\EncryptionService;
use OCA\CAFEVDB\Settings\ConfigConstants;
use OCA\CAFEVDB\Tests\MockProvider;
use OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations\SetupMigrationTrait;
use OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Service\RequestService;

/**
 * Test aspects of the CloudUserConnectorService.
 *
 * @todo Testing the DB access requires excessive mocking or a real DB. The
 * latter has not yet been fully set up but that will be the way to go in the
 * future.
 */
#[Attributes\CoversClass(CloudUserConnectorService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\AppInfo\Application::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\TimeFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\UndoableRunQueue::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\Transliterator::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\Util::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\HaliteCryptoFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\HaliteSymmetricStreamCryptor::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\SealCryptor::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\SealService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Connection::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\DBAL\Logging\CloudLogger::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\DeprecationLogger::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\Migrations\AbstractMigration::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\Migrations\DependencyFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DoctrineMigrationsVersion::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\Instrument::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\InstrumentFamily::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\LogEntry::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\DoctrineMigrationsListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\GedmoLoggableListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\GedmoSluggableListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\GedmoTranslatableListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\Transformable\Encryption::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Mapping\ClassMetadataDecorator::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Mapping\ReservedWordQuoteStrategy::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Repositories\EntityRepository::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Repositories\ProjectsRepository::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Repositories\RepositoryFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\EntityManager::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Events\EncryptionServiceBound::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Events\EntityManagerBoundEvent::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\CalendarObjectFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\DTO\CalendarEventData::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version19700101000001::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version19700101000002::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version19700101000003::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260108084800::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260108115432::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260130130553::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260131090857::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260206193722::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260207000624::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260819094146::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260819094422::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260819105948::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20261009150233::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20261031220000::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20261101120000::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20261118191044::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\WopiCredentialsViewService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\WopiCredentialStore::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\EmailTemplateDefaultsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\CalDavService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ConfigService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\DoctrineMigrationsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\EncryptionService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\EventsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\L10N\BiDirectionalL10N::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\L10N\L10NFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\MailingListsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\MusicianService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ProjectParticipantFieldsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ProjectService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\Registration::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\VCalendarService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\AbstractDecimalRationalType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\ArrayType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\DecimalRationalMonetaryType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\UuidType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Service\ExecutableFinder::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Service\RequestService::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\ArrayTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\TranslatableTrait::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\ORM\EnumOrderByOptions::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\ORM\FindLikeTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\BackedEnumTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\AppConfigTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\ConfigTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\EntityManagerTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\UserPreferencesTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\CamelCaseToDashesTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\TranslatableEnumTrait::class)]
class CloudUserConnectorServiceTest extends TestCase
{
  use SetupMigrationTrait;
  use UserSqlRequestsTrait;

  private string $appName;

  private IConfig $cloudConfig;

  private CloudUserConnectorService $cloudUserConnectorService;

  private EncryptionService $encryptionService;

  private IAppManager $appManager;

  private MockProvider $mockProvider;

  private DatabaseProvider $databaseProvider;

  /** @return void */
  public function setup(): void
  {
    /** @var MockProvider $mockProvider */
    $this->mockProvider = $this->mockProvider ?? MockProvider::create($this);

    $this->databaseProvider = \OCP\Server::get(DatabaseProvider::class);

    if (!$this->databaseProvider->getDatabaseConfig()) {
      $this->databaseProvider->startServer();
    }

    // Instantiate the entity-manager, this is needed in order to use our test-database.
    $this->getEntityManager();

    $this->appManager = $this->getMockBuilder(IAppManager::class)
      ->getMock();
    $this->appManager
      ->expects($this->never())
      ->method('getAppInfo');

    $this->appName = $this->mockProvider->appName;

    $this->cloudConfig = $this->mockProvider->getCloudConfig();

    $this->encryptionService = $this->mockProvider->getEncryptionService();

    $appContainer = $this->mockProvider->getAppContainer();

    $httpClientService = $this->getUserSqlEndPointStub();
    $this->mockProvider->registerClassInstance(IClientService::class, $httpClientService, global: true);

    $this->cloudUserConnectorService = new CloudUserConnectorService(
      appContainer: $appContainer,
      appManager: $this->appManager,
      appName: $this->appName,
      cloudConfig: $this->cloudConfig,
      encryptionService: $this->mockProvider->getEncryptionService(),
      l: $this->mockProvider->getL10N(),
      logger: $this->mockProvider->getLoggerInterface(),
      requestService: $appContainer->get(RequestService::class),
    );
  }

  /** @return void */
  public function testConstruction(): void
  {
    $this->appManager->expects($this->never())->method('isInstalled');
    // nothing
  }

  public function testGrantToRevokeStatementHandlesFieldAndFunctionGrants(): void
  {
    $method = new ReflectionMethod(CloudUserConnectorService::class, 'grantToRevokeStatement');
    $invoke = fn(string $sql): ?string => $method->invoke(
      $this->cloudUserConnectorService,
      $sql,
    );

    self::assertSame(
      "REVOKE UPDATE (password_hash) ON `db`.`View` FROM 'connector'@'%'",
      $invoke("GRANT UPDATE (password_hash) ON `db`.`View` TO 'connector'@'%'"),
    );
    self::assertSame(
      'REVOKE EXECUTE ON FUNCTION `db`.`Fn` FROM `connector`',
      $invoke('GRANT EXECUTE ON FUNCTION `db`.`Fn` TO `connector`'),
    );
  }

  /** @return void */
  public function testCheckRequirements(): void
  {
    $method = $this->appManager->expects($this->atLeastOnce())->method('isInstalled');

    $result = $this->cloudUserConnectorService->checkRequirements(
      dataBaseName: null,
    );
    $this->assertEquals(CloudUserConnectorService::REQUIREMENTS_MISSING, $result['status']);
    $this->assertNotEmpty($result['hints']);

    $method->with(CloudUserConnectorService::CLOUD_USER_BACKEND)->willReturn(true);
    $result = $this->cloudUserConnectorService->checkRequirements(
      dataBaseName: null,
    );
    $this->assertEquals(CloudUserConnectorService::REQUIREMENTS_OK, $result['status']);
    $this->assertEmpty($result['hints']);

    $restrictions = ['NOT EMPTY'];
    $this->appManager
      ->expects($this->exactly(3))
      ->method('getAppRestriction')
      ->with(CloudUserConnectorService::CLOUD_USER_BACKEND)
      ->willReturnCallback(function() use (&$restrictions) {
        return $restrictions;
      });
    $result = $this->cloudUserConnectorService->checkRequirements(
      dataBaseName: null,
    );
    $this->assertEquals(CloudUserConnectorService::REQUIREMENTS_MISSING, $result['status']);
    $this->assertNotEmpty($result['hints']);

    $restrictions = [];
    $result = $this->cloudUserConnectorService->checkRequirements(
      dataBaseName: 'blub',
    );
    $this->assertNotEmpty($result['hints']);

    $result = $this->cloudUserConnectorService->checkRequirements(
      dataBaseName: $this->appName,
    );
    $this->assertEquals(CloudUserConnectorService::REQUIREMENTS_OK, $result['status']);
    $this->assertEmpty($result['hints']);
  }

  /** @return void */
  public function testWriteUserSqlConfig(): void
  {
    $this->encryptionService->setConfigValue(ConfigConstants::ORCHESTRA_NAME_KEY, 'Orchester');
    $this->cloudUserConnectorService->writeUserSqlConfig(
      dataBaseName: 'something',
      delete: false,
    );
    $this->assertEquals(
      'Orchester Personen',
      $this->cloudConfig->getAppValue(
        $this->appName,
        CloudUserConnectorService::CLOUD_USER_BACKEND . ':' . 'opt.default_group',
      ),
    );
    $this->assertTrue($this->cloudUserConnectorService->haveCloudUserBackendConfig());
    $this->cloudUserConnectorService->writeUserSqlConfig(
      dataBaseName: 'something',
      delete: true,
    );
    $this->assertEquals(
      null,
      $this->cloudConfig->getAppValue(
        $this->appName,
        CloudUserConnectorService::CLOUD_USER_BACKEND . ':' . 'opt.default_group',
      ),
    );
    $this->assertFalse($this->cloudUserConnectorService->haveCloudUserBackendConfig());
  }

  /** @return void */
  public function testConfigureCloudUserBackend(): void
  {
    $values = [];
    $config = $this->createStub(IConfig::class);
    $config->method('getAppKeys')->willReturn(['user_sql:db.database', 'user_sql:db.driver']);
    $config->method('getAppValue')->willReturnCallback(
      static fn(string $app, string $key, mixed $default = '') => match ($key) {
        'user_sql:db.database' => 'views',
        'user_sql:db.driver' => 'mysql',
        default => $default,
      },
    );
    $config->method('getSystemValue')->willReturnCallback(
      static fn(string $key, mixed $default = '') => match ($key) {
        'dbhost' => 'cloud-db:3307',
        'dbuser' => 'cloud-user',
        'dbpassword' => 'cloud-secret',
        default => $default,
      },
    );
    $config->method('setAppValue')->willReturnCallback(
      static function(string $app, string $key, mixed $value) use (&$values): void {
        $values[$app . ':' . $key] = $value;
      },
    );
    $service = $this->getMockBuilder(CloudUserConnectorService::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getConnectorAccount', 'userSqlProperties', 'requireUserSqlSettingsAccess'])
      ->getMock();
    $service->method('getConnectorAccount')->willReturn([]);
    $service->method('userSqlProperties')->willReturn(new ArrayObject());
    foreach ([
      'cloudConfig' => $config,
      'appName' => 'cafevdb',
      'l' => $this->mockProvider->getL10N(),
      'logger' => $this->mockProvider->getLoggerInterface(),
      'requestService' => $this->mockProvider->getAppContainer()->get(RequestService::class),
    ] as $name => $value) {
      (new ReflectionProperty(CloudUserConnectorService::class, $name))->setValue($service, $value);
    }
    self::assertSame([], $service->configureCloudUserBackend());
    self::assertSame('cloud-db', $values['user_sql:db.hostname']);
    self::assertSame('cloud-secret', $values['user_sql:db.password']);
    self::assertSame('views', $values['user_sql:db.database']);
  }

  /** @return void */
  public function testWriteUserSqlConfigWithoutOrchestraName(): void
  {
    $keys = [ConfigConstants::ORCHESTRA_NAME_KEY, ConfigConstants::MUSICIANS_ADDRESS_BOOK_KEY];
    $values = [];
    foreach ($keys as $key) {
      $values[$key] = $this->encryptionService->getAppValue($key);
      $this->encryptionService->deleteAppValue($key);
    }
    try {
      $this->assertNull($this->encryptionService->getConfigValue(ConfigConstants::ORCHESTRA_NAME_KEY));
      $this->assertNull($this->encryptionService->getConfigValue(ConfigConstants::MUSICIANS_ADDRESS_BOOK_KEY));
      set_error_handler(static function(int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
      }, E_DEPRECATED);
      try {
        $this->cloudUserConnectorService->writeUserSqlConfig(dataBaseName: 'something');
      } finally {
        restore_error_handler();
      }
      $this->assertSame(
        ' ' . $this->mockProvider->getL10N()->t('Musicians'),
        $this->cloudConfig->getAppValue($this->appName, CloudUserConnectorService::CLOUD_USER_BACKEND . ':opt.default_group'),
      );
    } finally {
      $this->cloudUserConnectorService->writeUserSqlConfig(dataBaseName: 'something', delete: true);
      foreach ($values as $key => $value) {
        if ($value === null) {
          $this->encryptionService->deleteAppValue($key);
        } else {
          $this->encryptionService->setAppValue($key, $value);
        }
      }
    }
  }

  /** @return void */
  public function testProjectGroupId(): void
  {
    $number = 47;
    $groupId = CloudUserConnectorService::getProjectGroupId($number);
    $this->assertEquals($this->appName . '_' .  $number, $groupId);
  }

  /** @return void */
  public function testConnectorAccountContainsNoCredentials(): void
  {
    $account = $this->cloudUserConnectorService->normalizeConnectorAccount(' nextcloud ', ' % ');
    self::assertSame(['user' => 'nextcloud', 'accountHost' => '%'], $account);
    $this->cloudUserConnectorService->saveConnectorAccount($account);
    try {
      self::assertSame($account, $this->cloudUserConnectorService->getConnectorAccount());
      self::assertSame(
        $account,
        json_decode($this->cloudConfig->getAppValue($this->appName, CloudUserConnectorService::CONNECTOR_ACCOUNT_CONFIG), true),
      );
    } finally {
      $this->cloudUserConnectorService->saveConnectorAccount([]);
    }
    self::assertSame([], $this->cloudUserConnectorService->getConnectorAccount());
  }

  /** @return void */
  public function testGenerateCloudUserViews(): void
  {
    $this->applyMigrations('latest');
    $cloudConnectorDatabase = $this->databaseProvider->dataBaseName(EnumDatabasePurpose::CLOUD_CONNECTOR);
    $dbConfig = $this->databaseProvider->getDatabaseConfig();
    $this->cloudConfig->setSystemValue('dbhost', $dbConfig->databaseServer);
    $this->cloudConfig->setSystemValue('dbuser', DatabaseProvider::CLOUD_DB_USER);
    $this->cloudConfig->setSystemValue('dbtype', 'mysql');

    $this->cloudUserConnectorService->updateUserSqlViews($cloudConnectorDatabase);
    $this->cloudUserConnectorService->updateMusicianPersonalizedViews($cloudConnectorDatabase);
    $this->assertPersonalizedStorageViews($cloudConnectorDatabase);
    $this->cloudUserConnectorService->removeMusicianPersonalizedViews($cloudConnectorDatabase);
    $this->cloudUserConnectorService->removeUserSqlViews($cloudConnectorDatabase);

    $this->unapplyMigrations();
  }

  /** @return void */
  public function testDedicatedAccountAccessesViewsWhenNextcloudUsesPostgres(): void
  {
    $this->applyMigrations('latest');
    $database = $this->databaseProvider->dataBaseName(EnumDatabasePurpose::CLOUD_CONNECTOR);
    $dbConfig = $this->databaseProvider->getDatabaseConfig();
    $credentialService = $this->mockProvider->getAppContainer()->get(\OCA\CAFEVDB\Service\ConnectorDatabaseCredentials::class);
    $credentials = $credentialService->prepare(
      $this->getEntityManager()->getConnection(),
      DatabaseProvider::CLOUD_DB_USER,
      $dbConfig->databasePassword,
    );
    $account = ['user' => $credentials['user'], 'accountHost' => $credentials['accountHost']];
    $oldType = $this->cloudConfig->getSystemValue('dbtype', 'sqlite');
    try {
      $this->cloudConfig->setSystemValue('dbtype', 'pgsql');
      $this->cloudUserConnectorService->saveConnectorAccount($account);
      self::assertSame($account, $this->cloudUserConnectorService->getConnectorAccount());
      $this->cloudUserConnectorService->updateUserSqlViews($database, checkAccess: true);
      $this->cloudUserConnectorService->updateMusicianPersonalizedViews($database, checkAccess: true);
      $credentialService->checkViews($credentials, $database, ['NextcloudUserView', 'PersonalizedMusiciansView']);
    } finally {
      $this->cloudUserConnectorService->saveConnectorAccount([]);
      $this->cloudConfig->setSystemValue('dbtype', $oldType);
      $this->unapplyMigrations();
    }
  }

  /** @return void */
  public function testCustomAccountDoesNotOverwriteUserSqlConnectionSettings(): void
  {
    $account = ['user' => DatabaseProvider::CLOUD_DB_USER, 'accountHost' => '%'];
    $connectionSettings = [
      'db.hostname' => 'members-db',
      'db.username' => 'members-user',
      'db.password' => 'test-only',
      'db.database' => 'members-views',
      'db.driver' => 'mysql',
      'db.ssl_ca' => 'ca.pem',
      'opt.safe_store' => '1',
    ];
    $properties = new ArrayObject($connectionSettings);
    $container = $this->mockProvider->getAppContainer();
    $service = $this->getMockBuilder(CloudUserConnectorService::class)
      ->setConstructorArgs([
        $this->encryptionService, $this->appManager, $this->cloudConfig, $this->mockProvider->getL10N(),
        $container->get(RequestService::class), $this->appName, $container, $this->mockProvider->getLoggerInterface(),
      ])
      ->onlyMethods(['userSqlProperties', 'requireUserSqlSettingsAccess'])->getMock();
    $service->expects($this->exactly(2))->method('userSqlProperties')->willReturn($properties);
    $service->expects($this->exactly(2))->method('requireUserSqlSettingsAccess');
    $service->saveConnectorAccount($account);
    try {
      $service->writeUserSqlConfig('configured-views');
      $service->configureCloudUserBackend();
      foreach ($connectionSettings as $key => $expected) {
        self::assertSame($expected, $properties[$key]);
      }
      self::assertSame('NextcloudUserView', $properties['db.table.user']);
      $service->configureCloudUserBackend(erase: true);
      foreach ($connectionSettings as $key => $expected) {
        self::assertSame($expected, $properties[$key]);
      }
      self::assertFalse(isset($properties['db.table.user']));
    } finally {
      $service->saveConnectorAccount([]);
    }
  }

  #[Attributes\DataProvider('userSqlEndpoints')]
  public function testDedicatedCredentialsConfigureUserSqlWithoutChangingSafeStore(
    array $credentials,
    string $cloudHost,
    string $safeStore,
    ?string $expectedHost,
  ): void {
    $account = ['user' => 'restricted', 'accountHost' => '%'];
    $credentials += $account + ['password' => 'private-password'];
    $appValues = [];
    $systemValues = [];
    $config = $this->createStub(IConfig::class);
    $config->method('getAppKeys')->willReturn(['user_sql:db.database', 'user_sql:db.table.user']);
    $config->method('getAppValue')->willReturnCallback(
      static fn(string $app, string $key, mixed $default = '') => match ([$app, $key]) {
        ['cafevdb', 'user_sql:db.database'] => 'views',
        ['cafevdb', 'user_sql:db.table.user'] => 'NextcloudUserView',
        ['user_sql', 'opt.safe_store'] => $safeStore,
        default => $default,
      },
    );
    $config->method('getSystemValue')->willReturn($cloudHost);
    $config->method('setAppValue')->willReturnCallback(
      static function(string $app, string $key, mixed $value) use (&$appValues): void {
        $appValues[$app . ':' . $key] = $value;
      },
    );
    $config->method('setSystemValue')->willReturnCallback(
      static function(string $key, mixed $value) use (&$systemValues): void {
        $systemValues[$key] = $value;
      },
    );
    $credentialService = $this->createStub(ConnectorDatabaseCredentials::class);
    $credentialService->method('load')->willReturn($credentials);
    $container = $this->createStub(ContainerInterface::class);
    $container->method('get')->willReturn($credentialService);
    $properties = new ArrayObject(['opt.safe_store' => $safeStore, 'db.ssl_ca' => 'ca.pem']);
    $service = $this->getMockBuilder(CloudUserConnectorService::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getConnectorAccount', 'userSqlProperties', 'requireUserSqlSettingsAccess'])
      ->getMock();
    $service->method('getConnectorAccount')->willReturn($account);
    $service->method('userSqlProperties')->willReturn($properties);
    foreach ([
      'cloudConfig' => $config,
      'appName' => 'cafevdb',
      'appContainer' => $container,
      'l' => $this->mockProvider->getL10N(),
      'logger' => $this->mockProvider->getLoggerInterface(),
      'requestService' => $this->mockProvider->getAppContainer()->get(RequestService::class),
    ] as $name => $value) {
      (new ReflectionProperty(CloudUserConnectorService::class, $name))->setValue($service, $value);
    }

    $warnings = $service->configureCloudUserBackend();
    self::assertSame('NextcloudUserView', $properties['db.table.user']);
    self::assertSame('ca.pem', $properties['db.ssl_ca']);
    self::assertSame($safeStore, $properties['opt.safe_store']);
    if ($expectedHost === null) {
      self::assertNotEmpty($warnings);
      self::assertSame([], $appValues);
      self::assertSame([], $systemValues);
      return;
    }
    self::assertSame([], $warnings);
    $values = $safeStore === '1' ? $systemValues : $appValues;
    $prefix = $safeStore === '1' ? 'user_sql.' : 'user_sql:';
    self::assertSame($expectedHost, $values[$prefix . 'db.hostname']);
    self::assertSame('views', $values[$prefix . 'db.database']);
    self::assertSame('restricted', $values[$prefix . 'db.username']);
    self::assertSame('private-password', $values[$prefix . 'db.password']);
    self::assertSame('mysql', $appValues['user_sql:db.driver']);
  }

  /** @return iterable */
  public static function userSqlEndpoints(): iterable
  {
    yield 'app store' => [['host' => 'mariadb'], 'cloud-db', '0', 'mariadb'];
    yield 'system store with matching port' => [['host' => 'mariadb', 'port' => 3307], 'cloud-db:3307', '1', 'mariadb'];
    yield 'different port' => [['host' => 'mariadb', 'port' => 3307], 'cloud-db:3306', '0', null];
    yield 'matching socket' => [['unix_socket' => '/tmp/views.sock'], 'localhost:/tmp/views.sock', '1', 'localhost'];
    yield 'different socket' => [['unix_socket' => '/tmp/views.sock'], 'localhost:/tmp/other.sock', '0', null];
    yield 'tcp with inherited socket' => [['host' => 'mariadb'], 'localhost:/tmp/views.sock', '0', null];
  }

  /**
   * @param string $cloudConnectorDatabase
   *
   * @return void
   */
  private function assertPersonalizedStorageViews(string $cloudConnectorDatabase): void
  {
    $connection = $this->getEntityManager()->getConnection();
    $connection->beginTransaction();
    try {
      $connection->executeStatement(
        'INSERT INTO Musicians (id, first_name, sur_name, uuid) VALUES
          (1, "First", "Owner", UNHEX("00000000000000000000000000000001")),
          (2, "Second", "Owner", UNHEX("00000000000000000000000000000002")),
          (3, "Third", "Owner", UNHEX("00000000000000000000000000000003"))',
      );
      $connection->executeStatement(
        'INSERT INTO MusicianRowAccessTokens (musician_id, user_id, access_token_hash) VALUES
          (1, "first-owner", "first-token"), (2, "second-owner", "second-token"),
          (3, "third-owner", "third-token")',
      );
      $connection->executeStatement(
        'INSERT INTO Files (id, mime_type, type) VALUES
          (1, "text/plain", "encrypted"), (2, "text/plain", "encrypted"),
          (3, "text/plain", "encrypted")',
      );
      $connection->executeStatement(
        'INSERT INTO EncryptedFileOwners (musician_id, encrypted_file_id) VALUES
          (1, 1), (1, 2), (2, 3)',
      );
      $connection->executeStatement(
        'INSERT INTO DatabaseStorageDirEntries (id, name, type, parent_id, file_id) VALUES
          (1, "shared-root", "folder", NULL, NULL),
          (2, "parent", "folder", 1, NULL),
          (3, "nested-parent", "folder", 2, NULL),
          (4, "first-file", "file", 3, 1),
          (5, "second-file", "file", 3, 2),
          (6, "other-file", "file", 3, 3),
          (7, "empty-sibling", "folder", 2, NULL),
          (10, "other-root", "folder", NULL, NULL),
          (11, "other-parent", "folder", 10, NULL),
          (12, "other-file", "file", 11, 3),
          (20, "empty-root", "folder", NULL, NULL),
          (30, "authorized-root", "folder", NULL, NULL),
          (31, "second-file", "file", 30, 2)',
      );
      $connection->executeStatement(
        'INSERT INTO DatabaseStorages (id, storage_id, root_id) VALUES
          (1, "shared", 1), (2, "other", 10), (3, "empty", 20), (4, "authorized", 30)',
      );

      foreach ([
        ['first-owner', 'first-token', [1, 2, 3, 4, 5, 30, 31], [1, 4]],
        ['second-owner', 'second-token', [1, 2, 3, 6, 10, 11, 12], [1, 2]],
        ['third-owner', 'third-token', [], []],
        ['first-owner', 'wrong-token', [], []],
        [null, null, [], []],
      ] as [$userId, $token, $entryIds, $storageIds]) {
        $connection->executeStatement('SET @' . Constants::SQL_CLOUD_USER_ID . ' = ?', [$userId]);
        $connection->executeStatement('SET @' . Constants::SQL_ROW_ACCESS_TOKEN . ' = ?', [$token]);
        foreach ([
          'DatabaseStorageDirEntries' => $entryIds,
          'DatabaseStorages' => $storageIds,
        ] as $table => $expectedIds) {
          $actualIds = $connection->executeQuery(
            'SELECT id FROM ' . $cloudConnectorDatabase . '.Personalized' . $table . 'View ORDER BY id',
          )->fetchFirstColumn();
          $this->assertSame($expectedIds, array_map('intval', $actualIds));
        }
      }
    } finally {
      $connection->executeStatement('SET @' . Constants::SQL_CLOUD_USER_ID . ' = NULL');
      $connection->executeStatement('SET @' . Constants::SQL_ROW_ACCESS_TOKEN . ' = NULL');
      $connection->rollBack();
    }
  }
}
