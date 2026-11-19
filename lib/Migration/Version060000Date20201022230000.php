<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2020-2024, 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace OCA\CAFEVDB\Migration;

use Closure;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add in_reply_to column to blog entries.
 */
class Version060000Date20201022230000 extends SimpleMigrationStep
{
  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    private IDBConnection $connection,
  ) {
  }
  // phpcs:enable

  /** {@inheritdoc} */
  public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options)
  {
  }

  /** {@inheritdoc} */
  public function changeSchema(IOutput $output, Closure $schemaClosure, array $options)
  {
    /** @var ISchemaWrapper $schema */
    $schema = $schemaClosure();

    $table = $schema->getTable('cafevdb_blog');
    if (!$table->hasColumn('in_reply_to')) {
      $table->addColumn('in_reply_to', 'integer', [
        'notnull' => true,
        'length' => 4,
        'default' => -1,
      ]);
    }

    return $schema;
  }

  /** {@inheritdoc} */
  public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options)
  {
    /** @var ISchemaWrapper $schema */
    $schema = $schemaClosure();
    if (!$schema->hasTable('cafevdb_blog')) {
      return;
    }
    $table = $schema->getTable('cafevdb_blog');
    if (!$table->hasColumn('inreplyto') || !$table->hasColumn('in_reply_to')) {
      return;
    }

    $this->connection->getQueryBuilder()
      ->update('cafevdb_blog')
      ->set('in_reply_to', 'inreplyto')
      ->executeStatement();
  }
}
