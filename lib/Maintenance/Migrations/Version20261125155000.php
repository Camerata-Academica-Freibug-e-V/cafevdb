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

/** Persist database-storage cache notifications until an authorized mount is available. */
final class Version20261125155000 extends AbstractStructuralMigration
{
  /** {@inheritdoc} */
  public function getDescription(): string
  {
    return $this->l->t('Create durable queue for database-storage cache updates.');
  }

  /** {@inheritdoc} */
  public function up(Schema $schema): void
  {
    $table = DatabaseTables::DATABASE_STORAGE_CACHE_CHANGES_TABLE;
    $this->addSql(
      'CREATE TABLE ' . $table . ' (
        id INT AUTO_INCREMENT NOT NULL,
        storage_id VARCHAR(512) NOT NULL,
        path TEXT NOT NULL,
        old_path TEXT DEFAULT NULL,
        created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(255) NOT NULL DEFAULT \'unknown\',
        INDEX IDX_STORAGE_CACHE_CHANGE_STORAGE (storage_id, id),
        PRIMARY KEY (id)
      ) ENGINE=InnoDB',
    );
  }

  /** {@inheritdoc} */
  public function down(Schema $schema): void
  {
    $this->addSql('DROP TABLE ' . DatabaseTables::DATABASE_STORAGE_CACHE_CHANGES_TABLE);
  }
}
