<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Service;

use OCA\CAFEVDB\Constants;
use OCA\CAFEVDB\Database\Connection;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities\WopiCredential;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use Psr\Container\ContainerInterface;
use RuntimeException;
use SensitiveParameter;

/** Write app keys with the orchestra connection; read only through the connector view. */
class WopiCredentialStore
{
  public const CONNECTOR_DATABASE_CONFIG = 'wopiConnectorDatabase';
  public const VIEW_NAME = 'WopiCredentialsView';
  public const TOKEN_HASH_FUNCTION = Constants::SQL_WOPI_TOKEN_HASH;

  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    private IConfig $config,
    private string $appName,
    private IDBConnection $cloudConnection,
    private ContainerInterface $appContainer,
    private ICrypto $crypto,
    private ConnectorDatabaseCredentials $connectorCredentials,
  ) {
  }

  public function store(
    #[SensitiveParameter] string $token,
    string $userId,
    int $expiry,
    #[SensitiveParameter] string $appKey,
  ): void {
    $this->connectorDatabase();
    $now = time();
    if ($token === '' || $expiry <= $now || $appKey === '') {
      throw new RuntimeException('Cannot store an expired or empty WOPI credential.');
    }

    $encryptedKey = $this->crypto->encrypt(
      json_encode(['userId' => $userId, 'expires' => $expiry, 'key' => base64_encode($appKey)], JSON_THROW_ON_ERROR),
      $this->encryptionKey($token),
    );

    $connection = $this->appContainer->get(Connection::class);
    $connection->executeStatement('DELETE FROM ' . WopiCredential::TABLE_NAME . ' WHERE expires <= ?', [$now]);
    $connection->executeStatement(
      'INSERT INTO ' . WopiCredential::TABLE_NAME . ' (token_hash, encrypted_key, expires) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE encrypted_key = ?, expires = ?',
      [hash('sha256', $token), $encryptedKey, $expiry, $encryptedKey, $expiry],
    );
  }

  public function recover(#[SensitiveParameter] string $token, string $userId, int $expiry): ?string
  {
    if ($token === '' || $expiry <= time()) {
      throw new RuntimeException('Cannot recover an expired or empty WOPI credential.');
    }
    $database = $this->connectorDatabase();
    $view = self::qualify($database, self::VIEW_NAME);
    $credentials = $this->connectorCredentials->load(ConnectorDatabaseCredentials::WOPI);
    if ($credentials !== []) {
      try {
        $connection = $this->connectorCredentials->open($credentials, $database);
        $connection->executeStatement('SET @' . Constants::SQL_WOPI_TOKEN_HASH . ' = ?', [hash('sha256', $token)]);
        $encoded = $connection->fetchOne('SELECT encrypted_key FROM ' . $view . ' WHERE expires = ?', [$expiry]);
      } catch (\Throwable) {
        throw $this->connectorCredentials->accessError();
      } finally {
        // The dedicated connection must not keep the per-request session variable.
        if (isset($connection)) {
          $connection->close();
        }
      }
    } else {
      $encoded = $this->recoverUsingCloudConnection($view, $token, $expiry);
    }

    return $encoded === false ? null : $this->decryptCredential($encoded, $token, $userId, $expiry);
  }

  private function recoverUsingCloudConnection(string $view, #[SensitiveParameter] string $token, int $expiry): mixed
  {
    $variable = '@' . Constants::SQL_WOPI_TOKEN_HASH;
    try {
      $this->cloudConnection->executeStatement('SET ' . $variable . ' = ?', [hash('sha256', $token)]);
      $result = $this->cloudConnection->executeQuery('SELECT encrypted_key FROM ' . $view . ' WHERE expires = ?', [$expiry]);
      try {
        return $result->fetchOne();
      } finally {
        $result->closeCursor();
      }
    } finally {
      $this->cloudConnection->executeStatement('SET ' . $variable . ' = NULL');
    }
  }

  private function decryptCredential(string $encoded, #[SensitiveParameter] string $token, string $userId, int $expiry): string
  {
    $credential = json_decode(
      $this->crypto->decrypt($encoded, $this->encryptionKey($token)),
      true,
      flags: JSON_THROW_ON_ERROR,
    );
    if (!is_array($credential)
        || ($credential['userId'] ?? null) !== $userId
        || ($credential['expires'] ?? null) !== $expiry
        || !is_string($credential['key'] ?? null)) {
      throw new RuntimeException('Invalid WOPI credential.');
    }
    $key = base64_decode($credential['key'], true);
    if ($key === false || $key === '') {
      throw new RuntimeException('Invalid WOPI app key.');
    }
    return $key;
  }

  public static function qualify(string $database, string $name): string
  {
    return '`' . str_replace('`', '``', $database) . '`.`' . str_replace('`', '``', $name) . '`';
  }

  private function connectorDatabase(): string
  {
    $database = $this->config->getAppValue($this->appName, self::CONNECTOR_DATABASE_CONFIG, '');
    if ($database === '') {
      throw new RuntimeException('WOPI connector view is not configured.');
    }
    return $database;
  }

  private function encryptionKey(#[SensitiveParameter] string $token): string
  {
    // The lookup hash is visible to SQL; it must not also be the encryption key.
    return hash_hkdf('sha256', $token, 32, 'cafevdb/wopi-credential/v1');
  }
  // phpcs:enable
}
