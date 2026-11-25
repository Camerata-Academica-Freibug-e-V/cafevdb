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

use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Schema\Schema;
use OCA\CAFEVDB\Database\Doctrine\Migrations\AbstractStructuralMigration;

/** Add a change-revision counter to the database storages. */
final class Version20261125100000 extends AbstractStructuralMigration
{
  /** {@inheritdoc} */
  public function getDescription(): string
  {
    return $this->l->t('Add a change-revision counter to the database storages for sub-second cache invalidation.');
  }

  /** {@inheritdoc} */
  public function up(Schema $schema): void
  {
    $this->addSql('ALTER TABLE DatabaseStorages ADD revision BIGINT UNSIGNED DEFAULT 0 NOT NULL');
  }

  /** {@inheritdoc} */
  public function down(Schema $schema): void
  {
    $this->addSql('ALTER TABLE DatabaseStorages DROP revision');
  }
}
