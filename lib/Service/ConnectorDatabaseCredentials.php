<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\CAFEVDB\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use SensitiveParameter;
use Throwable;

use OCA\CAFEVDB\Database\Connection;
use OCA\CAFEVDB\Exceptions\DatabaseCloudConnectorViewException;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection as DbalConnection;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\DriverManager;

/** Connector secrets must be readable even before the orchestra app is unlocked. */
class ConnectorDatabaseCredentials
{
  public const WOPI = 'wopiConnectorCredentials';

  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    private IConfig $config,
    private ICrypto $crypto,
    private IDBConnection $cloudConnection,
    private IL10N $l,
    private string $appName,
  ) {
  }

  public function load(string $key): array
  {
    $encrypted = $this->config->getAppValue($this->appName, $key, '');
    return $encrypted === '' ? [] : json_decode($this->crypto->decrypt($encrypted), true, flags: JSON_THROW_ON_ERROR);
  }

  public function save(string $key, #[SensitiveParameter] array $credentials): void
  {
    if ($credentials === []) {
      $this->config->deleteAppValue($this->appName, $key);
    } else {
      $this->config->setAppValue($this->appName, $key, $this->crypto->encrypt(json_encode($credentials, JSON_THROW_ON_ERROR)));
    }
  }

  public function prepare(
    Connection $appConnection,
    string $user,
    #[SensitiveParameter] string $password,
  ): array {
    // Both schemas must be on the app's MariaDB server for cross-schema views.
    $params = $appConnection->getParams();
    if ($user === '' || $user === ($params['user'] ?? null)) {
      throw new DatabaseCloudConnectorViewException($this->l->t('Enter a dedicated database username, not the orchestra database username.'));
    }
    $credentials = [];
    if (isset($params['unix_socket']) && is_string($params['unix_socket']) && $params['unix_socket'] !== '') {
      $credentials['unix_socket'] = $params['unix_socket'];
    } else {
      $credentials = array_intersect_key($params, array_flip(['host', 'port']));
      if (!isset($credentials['host'])) {
        $credentials['host'] = 'localhost';
      }
    }
    $credentials += ['user' => $user, 'password' => $password];
    try {
      // Do not select the schema yet: the new user may not have any grants.
      $connection = $this->open($credentials);
      try {
        $account = $connection->fetchOne('SELECT CURRENT_USER()');
      } finally {
        $connection->close();
      }
      $separator = is_string($account) ? strrpos($account, '@') : false;
      if ($separator !== false && substr($account, 0, $separator) === $user) {
        return $credentials + ['accountHost' => substr($account, $separator + 1)];
      }
    } catch (Throwable) {
      // Driver errors and their traces must not reach the settings response.
    }
    throw $this->accessError();
  }

  public function open(#[SensitiveParameter] array $credentials, ?string $database = null): DbalConnection
  {
    $params = array_intersect_key($credentials, array_flip(['host', 'port', 'unix_socket', 'user', 'password']))
      + ['driver' => 'pdo_mysql', 'charset' => 'utf8mb4'];
    if ($database !== null) {
      $params['dbname'] = $database;
    }
    return DriverManager::getConnection($params);
  }

  public function checkViews(#[SensitiveParameter] array $credentials, string $database, array $views): void
  {
    try {
      $connection = $credentials === [] ? $this->cloudConnection : $this->open($credentials, $database);
      foreach ($views as $view) {
        $result = $connection->executeQuery('SELECT 1 FROM ' . WopiCredentialStore::qualify($database, $view) . ' LIMIT 1');
        if ($credentials === []) {
          $result->closeCursor();
        } else {
          $result->free();
        }
      }
      if ($credentials !== []) {
        $connection->close();
      }
    } catch (Throwable) {
      if ($credentials !== [] && isset($connection)) {
        $connection->close();
      }
      throw $this->accessError();
    }
  }

  public function accessError(): DatabaseCloudConnectorViewException
  {
    return new DatabaseCloudConnectorViewException(
      $this->l->t('Cannot access the connector database with these credentials. Check the MariaDB account, password, and view privileges.'),
    );
  }
  // phpcs:enable
}
