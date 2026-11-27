<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use RuntimeException;

use OCA\CAFEVDB\Service\OrphanTreeService;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\DriverManager;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Exception as DBALException;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;

/** Actual listing and transactional pruning with restrictive parent foreign keys. */
#[Attributes\CoversClass(OrphanTreeService::class)]
class OrphanTreeServiceTest extends TestCase
{
  private Connection $connection;
  private OrphanTreeService $service;

  /** {@inheritdoc} */
  protected function setUp(): void
  {
    $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $this->connection->executeStatement('PRAGMA foreign_keys = ON');
    $this->connection->executeStatement(
      'CREATE TABLE DatabaseStorageDirEntries (
        id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES DatabaseStorageDirEntries(id),
        file_id INTEGER, type TEXT, name TEXT)',
    );
    $this->connection->executeStatement(
      'CREATE TABLE DatabaseStorages (id INTEGER PRIMARY KEY, root_id INTEGER REFERENCES DatabaseStorageDirEntries(id))',
    );
    $this->connection->executeStatement(
      "INSERT INTO DatabaseStorageDirEntries VALUES
       (1, NULL, NULL, 'folder', 'mounted'), (2, 1, NULL, 'folder', 'empty mounted'),
       (10, NULL, NULL, 'folder', 'z-root'), (11, 10, NULL, 'folder', 'z-folder'),
       (12, 10, NULL, 'folder', 'a-folder'), (13, 11, 50, 'file', 'z.pdf'),
       (14, 11, 51, 'file', 'a.pdf'),
       (20, NULL, NULL, 'folder', 'a-empty'), (19, 20, NULL, 'folder', 'child'),
       (18, 19, NULL, 'folder', 'grandchild'),
       (30, NULL, NULL, 'folder', 'nested-root'), (31, 30, NULL, 'folder', 'nested mount'),
       (40, NULL, 52, 'folder', 'invalid folder'),
       (50, NULL, NULL, 'file', 'missing file data'),
       (60, NULL, NULL, 'generic', 'not a folder')",
    );
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (1, 1), (2, 31)');
    $this->service = new OrphanTreeService($this->connection);
  }

  /** {@inheritdoc} */
  protected function tearDown(): void
  {
    $this->connection->close();
  }

  /** @return void */
  public function testNamesIncludeParentPathsAndSortByPathThenName(): void
  {
    $before = $this->snapshot();
    $report = $this->service->inspect(true);
    self::assertSame([20, 40, 50, 30, 60, 10], array_column($report['trees'], 'root_id'));
    $tree = $report['trees'][5]['entries'];
    self::assertSame([10, 12, 11, 14, 13], array_column($tree, 'id'));
    self::assertSame(['/', '/z-root', '/z-root', '/z-root/z-folder', '/z-root/z-folder'], array_column($tree, 'parent_path'));
    self::assertSame($before, $this->snapshot());
    self::assertArrayNotHasKey('parent_path', $this->service->inspect()['trees'][0]['entries'][0]);
  }

  /** @return void */
  public function testPruningRemovesOnlyCompleteFolderOnlyTreesAndIsIdempotent(): void
  {
    $report = $this->service->inspect(true, true);
    self::assertSame([20], $report['pruned_root_ids']);
    self::assertSame(1, $report['summary']['pruned_trees']);
    self::assertSame(3, $report['summary']['pruned_entries']);
    self::assertSame([1, 2, 10, 11, 12, 13, 14, 30, 31, 40, 50, 60], array_column($this->snapshot(), 'id'));
    self::assertSame(2, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorages'));
    $before = $this->snapshot();
    self::assertSame([], $this->service->inspect(false, true)['pruned_root_ids']);
    self::assertSame($before, $this->snapshot());
    self::assertFalse($this->connection->isTransactionActive());
  }

  /** @return void */
  public function testUnnamedRootHasNoDoubleSlashInParentPaths(): void
  {
    $this->connection->executeStatement("UPDATE DatabaseStorageDirEntries SET name = '' WHERE id = 10");
    $tree = $this->service->inspect(true)['trees'][0]['entries'];
    self::assertSame(['/', '/', '/', '/z-folder', '/z-folder'], array_column($tree, 'parent_path'));
  }

  /** @return void */
  public function testCollectionClassificationRejectsMalformedTrees(): void
  {
    $this->connection->executeStatement(
      "INSERT INTO DatabaseStorageDirEntries VALUES
       (70, NULL, NULL, 'folder', 'bad parent'),
       (71, 70, 53, 'file', 'file'),
       (72, 71, NULL, 'folder', 'child')",
    );
    $before = $this->snapshot();
    $report = $this->service->classifyForCollection();
    self::assertSame([10, 20], $report['eligible_root_ids']);
    $rejected = array_column($report['rejected'], 'reasons', 'root_id');
    self::assertContains('registered-descendant', $rejected[30]);
    self::assertContains('invalid-file-reference', $rejected[40]);
    self::assertContains('root-not-folder', $rejected[50]);
    self::assertContains('root-not-folder', $rejected[60]);
    self::assertContains('non-folder-parent', $rejected[70]);
    self::assertSame($before, $this->snapshot());
  }

  /** @return void */
  public function testFailureRollsBackPreviouslyDeletedFolders(): void
  {
    $this->connection->executeStatement(
      "CREATE TRIGGER fail_delete BEFORE DELETE ON DatabaseStorageDirEntries WHEN OLD.id = 20
       BEGIN SELECT RAISE(ABORT, 'injected failure'); END",
    );
    $before = $this->snapshot();
    try {
      $this->service->inspect(false, true);
      self::fail('Expected the root deletion to fail.');
    } catch (DBALException $exception) {
      self::assertStringContainsString('injected failure', $exception->getMessage());
    }
    self::assertSame($before, $this->snapshot());
    self::assertFalse($this->connection->isTransactionActive());
  }

  /** @return void */
  public function testPruningDoesNotTakeOverCallerTransaction(): void
  {
    $this->connection->beginTransaction();
    try {
      $this->service->inspect(false, true);
      self::fail('Expected transaction ownership error.');
    } catch (RuntimeException $exception) {
      self::assertStringContainsString('own transaction', $exception->getMessage());
    }
    self::assertTrue($this->connection->isTransactionActive());
    $this->connection->rollBack();
  }

  /** @return array */
  private function snapshot(): array
  {
    return $this->connection->fetchAllAssociative('SELECT * FROM DatabaseStorageDirEntries ORDER BY id');
  }
}
