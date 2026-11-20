<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\CAFEVDB\Tests\Unit\Service;

use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use OCA\CAFEVDB\Database\Connection;
use OCA\CAFEVDB\Exceptions\DatabaseCloudConnectorViewException;
use OCA\CAFEVDB\Service\ConnectorDatabaseCredentials;
use OCA\CAFEVDB\Service\EncryptionService;
use OCA\CAFEVDB\Service\WopiCredentialStore;
use OCA\CAFEVDB\Service\WopiCredentialsViewService;
use OCA\CAFEVDB\Settings\ConfigConstants;

#[CoversClass(WopiCredentialsViewService::class)]
#[UsesClass(WopiCredentialStore::class)]
#[UsesClass(ConnectorDatabaseCredentials::class)]
class WopiCredentialsViewServiceTest extends TestCase
{
  public function testDatabaseSwitchUpdatesLocatorBeforeDroppingPreviousView(): void
  {
    $events = [];
    $service = $this->service('previous', $events);

    $service->updateWopiCredentialsView('next');

    self::assertCount(7, $events);
    self::assertSame(
      'cafevdb|wopiConnectorDatabase|next',
      $events[4],
    );
    self::assertSame(
      'DROP VIEW IF EXISTS `previous`.`WopiCredentialsView`',
      $events[5],
    );
    self::assertSame(
      'DROP FUNCTION IF EXISTS `previous`.`WOPI_TOKEN_HASH`',
      $events[6],
    );
  }

  public function testGrantFailureLeavesPreviousLocatorAndViewUntouched(): void
  {
    $events = [];
    $service = $this->service('previous', $events, failOnSelectGrant: true);

    try {
      $service->updateWopiCredentialsView('next');
      self::fail('Expected view provisioning to fail.');
    } catch (DatabaseCloudConnectorViewException) {
      self::assertCount(4, $events);
      self::assertStringStartsWith('GRANT SELECT ON', $events[3]);
    }
  }

  public function testDisablingRemovesTheRecordedViewAndLocator(): void
  {
    $events = [];
    $service = $this->service('previous', $events);

    $service->removeWopiCredentialsView();

    self::assertSame(
      [
        'DROP VIEW IF EXISTS `previous`.`WopiCredentialsView`',
        'DROP FUNCTION IF EXISTS `previous`.`WOPI_TOKEN_HASH`',
        'clear-locator',
      ],
      $events,
    );
  }

  public function testDedicatedCredentialsWorkWithPostgresAndAreCheckedBeforeSaving(): void
  {
    $events = [];
    $credentials = ['user' => 'connector', 'accountHost' => '%', 'password' => 'test-only'];
    $store = $this->createMock(ConnectorDatabaseCredentials::class);
    $store->expects($this->once())->method('checkViews')
      ->with($credentials, 'next', [WopiCredentialStore::VIEW_NAME])
      ->willReturnCallback(static function() use (&$events): void {
        $events[] = 'validated';
      });
    $store->expects($this->once())->method('save')
      ->with(ConnectorDatabaseCredentials::WOPI, $credentials)
      ->willReturnCallback(static function() use (&$events): void {
        $events[] = 'saved';
      });
    $service = $this->service('next', $events, credentialStore: $store, dbType: 'pgsql');

    $service->updateWopiCredentialsView('next', $credentials, checkAccess: true);

    self::assertSame("GRANT SELECT ON `next`.`WopiCredentialsView` TO 'connector'@'%'", $events[3]);
    self::assertSame(['validated', 'saved', 'cafevdb|wopiConnectorDatabase|next'], array_slice($events, 4));
  }

  public function testInaccessibleViewDoesNotReplaceTheActiveLocator(): void
  {
    $events = [];
    $store = $this->createStub(ConnectorDatabaseCredentials::class);
    $store->method('checkViews')->willThrowException(new DatabaseCloudConnectorViewException('Access denied.'));
    $service = $this->service('previous', $events, credentialStore: $store);

    try {
      $service->updateWopiCredentialsView('next', checkAccess: true);
      self::fail('Expected view validation to fail.');
    } catch (DatabaseCloudConnectorViewException) {
      self::assertCount(4, $events);
      self::assertStringStartsWith('GRANT SELECT ON', $events[3]);
    }
  }

  private function service(
    string $previousDatabase,
    array &$events,
    bool $failOnSelectGrant = false,
    ?ConnectorDatabaseCredentials $credentialStore = null,
    string $dbType = 'mysql',
  ): WopiCredentialsViewService {
    $connection = $this->createStub(Connection::class);
    $connection->method('quote')->willReturnCallback(static fn(string $value): string => "'" . $value . "'");
    $connection->method('executeStatement')->willReturnCallback(
      static function(string $sql) use (&$events, $failOnSelectGrant): int {
        $events[] = $sql;
        if ($failOnSelectGrant && str_starts_with($sql, 'GRANT SELECT')) {
          throw new RuntimeException('Grant denied.');
        }
        return 0;
      },
    );

    $cloudConfig = $this->createMock(IConfig::class);
    $cloudConfig->method('getSystemValue')->willReturnMap([
      ['dbhost', '', 'database-host'],
      ['dbtype', 'sqlite', $dbType],
      [ConfigConstants::APP_DB_USER, '', 'nextcloud'],
    ]);
    $cloudConfig->expects($this->once())->method('getAppValue')
      ->with('cafevdb', WopiCredentialStore::CONNECTOR_DATABASE_CONFIG, '')
      ->willReturn($previousDatabase);
    $cloudConfig->method('setAppValue')->willReturnCallback(
      static function(string $app, string $key, string $value) use (&$events): void {
        $events[] = implode('|', [$app, $key, $value]);
      },
    );
    $cloudConfig->method('deleteAppValue')->willReturnCallback(
      static function() use (&$events): void {
        $events[] = 'clear-locator';
      },
    );

    $encryptionService = $this->createStub(EncryptionService::class);
    $encryptionService->method('getConfigValue')->willReturnMap([
      [ConfigConstants::APP_DB_SERVER, null, false, 'database-host'],
      [ConfigConstants::APP_DB_NAME, null, false, 'app'],
    ]);
    return new WopiCredentialsViewService(
      $encryptionService,
      $cloudConfig,
      $this->createStub(IL10N::class),
      'cafevdb',
      $connection,
      $credentialStore ?? $this->createStub(ConnectorDatabaseCredentials::class),
    );
  }
}
