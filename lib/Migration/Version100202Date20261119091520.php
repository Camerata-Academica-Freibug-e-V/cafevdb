<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\CAFEVDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/** Drop the legacy blog reply column after its data has been copied. */
class Version100202Date20261119091520 extends SimpleMigrationStep
{
  /**
   * @param IDBConnection $connection Connection used to check the copied blog replies.
   */
  public function __construct(
    private IDBConnection $connection,
  ) {
  }

  /** {@inheritdoc} */
  #[Override]
  public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
  {
    /** @var ISchemaWrapper $schema */
    $schema = $schemaClosure();
    if (!$schema->hasTable('cafevdb_blog')) {
      return null;
    }

    $table = $schema->getTable('cafevdb_blog');
    if (!$table->hasColumn('inreplyto') || !$table->hasColumn('in_reply_to')) {
      return null;
    }

    // On fresh installs, Nextcloud builds the target schema before applying it.
    $liveSchema = $this->connection->createSchema();
    $liveTableName = $options['tablePrefix'] . 'cafevdb_blog';
    if ($liveSchema->hasTable($liveTableName) && $liveSchema->getTable($liveTableName)->hasColumn('inreplyto')) {
      if (!$liveSchema->getTable($liveTableName)->hasColumn('in_reply_to')) {
        return null;
      }

      $queryBuilder = $this->connection->getQueryBuilder();
      $result = $queryBuilder->select('1')
        ->from('cafevdb_blog')
        ->where('COALESCE(in_reply_to, -1) <> inreplyto')
        ->setMaxResults(1)
        ->executeQuery();
      $hasUncopiedRows = $result->fetchOne() !== false;
      $result->closeCursor();
      if ($hasUncopiedRows) {
        return null;
      }
    }

    $table->dropColumn('inreplyto');
    return $schema;
  }
}
