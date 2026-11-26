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
use OCP\DB\IResult;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

use OCA\CAFEVDB\Exceptions\DatabaseCloudConnectorViewException;
use OCA\CAFEVDB\Service\ConnectorDatabaseCredentials;
use OCA\CAFEVDB\Service\WopiCredentialStore;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection as DbalConnection;

/** Wopi Credential Store Test. */
#[CoversClass(WopiCredentialStore::class)]
#[UsesClass(ConnectorDatabaseCredentials::class)]
class WopiCredentialStoreTest extends TestCase
{
  /** @return void */
  public function testRecoveryFallsBackToNextcloudConnectionAndClearsTheToken(): void
  {
    $expiry = time() + 300;
    $result = $this->createMock(IResult::class);
    $result->expects($this->once())->method('fetchOne')->willReturn(false);
    $result->expects($this->once())->method('closeCursor');
    $statements = [];
    $cloudConnection = $this->createMock(IDBConnection::class);
    $cloudConnection->expects($this->exactly(2))->method('executeStatement')
      ->willReturnCallback(static function(string $sql, array $params = []) use (&$statements): int {
        $statements[] = [$sql, $params];
        return 0;
      });
    $cloudConnection->expects($this->once())->method('executeQuery')
      ->with('SELECT encrypted_key FROM `connector`.`WopiCredentialsView` WHERE expires = ?', [$expiry])
      ->willReturn($result);
    $connector = $this->createMock(ConnectorDatabaseCredentials::class);
    $connector->expects($this->once())->method('load')->willReturn([]);
    $connector->expects($this->never())->method('open');
    $config = $this->createStub(IConfig::class);
    $config->method('getAppValue')->willReturn('connector');
    $store = new WopiCredentialStore(
      $config, 'cafevdb', $cloudConnection,
      $this->createStub(ContainerInterface::class), $this->createStub(ICrypto::class), $connector,
    );

    self::assertNull($store->recover('token', 'member', $expiry));
    self::assertSame([
      ['SET @WOPI_TOKEN_HASH = ?', [hash('sha256', 'token')]],
      ['SET @WOPI_TOKEN_HASH = NULL', []],
    ], $statements);
  }

  /** @return void */
  public function testRecoveryUsesDedicatedConnectionAndClosesIt(): void
  {
    $expiry = time() + 300;
    $credentials = ['user' => 'restricted', 'password' => 'test-only'];
    $connection = $this->createMock(DbalConnection::class);
    $connection->expects($this->once())->method('executeStatement')
      ->with('SET @WOPI_TOKEN_HASH = ?', [hash('sha256', 'token')]);
    $connection->expects($this->once())->method('fetchOne')
      ->with('SELECT encrypted_key FROM `connector`.`WopiCredentialsView` WHERE expires = ?', [$expiry])
      ->willReturn('encrypted-key');
    $connection->expects($this->once())->method('close');
    $connector = $this->createMock(ConnectorDatabaseCredentials::class);
    $connector->expects($this->once())->method('load')->with(ConnectorDatabaseCredentials::WOPI)->willReturn($credentials);
    $connector->expects($this->once())->method('open')->with($credentials, 'connector')->willReturn($connection);
    $crypto = $this->createMock(ICrypto::class);
    $crypto->expects($this->once())->method('decrypt')
      ->with('encrypted-key', hash_hkdf('sha256', 'token', 32, 'cafevdb/wopi-credential/v1'))
      ->willReturn(json_encode(['userId' => 'member', 'expires' => $expiry, 'key' => base64_encode('app-key')]));
    $config = $this->createStub(IConfig::class);
    $config->method('getAppValue')->willReturn('connector');
    $store = new WopiCredentialStore(
      $config, 'cafevdb', $this->createStub(IDBConnection::class),
      $this->createStub(ContainerInterface::class), $crypto, $connector,
    );

    self::assertSame('app-key', $store->recover('token', 'member', $expiry));
  }

  /** @return void */
  public function testRecoveryClosesConnectionAndSanitizesDatabaseErrors(): void
  {
    $connection = $this->createMock(DbalConnection::class);
    $connection->expects($this->once())->method('executeStatement')
      ->willThrowException(new RuntimeException('test-only password'));
    $connection->expects($this->once())->method('close');
    $connector = $this->createMock(ConnectorDatabaseCredentials::class);
    $connector->expects($this->once())->method('load')->willReturn(['user' => 'restricted', 'password' => 'test-only']);
    $connector->expects($this->once())->method('open')->willReturn($connection);
    $connector->expects($this->once())->method('accessError')->willReturn(new DatabaseCloudConnectorViewException('Access denied.'));
    $config = $this->createStub(IConfig::class);
    $config->method('getAppValue')->willReturn('connector');
    $store = new WopiCredentialStore(
      $config, 'cafevdb', $this->createStub(IDBConnection::class),
      $this->createStub(ContainerInterface::class), $this->createStub(ICrypto::class), $connector,
    );

    try {
      $store->recover('token', 'member', time() + 300);
      self::fail('Expected database failure.');
    } catch (DatabaseCloudConnectorViewException $exception) {
      self::assertSame('Access denied.', $exception->getMessage());
      self::assertNull($exception->getPrevious());
    }
  }
}
