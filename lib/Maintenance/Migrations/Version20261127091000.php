<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\CAFEVDB\Maintenance\Migrations;

use OCA\CAFEVDB\Database\Doctrine\Migrations\AbstractStructuralMigration;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Schema\Schema;

/** Add timestamps to file contents without inventing historical dates. */
final class Version20261127091000 extends AbstractStructuralMigration
{
  /** {@inheritdoc} */
  public function getDescription(): string
  {
    return $this->l->t('Add creation and modification timestamps to file data.');
  }

  /** {@inheritdoc} */
  public function up(Schema $schema): void
  {
    $this->addSql('ALTER TABLE FileData ADD created DATETIME(6) DEFAULT NULL, ADD updated DATETIME(6) DEFAULT NULL');
    $this->addSql('ALTER TABLE FileData ALTER created SET DEFAULT CURRENT_TIME, ALTER updated SET DEFAULT CURRENT_TIME');
  }

  /** {@inheritdoc} */
  public function down(Schema $schema): void
  {
    $this->addSql('ALTER TABLE FileData DROP created, DROP updated');
  }
}
