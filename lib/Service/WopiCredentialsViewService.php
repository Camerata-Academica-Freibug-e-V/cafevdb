<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\CAFEVDB\Service;

use OCP\IConfig;
use OCP\IL10N;
use SensitiveParameter;
use Throwable;

use OCA\CAFEVDB\Constants;
use OCA\CAFEVDB\Database\Connection;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities\WopiCredential;
use OCA\CAFEVDB\Exceptions\DatabaseCloudConnectorViewException;
use OCA\CAFEVDB\Settings\ConfigConstants;

/** Provision the restricted credential lookup used by WOPI database mounts. */
class WopiCredentialsViewService
{
  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    private EncryptionService $encryptionService,
    private IConfig $cloudConfig,
    private IL10N $l,
    private string $appName,
    private Connection $connection,
    private ConnectorDatabaseCredentials $connectorCredentials,
  ) {
  }
  // phpcs:enable

  /**
   * Expose a single unexpired WOPI credential to the Nextcloud database user.
   *
   * @param null|string $database Connector database; defaults to the app database.
   * @param null|array $credentials Override, or null to use the saved credentials.
   * @param bool $checkAccess Whether to verify the view using the connector connection.
   *
   * @return void
   */
  public function updateWopiCredentialsView(
    ?string $database,
    #[SensitiveParameter] ?array $credentials = null,
    bool $checkAccess = false,
  ): void
  {
    $previousCredentials = $this->connectorCredentials->load(ConnectorDatabaseCredentials::WOPI);
    $grantee = $this->grantee($credentials ?? $previousCredentials);
    $appDbName = $this->encryptionService->getConfigValue(ConfigConstants::APP_DB_NAME);
    $database = $database ?: $appDbName;
    $previousDatabase = $this->getConfiguredDatabase();
    $function = WopiCredentialStore::qualify($database, WopiCredentialStore::TOKEN_HASH_FUNCTION);
    $view = WopiCredentialStore::qualify($database, WopiCredentialStore::VIEW_NAME);
    $table = WopiCredentialStore::qualify($appDbName, WopiCredential::TABLE_NAME);
    try {
      $this->connection->executeStatement(
        'CREATE OR REPLACE FUNCTION ' . $function . '()
         RETURNS VARBINARY(64)
         NOT DETERMINISTIC NO SQL SQL SECURITY INVOKER
         RETURN IF(OCTET_LENGTH(@' . Constants::SQL_WOPI_TOKEN_HASH . ') = 64, @' . Constants::SQL_WOPI_TOKEN_HASH . ', NULL)',
      );
      $this->connection->executeStatement(
        'CREATE OR REPLACE ALGORITHM=TEMPTABLE SQL SECURITY DEFINER VIEW ' . $view . ' AS
         SELECT encrypted_key, expires FROM ' . $table . '
         WHERE token_hash = ' . $function . '() AND expires > UNIX_TIMESTAMP()',
      );
      $this->connection->executeStatement('GRANT EXECUTE ON FUNCTION ' . $function . ' TO ' . $grantee);
      $this->connection->executeStatement('GRANT SELECT ON ' . $view . ' TO ' . $grantee);
    } catch (Throwable $exception) {
      throw new DatabaseCloudConnectorViewException(
        $this->l->t('Unable to configure the WOPI credential view.'),
        $exception->getCode(),
        $exception,
      );
    }
    if ($checkAccess) {
      $this->connectorCredentials->checkViews($credentials ?? $previousCredentials, $database, [WopiCredentialStore::VIEW_NAME]);
    }
    if ($credentials !== null) {
      $this->connectorCredentials->save(ConnectorDatabaseCredentials::WOPI, $credentials);
    }
    // Switch lookups to the new view before dropping the old one.
    $this->cloudConfig->setAppValue($this->appName, WopiCredentialStore::CONNECTOR_DATABASE_CONFIG, $database);
    if ($previousDatabase !== '' && $previousDatabase !== $database) {
      $this->dropConnectorObjects($previousDatabase);
    }
  }

  /**
   * @param array $credentials
   *
   * @return string
   */
  private function grantee(#[SensitiveParameter] array $credentials): string
  {
    if ($credentials !== []) {
      return $this->connection->quote($credentials['user']) . '@' . $this->connection->quote($credentials['accountHost']);
    }

    $cloudDbHost = $this->cloudConfig->getSystemValue('dbhost');
    $appDbHost = $this->encryptionService->getConfigValue(ConfigConstants::APP_DB_SERVER);
    if ($this->cloudConfig->getSystemValue('dbtype', 'sqlite') !== 'mysql' || $cloudDbHost !== $appDbHost) {
      throw new DatabaseCloudConnectorViewException(
        $this->l->t('Nextcloud must use MySQL or MariaDB on the orchestra database server, or you must configure separate connector credentials.'),
      );
    }
    return $this->connection->quote($this->cloudConfig->getSystemValue(ConfigConstants::APP_DB_USER)) . "@'localhost'";
  }

  /**
   * Remove restricted WOPI connector access without touching stored credentials.
   *
   * @return void
   */
  public function removeWopiCredentialsView(): void
  {
    $database = $this->getConfiguredDatabase();
    if ($database !== '') {
      $this->dropConnectorObjects($database);
      $this->cloudConfig->deleteAppValue($this->appName, WopiCredentialStore::CONNECTOR_DATABASE_CONFIG);
    }
  }

  /** @return string The active connector locator, readable before the app key is available. */
  private function getConfiguredDatabase(): string
  {
    return $this->cloudConfig->getAppValue($this->appName, WopiCredentialStore::CONNECTOR_DATABASE_CONFIG, '');
  }

  /**
   * @param string $database
   *
   * @return void
   */
  private function dropConnectorObjects(string $database): void
  {
    $this->connection->executeStatement(
      'DROP VIEW IF EXISTS ' . WopiCredentialStore::qualify($database, WopiCredentialStore::VIEW_NAME),
    );
    $this->connection->executeStatement(
      'DROP FUNCTION IF EXISTS ' . WopiCredentialStore::qualify($database, WopiCredentialStore::TOKEN_HASH_FUNCTION),
    );
  }
}
