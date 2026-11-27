<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\CAFEVDB\Maintenance\Migrations;

use OCA\CAFEVDB\Database\Doctrine\Migrations\AbstractStructuralMigration;
use OCA\CAFEVDB\Controller\EnumSimpleSettingsKey;
use OCA\CAFEVDB\Service\CloudUserConnectorService;
use OCA\CAFEVDB\Service\EncryptionService;
use OCA\CAFEVDB\Service\Finance\InstrumentInsuranceReceivablesGenerator;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Schema\Schema;
use UnexpectedValueException;

/** Give insurance brokers a stable identity independent of their short name. */
final class Version20261127120000 extends AbstractStructuralMigration
{
  /** {@inheritdoc} */
  public function getDescription(): string
  {
    return $this->l->t('Give insurance brokers stable identifiers.');
  }

  /** {@inheritdoc} */
  public function preUp(Schema $schema): void
  {
    if (!$this->dryRun) {
      $this->checkReceivables(false);
    }
  }

  /** {@inheritdoc} */
  public function up(Schema $schema): void
  {
    $this->addSql('ALTER TABLE InsuranceBrokers ADD id INT NOT NULL AUTO_INCREMENT, ADD UNIQUE INDEX UNIQ_INSURANCE_BROKER_ID (id)');
    $this->addSql('ALTER TABLE InsuranceRates ADD broker_numeric_id INT DEFAULT NULL');
    $this->addSql('ALTER TABLE InstrumentInsurances ADD broker_numeric_id INT DEFAULT NULL');
    $this->addSql('UPDATE InsuranceRates r INNER JOIN InsuranceBrokers b ON r.broker_id = b.short_name SET r.broker_numeric_id = b.id');
    $this->addSql('UPDATE InstrumentInsurances i INNER JOIN InsuranceBrokers b ON i.broker_id = b.short_name SET i.broker_numeric_id = b.id');
    $this->addSql('ALTER TABLE InsuranceRates MODIFY broker_numeric_id INT NOT NULL');
    $this->addSql('ALTER TABLE InstrumentInsurances MODIFY broker_numeric_id INT NOT NULL');
    $this->addSql('ALTER TABLE InstrumentInsurances DROP FOREIGN KEY FK_B9BA7EF6CC064FCBD069886');
    $this->addSql('ALTER TABLE InsuranceRates DROP FOREIGN KEY FK_CB75C3526CC064FC, DROP FOREIGN KEY FK_CB75C3526CC064FC_ONUPDATE_CASCADE');
    $this->addSql('ALTER TABLE InsuranceRates DROP PRIMARY KEY, DROP INDEX IDX_CB75C3526CC064FC, DROP broker_id,
      CHANGE broker_numeric_id broker_id INT NOT NULL, ADD PRIMARY KEY (broker_id, geographical_scope),
      ADD INDEX IDX_CB75C3526CC064FC (broker_id)');
    $this->addSql('ALTER TABLE InstrumentInsurances DROP INDEX IDX_B9BA7EF6CC064FCBD069886, DROP broker_id,
      CHANGE broker_numeric_id broker_id INT NOT NULL, ADD INDEX IDX_B9BA7EF6CC064FCBD069886 (broker_id, geographical_scope)');
    $this->addSql('ALTER TABLE InsuranceBrokers DROP PRIMARY KEY, ADD PRIMARY KEY (id), ADD UNIQUE INDEX UNIQ_INSURANCE_BROKER_SHORT_NAME (short_name), DROP INDEX UNIQ_INSURANCE_BROKER_ID');
    $this->addSql('ALTER TABLE InsuranceRates ADD CONSTRAINT FK_CB75C3526CC064FC FOREIGN KEY (broker_id) REFERENCES InsuranceBrokers (id)');
    $this->addSql('ALTER TABLE InstrumentInsurances ADD CONSTRAINT FK_B9BA7EF6CC064FCBD069886
      FOREIGN KEY (broker_id, geographical_scope) REFERENCES InsuranceRates (broker_id, geographical_scope)');
    $this->addSql(
      'UPDATE ProjectParticipantFieldsDataOptions o
       INNER JOIN ProjectParticipantFieldsDataOptions g ON g.field_id = o.field_id AND g.data = ?
       INNER JOIN InsuranceBrokers b ON b.short_name = JSON_UNQUOTE(JSON_EXTRACT(o.data, \'$.broker\'))
       SET o.data = CONCAT(\'{"year":\', JSON_QUOTE(JSON_UNQUOTE(JSON_EXTRACT(o.data, \'$.year\'))), \',"brokerId":\', b.id, \'}\')
       WHERE JSON_VALID(o.data) AND JSON_EXTRACT(o.data, \'$.broker\') IS NOT NULL',
      [InstrumentInsuranceReceivablesGenerator::class],
    );
  }

  /** {@inheritdoc} */
  public function postUp(Schema $schema): void
  {
    $this->refreshConnectorViews();
  }

  /** {@inheritdoc} */
  public function preDown(Schema $schema): void
  {
    if (!$this->dryRun) {
      $this->checkReceivables(true);
    }
  }

  /** {@inheritdoc} */
  public function down(Schema $schema): void
  {
    $this->addSql(
      'UPDATE ProjectParticipantFieldsDataOptions o
       INNER JOIN ProjectParticipantFieldsDataOptions g ON g.field_id = o.field_id AND g.data = ?
       INNER JOIN InsuranceBrokers b ON b.id = JSON_EXTRACT(o.data, \'$.brokerId\')
       SET o.data = CONCAT(\'{"year":\', JSON_QUOTE(JSON_UNQUOTE(JSON_EXTRACT(o.data, \'$.year\'))), \',"broker":\', JSON_QUOTE(b.short_name), \'}\')
       WHERE JSON_VALID(o.data) AND JSON_EXTRACT(o.data, \'$.brokerId\') IS NOT NULL',
      [InstrumentInsuranceReceivablesGenerator::class],
    );
    $this->addSql('ALTER TABLE InsuranceRates ADD broker_short_name VARCHAR(40) DEFAULT NULL');
    $this->addSql('ALTER TABLE InstrumentInsurances ADD broker_short_name VARCHAR(40) DEFAULT NULL');
    $this->addSql('UPDATE InsuranceRates r INNER JOIN InsuranceBrokers b ON r.broker_id = b.id SET r.broker_short_name = b.short_name');
    $this->addSql('UPDATE InstrumentInsurances i INNER JOIN InsuranceBrokers b ON i.broker_id = b.id SET i.broker_short_name = b.short_name');
    $this->addSql('ALTER TABLE InsuranceRates MODIFY broker_short_name VARCHAR(40) NOT NULL');
    $this->addSql('ALTER TABLE InstrumentInsurances MODIFY broker_short_name VARCHAR(40) NOT NULL');
    $this->addSql('ALTER TABLE InstrumentInsurances DROP FOREIGN KEY FK_B9BA7EF6CC064FCBD069886');
    $this->addSql('ALTER TABLE InsuranceRates DROP FOREIGN KEY FK_CB75C3526CC064FC');
    $this->addSql('ALTER TABLE InsuranceRates DROP PRIMARY KEY, DROP INDEX IDX_CB75C3526CC064FC, DROP broker_id,
      CHANGE broker_short_name broker_id VARCHAR(40) NOT NULL, ADD PRIMARY KEY (broker_id, geographical_scope),
      ADD INDEX IDX_CB75C3526CC064FC (broker_id)');
    $this->addSql('ALTER TABLE InstrumentInsurances DROP INDEX IDX_B9BA7EF6CC064FCBD069886, DROP broker_id,
      CHANGE broker_short_name broker_id VARCHAR(40) NOT NULL, ADD INDEX IDX_B9BA7EF6CC064FCBD069886 (broker_id, geographical_scope)');
    $this->addSql('ALTER TABLE InsuranceBrokers MODIFY id INT NOT NULL');
    $this->addSql('ALTER TABLE InsuranceBrokers DROP PRIMARY KEY, ADD PRIMARY KEY (short_name), DROP INDEX UNIQ_INSURANCE_BROKER_SHORT_NAME, DROP id');
    $this->addSql('ALTER TABLE InsuranceRates ADD CONSTRAINT FK_CB75C3526CC064FC FOREIGN KEY (broker_id) REFERENCES InsuranceBrokers (short_name),
      ADD CONSTRAINT FK_CB75C3526CC064FC_ONUPDATE_CASCADE FOREIGN KEY (broker_id) REFERENCES InsuranceBrokers (short_name) ON UPDATE CASCADE');
    $this->addSql('ALTER TABLE InstrumentInsurances ADD CONSTRAINT FK_B9BA7EF6CC064FCBD069886
      FOREIGN KEY (broker_id, geographical_scope) REFERENCES InsuranceRates (broker_id, geographical_scope)');
  }

  /** {@inheritdoc} */
  public function postDown(Schema $schema): void
  {
    $this->refreshConnectorViews();
  }

  /** @return void */
  private function refreshConnectorViews(): void
  {
    if ($this->dryRun) {
      return;
    }
    $config = $this->appContainer->get(EncryptionService::class);
    if ($config->getConfigValue(EnumSimpleSettingsKey::MUSICIAN_PERSONALIZED_VIEWS->value, 'off') !== 'on') {
      return;
    }
    $database = $config->getConfigValue(EnumSimpleSettingsKey::CLOUD_USER_VIEWS_DATABASE->value);
    $this->appContainer->get(CloudUserConnectorService::class)->updateMusicianPersonalizedViews($database);
  }

  /**
   * @param bool $numeric Whether the stored broker reference is numeric.
   *
   * @return void
   */
  private function checkReceivables(bool $numeric): void
  {
    $connection = $this->entityManager->getConnection();
    $options = $connection->fetchAllAssociative(
      'SELECT o.field_id, o.data FROM ProjectParticipantFieldsDataOptions o
       INNER JOIN ProjectParticipantFieldsDataOptions g ON g.field_id = o.field_id AND g.data = ?
       WHERE o.data LIKE \'{%\'',
      [InstrumentInsuranceReceivablesGenerator::class],
    );
    foreach ($options as $option) {
      $data = json_decode($option['data'], true, flags: JSON_THROW_ON_ERROR);
      $key = $numeric ? 'brokerId' : 'broker';
      if (!is_array($data) || count($data) !== 2 || !isset($data['year'], $data[$key])
          || !is_string($data['year']) || !preg_match('/^\d{4}$/D', $data['year'])
          || ($numeric ? !is_int($data[$key]) : !is_string($data[$key]))) {
        throw new UnexpectedValueException('Invalid insurance receivable identity in field ' . $option['field_id']);
      }
      $column = $numeric ? 'id' : 'short_name';
      if ($connection->fetchOne('SELECT 1 FROM InsuranceBrokers WHERE ' . $column . ' = ?', [$data[$key]]) === false) {
        throw new UnexpectedValueException('Unresolved insurance broker reference in field ' . $option['field_id'] . ': ' . $data[$key]);
      }
    }
  }
}
