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
use OCA\CAFEVDB\PageRenderer\DatabaseTables;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Schema\Schema;

/** Record directory-entry identities alongside durable cache changes. */
final class Version20261125161000 extends AbstractStructuralMigration
{
  /** {@inheritdoc} */
  public function getDescription(): string
  {
    return $this->l->t('Record directory-entry identities for database-storage cache updates.');
  }

  /** {@inheritdoc} */
  public function up(Schema $schema): void
  {
    $this->addSql('ALTER TABLE ' . DatabaseTables::DATABASE_STORAGE_CACHE_CHANGES_TABLE . ' ADD entry_id INT DEFAULT NULL');
  }

  /** {@inheritdoc} */
  public function down(Schema $schema): void
  {
    $this->addSql('ALTER TABLE ' . DatabaseTables::DATABASE_STORAGE_CACHE_CHANGES_TABLE . ' DROP COLUMN entry_id');
  }
}
