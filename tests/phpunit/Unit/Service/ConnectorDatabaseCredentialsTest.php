<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\CAFEVDB\Tests\Unit\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use OCA\CAFEVDB\Database\Connection;
use OCA\CAFEVDB\Exceptions\DatabaseCloudConnectorViewException;
use OCA\CAFEVDB\Service\ConnectorDatabaseCredentials;
use OCA\CAFEVDB\Service\WopiCredentialStore;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection as DbalConnection;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Result;

/** Connector Database Credentials Test. */
#[CoversClass(ConnectorDatabaseCredentials::class)]
#[UsesClass(WopiCredentialStore::class)]
class ConnectorDatabaseCredentialsTest extends TestCase
{
  /** @return void */
  public function testCredentialsAreEncryptedBeforeStorage(): void
  {
    $credentials = ['user' => 'restricted', 'password' => 'test-only', 'accountHost' => '%'];
    $config = $this->createMock(IConfig::class);
    $config->expects($this->once())->method('setAppValue')
      ->with('cafevdb', ConnectorDatabaseCredentials::WOPI, 'encrypted');
    $crypto = $this->createMock(ICrypto::class);
    $crypto->expects($this->once())->method('encrypt')
      ->with(json_encode($credentials))->willReturn('encrypted');

    $service = new ConnectorDatabaseCredentials(
      $config, $crypto, $this->createStub(IDBConnection::class), $this->createStub(IL10N::class), 'cafevdb',
    );
    $service->save(ConnectorDatabaseCredentials::WOPI, $credentials);
  }

  /** @return void */
  public function testCloudSnapshotRestoresEncryptedCredentialsAndAbsentMarker(): void
  {
    $config = $this->createMock(IConfig::class);
    $config->method('getAppValue')->willReturnMap([
      ['cafevdb', ConnectorDatabaseCredentials::CLOUD, '', 'encrypted-old'],
      ['cafevdb', ConnectorDatabaseCredentials::CLOUD_MANAGED, '', ''],
    ]);
    $config->expects($this->once())->method('setAppValue')
      ->with('cafevdb', ConnectorDatabaseCredentials::CLOUD, 'encrypted-old');
    $config->expects($this->once())->method('deleteAppValue')
      ->with('cafevdb', ConnectorDatabaseCredentials::CLOUD_MANAGED);
    $service = new ConnectorDatabaseCredentials(
      $config, $this->createStub(ICrypto::class), $this->createStub(IDBConnection::class), $this->createStub(IL10N::class), 'cafevdb',
    );
    $service->restoreCloud($service->snapshotCloud());
  }

  /** @return void */
  public function testPreparationIdentifiesTheActualAccountAndUsesTheAppServer(): void
  {
    $appConnection = $this->createStub(Connection::class);
    $appConnection->method('getParams')->willReturn([
      'host' => 'mariadb', 'port' => 3307, 'user' => 'orchestra', 'password' => 'orchestra-secret',
    ]);
    $connection = $this->createMock(DbalConnection::class);
    $connection->expects($this->once())->method('fetchOne')
      ->with('SELECT CURRENT_USER()')->willReturn('restricted@%');
    $connection->expects($this->once())->method('close');
    $service = $this->getMockBuilder(ConnectorDatabaseCredentials::class)
      ->setConstructorArgs([
        $this->createStub(IConfig::class), $this->createStub(ICrypto::class),
        $this->createStub(IDBConnection::class), $this->createStub(IL10N::class), 'cafevdb',
      ])
      ->onlyMethods(['open'])->getMock();
    $service->expects($this->once())->method('open')->with([
      'host' => 'mariadb', 'port' => 3307, 'user' => 'restricted', 'password' => 'test-only',
    ])->willReturn($connection);

    self::assertSame(
      ['host' => 'mariadb', 'port' => 3307, 'user' => 'restricted', 'password' => 'test-only', 'accountHost' => '%'],
      $service->prepare($appConnection, 'restricted', 'test-only'),
    );
  }

  /** @return void */
  public function testUnixSocketTakesPrecedenceOverHost(): void
  {
    $appConnection = $this->createStub(Connection::class);
    $appConnection->method('getParams')->willReturn([
      'host' => 'mariadb',
      'port' => 3307,
      'unix_socket' => '/run/mysqld/mysqld.sock',
      'user' => 'orchestra',
    ]);
    $connection = $this->createMock(DbalConnection::class);
    $connection->expects($this->once())->method('fetchOne')
      ->with('SELECT CURRENT_USER()')->willReturn('restricted@localhost');
    $connection->expects($this->once())->method('close');
    $service = $this->getMockBuilder(ConnectorDatabaseCredentials::class)
      ->setConstructorArgs([
        $this->createStub(IConfig::class), $this->createStub(ICrypto::class),
        $this->createStub(IDBConnection::class), $this->createStub(IL10N::class), 'cafevdb',
      ])
      ->onlyMethods(['open'])->getMock();
    $service->expects($this->once())->method('open')->with([
      'unix_socket' => '/run/mysqld/mysqld.sock',
      'user' => 'restricted',
      'password' => 'test-only',
    ])->willReturn($connection);

    self::assertSame(
      [
        'unix_socket' => '/run/mysqld/mysqld.sock',
        'user' => 'restricted',
        'password' => 'test-only',
        'accountHost' => 'localhost',
      ],
      $service->prepare($appConnection, 'restricted', 'test-only'),
    );
  }

  /** @return void */
  public function testConnectionErrorsNeverExposeDriverDetails(): void
  {
    $appConnection = $this->createStub(Connection::class);
    $appConnection->method('getParams')->willReturn(['host' => 'mariadb', 'user' => 'orchestra']);
    $l10n = $this->createStub(IL10N::class);
    $l10n->method('t')->willReturn('Connector connection failed.');
    $service = $this->getMockBuilder(ConnectorDatabaseCredentials::class)
      ->setConstructorArgs([
        $this->createStub(IConfig::class), $this->createStub(ICrypto::class),
        $this->createStub(IDBConnection::class), $l10n, 'cafevdb',
      ])
      ->onlyMethods(['open'])->getMock();
    $service->expects($this->once())->method('open')->willThrowException(new RuntimeException('driver included test-only password'));

    try {
      $service->prepare($appConnection, 'restricted', 'test-only');
      self::fail('Expected connection failure.');
    } catch (DatabaseCloudConnectorViewException $exception) {
      self::assertSame('Connector connection failed.', $exception->getMessage());
      self::assertNull($exception->getPrevious());
    }
  }

  /** @return void */
  public function testViewAccessIsCheckedOnTheDedicatedConnection(): void
  {
    $credentials = ['user' => 'restricted', 'password' => 'test-only'];
    $result = $this->createMock(Result::class);
    $result->expects($this->once())->method('free');
    $connection = $this->createMock(DbalConnection::class);
    $connection->expects($this->once())->method('executeQuery')
      ->with('SELECT 1 FROM `view``db`.`RestrictedView` LIMIT 1')->willReturn($result);
    $connection->expects($this->once())->method('close');
    $service = $this->getMockBuilder(ConnectorDatabaseCredentials::class)
      ->setConstructorArgs([
        $this->createStub(IConfig::class), $this->createStub(ICrypto::class),
        $this->createStub(IDBConnection::class), $this->createStub(IL10N::class), 'cafevdb',
      ])
      ->onlyMethods(['open'])->getMock();
    $service->expects($this->once())->method('open')->with($credentials, 'view`db')->willReturn($connection);

    $service->checkViews($credentials, 'view`db', ['RestrictedView']);
  }
}
