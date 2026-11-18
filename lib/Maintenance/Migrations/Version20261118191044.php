<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\CAFEVDB\Maintenance\Migrations;

use OCA\CAFEVDB\Controller\EnumSimpleSettingsKey;
use OCA\CAFEVDB\Database\Doctrine\Migrations\AbstractStructuralMigration;
use OCA\CAFEVDB\Service\CloudUserConnectorService;
use OCA\CAFEVDB\Service\EncryptionService;
use OCA\CAFEVDB\Service\WopiCredentialStore;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Schema\Schema;
use OCP\IConfig;

/** Add storage and restricted connector access for token-encrypted app keys. */
final class Version20261118191044 extends AbstractStructuralMigration
{
  /** {@inheritdoc} */
  public function getDescription(): string
  {
    return $this->l->t('Create storage for encrypted WOPI mount credentials.');
  }

  /** {@inheritdoc} */
  public function up(Schema $schema): void
  {
    // The table survives if provisioning the connector view fails.
    $this->addSql(
      'CREATE TABLE IF NOT EXISTS WopiCredentials (
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        encrypted_key LONGTEXT NOT NULL,
        expires BIGINT NOT NULL,
        INDEX IDX_WOPI_CREDENTIAL_EXPIRY (expires),
        PRIMARY KEY (token_hash)
      ) ENGINE=InnoDB',
    );
  }

  /** {@inheritdoc} */
  public function postUp(Schema $schema): void
  {
    if ($this->dryRun) {
      return;
    }

    $config = $this->appContainer->get(EncryptionService::class);
    if ($config->getConfigValue(EnumSimpleSettingsKey::IMPORT_CLUB_MEMBERS_AS_CLOUD_USERS->value, 'off') !== 'on'
        && $config->getConfigValue(EnumSimpleSettingsKey::MUSICIAN_PERSONALIZED_VIEWS->value, 'off') !== 'on') {
      return;
    }

    $database = $config->getConfigValue(EnumSimpleSettingsKey::CLOUD_USER_VIEWS_DATABASE->value);
    $this->appContainer->get(CloudUserConnectorService::class)->updateWopiCredentialsView($database);
  }

  /** {@inheritdoc} */
  public function down(Schema $schema): void
  {
    $this->addSql('DROP TABLE WopiCredentials');
  }

  /** {@inheritdoc} */
  public function preDown(Schema $schema): void
  {
    if ($this->dryRun) {
      return;
    }

    $database = $this->appContainer->get(IConfig::class)->getAppValue(
      $this->appContainer->get('AppName'),
      WopiCredentialStore::CONNECTOR_DATABASE_CONFIG,
      '',
    );
    if ($database !== '') {
      $this->appContainer->get(CloudUserConnectorService::class)->removeWopiCredentialsView($database);
    }
  }
}
