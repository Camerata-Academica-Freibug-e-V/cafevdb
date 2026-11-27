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

use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use OCA\CAFEVDB\Service\ParticipantFileAuditService;
use OCA\CAFEVDB\Service\ParticipantFileRepairService;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\DriverManager;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Exception as DBALException;

/** Execute actual backfill SQL, including rollback and repeat runs. */
#[Attributes\CoversClass(ParticipantFileRepairService::class)]
#[Attributes\UsesClass(ParticipantFileAuditService::class)]
class ParticipantFileRepairServiceTest extends TestCase
{
  private Connection $connection;
  private ParticipantFileRepairService $service;

  /** {@inheritdoc} */
  protected function setUp(): void
  {
    $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
      'CREATE TABLE Files (id INTEGER PRIMARY KEY, type TEXT)',
      'CREATE TABLE EncryptedFileOwners (encrypted_file_id INTEGER, musician_id INTEGER, UNIQUE(encrypted_file_id, musician_id))',
      'CREATE TABLE DatabaseStorageDirEntries (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, file_id INTEGER, '
      . 'type TEXT, name TEXT, created TEXT, updated TEXT, UNIQUE(parent_id, name))',
      'CREATE TABLE DatabaseStorages (id INTEGER PRIMARY KEY, root_id INTEGER)',
      "CREATE TABLE ProjectParticipants (musician_id INTEGER, project_id INTEGER, database_documents_id INTEGER, deleted TEXT, participation_status TEXT DEFAULT 'regular')",
      'CREATE TABLE Projects (id INTEGER PRIMARY KEY, deleted TEXT)',
      'CREATE TABLE Musicians (id INTEGER PRIMARY KEY, deleted TEXT)',
      'CREATE TABLE ProjectInstruments (project_id INTEGER, musician_id INTEGER, instrument_id INTEGER)',
      'CREATE TABLE instrument_instrument_family (instrument_id INTEGER, instrument_family_id INTEGER)',
      'CREATE TABLE InstrumentFamilies (id INTEGER PRIMARY KEY, family TEXT)',
      'CREATE TABLE SepaDebitMandates (sequence INTEGER, musician_id INTEGER, project_id INTEGER, written_mandate_id INTEGER, deleted TEXT)',
      'CREATE TABLE Invoices (id INTEGER PRIMARY KEY, debitor_id INTEGER, project_id INTEGER, written_invoice_id INTEGER, deleted TEXT)',
      'CREATE TABLE CompositePayments (id INTEGER PRIMARY KEY, musician_id INTEGER, project_id INTEGER, supporting_document_id INTEGER)',
      'CREATE TABLE DonationReceipts (id INTEGER PRIMARY KEY, donation_id INTEGER, supporting_document_id INTEGER, deleted TEXT)',
      "CREATE TABLE ProjectParticipantFields (id INTEGER PRIMARY KEY, data_type TEXT, project_id INTEGER DEFAULT 20, deleted TEXT, "
      . "participant_access TEXT DEFAULT 'none', participation_context TEXT DEFAULT 'unrestricted', multiplicity TEXT DEFAULT 'parallel')",
      'CREATE TABLE ProjectParticipantFieldsData (field_id INTEGER, musician_id INTEGER, project_id INTEGER, option_key BLOB, '
      . 'option_value TEXT, supporting_document_id INTEGER UNIQUE, deleted TEXT, updated TEXT)',
      "INSERT INTO Files VALUES (1, 'encrypted')",
      "INSERT INTO DatabaseStorageDirEntries (id, parent_id, file_id, type, name) VALUES
       (100, NULL, NULL, 'folder', ''), (200, NULL, NULL, 'folder', ''),
       (201, 200, 1, 'file', 'original.PDF')",
      'INSERT INTO DatabaseStorages VALUES (1, 100), (99, 200)',
      'INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (10, 20, 1)',
      'INSERT INTO Projects VALUES (20, NULL)',
      'INSERT INTO Musicians VALUES (10, NULL)',
    ] as $sql) {
      $this->connection->executeStatement($sql);
    }
    $this->service = new ParticipantFileRepairService(
      $this->connection,
      20,
      static fn(array $f) => ['Documents'],
      fn(int $rootId, array $folders, string $fileName, int $fileId, ?int $moveEntryId): int => $this->createFileEntry(
        $rootId,
        $folders,
        $fileName,
        $fileId,
        $moveEntryId,
      ),
    );
  }

  /** {@inheritdoc} */
  protected function tearDown(): void
  {
    $this->connection->close();
  }

  /** @return array */
  private function snapshot(): array
  {
    $result = [];
    foreach ([
      'DatabaseStorageDirEntries', 'EncryptedFileOwners', 'ProjectParticipantFieldsData',
      'Invoices', 'SepaDebitMandates', 'CompositePayments', 'DonationReceipts',
    ] as $table) {
      $result[$table] = $this->connection->fetchAllAssociative('SELECT * FROM ' . $table);
    }
    return $result;
  }

  /**
   * Create a test directory entry using SQL in the isolated unit fixture.
   *
   * @param int $rootId
   * @param array $folders
   * @param string $fileName
   * @param int $fileId
   * @param null|int $moveEntryId
   *
   * @return int
   */
  private function createFileEntry(int $rootId, array $folders, string $fileName, int $fileId, ?int $moveEntryId): int
  {
    $parentId = $rootId;
    foreach ($folders as $name) {
      $folder = $this->connection->fetchAssociative(
        'SELECT id, type FROM DatabaseStorageDirEntries WHERE parent_id = ? AND name = ?',
        [$parentId, $name],
      );
      if ($folder !== false) {
        if ($folder['type'] !== 'folder') {
          throw new RuntimeException('Unexpected non-folder in test fixture.');
        }
        $parentId = (int)$folder['id'];
        continue;
      }
      $this->connection->insert('DatabaseStorageDirEntries', [
        'parent_id' => $parentId, 'name' => $name, 'type' => 'folder',
      ]);
      $parentId = (int)$this->connection->lastInsertId();
    }
    if ($moveEntryId !== null) {
      $this->connection->update('DatabaseStorageDirEntries', ['parent_id' => $parentId], ['id' => $moveEntryId]);
      return $moveEntryId;
    }
    $this->connection->insert('DatabaseStorageDirEntries', [
      'parent_id' => $parentId, 'name' => $fileName, 'type' => 'file', 'file_id' => $fileId,
    ]);
    return (int)$this->connection->lastInsertId();
  }

  /** @return void */
  public function testInvoiceOwnershipAndLinkAreIdempotentAndSourceIsPreserved(): void
  {
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)');
    $this->connection->executeStatement("UPDATE ProjectParticipants SET participation_status = 'associated'");
    $report = $this->service->repair();
    self::assertSame(2, $report['summary']['applied']);
    self::assertSame(1, $report['summary']['deferred']);
    self::assertSame([], $report['remaining']['findings']);
    self::assertSame(1, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM EncryptedFileOwners'));
    self::assertSame(201, (int)$this->connection->fetchOne('SELECT written_invoice_id FROM Invoices'));
    self::assertSame(200, (int)$this->connection->fetchOne('SELECT parent_id FROM DatabaseStorageDirEntries WHERE id = 201'));
    self::assertSame('original.PDF', $this->connection->fetchOne('SELECT name FROM DatabaseStorageDirEntries WHERE id > 201 AND type = ?', ['file']));
    $snapshot = $this->snapshot();
    self::assertSame([], $this->service->repair()['repairs']);
    self::assertSame($snapshot, $this->snapshot());
  }

  /** @return void */
  public function testPrivateFieldLinkUpdatesReferenceWithoutGrantingOwner(): void
  {
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type) VALUES (1, 'db-file')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '201', NULL, NULL, NULL)");
    $report = $this->service->repair();
    self::assertSame(['applied' => 1], $report['summary']);
    self::assertSame(0, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM EncryptedFileOwners'));
    self::assertNotSame('201', $this->connection->fetchOne('SELECT option_value FROM ProjectParticipantFieldsData'));
    self::assertSame(1, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE id = 201'));
    self::assertSame([], $this->service->repair()['repairs']);
  }

  /** @return void */
  public function testMultipleReferencesAreRepairedFromSameLockedSnapshot(): void
  {
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)');
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type, participant_access) VALUES (1, 'receivables', 'read')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '12.34', 201, NULL, NULL)");
    $report = $this->service->repair();
    self::assertNotSame(201, (int)$this->connection->fetchOne('SELECT supporting_document_id FROM ProjectParticipantFieldsData'));
    self::assertSame(201, (int)$this->connection->fetchOne('SELECT written_invoice_id FROM Invoices'));
    self::assertSame(2, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE file_id = 1'));
    self::assertSame(1, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM EncryptedFileOwners'));
    self::assertSame([], $report['remaining']['findings']);
    self::assertSame([], $this->service->repair()['repairs']);
  }

  /** @return void */
  public function testDryRunDoesNotMutateAnything(): void
  {
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)');
    $before = $this->snapshot();
    $report = $this->service->repair(true);
    self::assertSame(2, $report['summary']['planned']);
    self::assertSame($before, $this->snapshot());
    self::assertFalse($this->connection->isTransactionActive());
  }

  /** @return void */
  public function testOrphanWithOneTargetIsMovedAndPreviewIsReadOnly(): void
  {
    $this->connection->executeStatement('DELETE FROM DatabaseStorages WHERE id = 99');
    $this->connection->executeStatement("INSERT INTO SepaDebitMandates VALUES (1, 10, 20, 201, '2000-01-01')");
    $before = $this->snapshot();
    $preview = $this->service->repair(true);
    $moves = array_values(array_filter($preview['repairs'], static fn(array $r) => ($r['action'] ?? null) === 'move'));
    self::assertCount(1, $moves);
    self::assertSame($before, $this->snapshot());

    $report = $this->service->repair();
    $moves = array_values(array_filter($report['repairs'], static fn(array $r) => ($r['action'] ?? null) === 'move'));
    self::assertCount(1, $moves);
    self::assertSame(201, $moves[0]['participant_entry_id']);
    self::assertSame(201, (int)$this->connection->fetchOne('SELECT written_mandate_id FROM SepaDebitMandates'));
    self::assertSame(1, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE file_id = 1'));
    self::assertSame(
      'Documents',
      $this->connection->fetchOne(
        'SELECT parent.name FROM DatabaseStorageDirEntries entry JOIN DatabaseStorageDirEntries parent ON parent.id = entry.parent_id WHERE entry.id = 201',
      ),
    );
    self::assertSame([], $report['remaining']['findings']);
    self::assertSame([], $this->service->repair()['repairs']);
  }

  /** @return void */
  public function testOrphanWithMultipleTargetsIsNotMoved(): void
  {
    $this->connection->executeStatement('DELETE FROM DatabaseStorages WHERE id = 99');
    $this->connection->executeStatement('INSERT INTO Projects VALUES (21, NULL)');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries (id, type, name) VALUES (400, 'folder', '')");
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (2, 400)');
    $this->connection->executeStatement('INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (10, 21, 2)');
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL), (2, 10, 21, 201, NULL)');
    $report = $this->service->repair();
    self::assertSame(200, (int)$this->connection->fetchOne('SELECT parent_id FROM DatabaseStorageDirEntries WHERE id = 201'));
    self::assertSame(3, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE file_id = 1'));
    self::assertSame([], $report['remaining']['findings']);
  }

  /** @return void */
  public function testOrphanWithSeveralReferencesToOneTargetIsMoved(): void
  {
    $this->connection->executeStatement('DELETE FROM DatabaseStorages WHERE id = 99');
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)');
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type, participant_access) VALUES (1, 'receivables', 'read')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '12.34', 201, NULL, NULL)");
    $report = $this->service->repair();
    self::assertNotSame(200, (int)$this->connection->fetchOne('SELECT parent_id FROM DatabaseStorageDirEntries WHERE id = 201'));
    self::assertSame(201, (int)$this->connection->fetchOne('SELECT supporting_document_id FROM ProjectParticipantFieldsData'));
    self::assertSame(201, (int)$this->connection->fetchOne('SELECT written_invoice_id FROM Invoices'));
    self::assertSame(1, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE file_id = 1'));
    self::assertSame([], $report['remaining']['findings']);
  }

  /** @return void */
  public function testOrphanWithDifferentFoldersForSameParticipantIsNotMoved(): void
  {
    $this->connection->executeStatement('DELETE FROM DatabaseStorages WHERE id = 99');
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)');
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type, participant_access) VALUES (1, 'receivables', 'read')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '12.34', 201, NULL, NULL)");
    $service = new ParticipantFileRepairService(
      $this->connection,
      20,
      static fn(array $finding): array => [$finding['source'] === 'invoice' ? 'Invoices' : 'Fields'],
      fn(int $rootId, array $folders, string $fileName, int $fileId, ?int $moveEntryId): int => $this->createFileEntry(
        $rootId,
        $folders,
        $fileName,
        $fileId,
        $moveEntryId,
      ),
    );
    $report = $service->repair();
    self::assertSame(200, (int)$this->connection->fetchOne('SELECT parent_id FROM DatabaseStorageDirEntries WHERE id = 201'));
    self::assertSame(3, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE file_id = 1'));
    self::assertSame([], $report['remaining']['findings']);
  }

  /** @return void */
  public function testOrphanMoveRollsBackOnLaterFailure(): void
  {
    $this->connection->executeStatement('DELETE FROM DatabaseStorages WHERE id = 99');
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)');
    $this->connection->executeStatement("CREATE TRIGGER fail_owner BEFORE INSERT ON EncryptedFileOwners BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
    $before = $this->snapshot();
    try {
      $this->service->repair();
      self::fail('Expected the ownership write to fail.');
    } catch (DBALException $exception) {
      self::assertStringContainsString('injected failure', $exception->getMessage());
    }
    self::assertSame($before, $this->snapshot());
  }

  /** @return void */
  public function testStandaloneOrphanFileIsMoved(): void
  {
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = NULL WHERE id = 201');
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)');
    $report = $this->service->repair();
    self::assertNotNull($this->connection->fetchOne('SELECT parent_id FROM DatabaseStorageDirEntries WHERE id = 201'));
    self::assertSame(1, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE file_id = 1'));
    self::assertSame([], $report['remaining']['findings']);
  }

  /** @return void */
  public function testUnrepairableSharedReferencePreventsMovingOrphan(): void
  {
    $this->connection->executeStatement('DELETE FROM DatabaseStorages WHERE id = 99');
    $this->connection->executeStatement("INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL), (2, 10, 20, 201, '2000-01-01')");
    $this->service->repair();
    self::assertSame(200, (int)$this->connection->fetchOne('SELECT parent_id FROM DatabaseStorageDirEntries WHERE id = 201'));
    self::assertSame(2, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE file_id = 1'));
  }

  /** @return iterable */
  public static function otherDocuments(): iterable
  {
    yield 'composite payment' => ['composite-payment'];
    yield 'donation receipt' => ['donation-receipt'];
  }

  /**
   * @param string $source
   *
   * @return void
   */
  #[Attributes\DataProvider('otherDocuments')]
  public function testOtherFinancialDocumentsAreRepaired(string $source): void
  {
    $this->connection->executeStatement(
      'INSERT INTO CompositePayments VALUES (1, 10, 20, ?)',
      [$source === 'composite-payment' ? 201 : null],
    );
    if ($source === 'donation-receipt') {
      $this->connection->executeStatement('INSERT INTO DonationReceipts VALUES (1, 1, 201, NULL)');
    }
    $report = $this->service->repair();
    self::assertSame([], $report['remaining']['findings']);
    self::assertSame(2, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE file_id = 1'));
    if ($source === 'donation-receipt') {
      self::assertSame(201, (int)$this->connection->fetchOne('SELECT supporting_document_id FROM DonationReceipts'));
    } else {
      self::assertNotSame(201, (int)$this->connection->fetchOne('SELECT supporting_document_id FROM CompositePayments'));
    }
    $snapshot = $this->snapshot();
    self::assertSame([], $this->service->repair()['repairs']);
    self::assertSame($snapshot, $this->snapshot());
  }

  /** @return void */
  public function testSupportReferenceConflictIsReportedInPreviewAndApply(): void
  {
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries (id, parent_id, file_id, type, name) VALUES
      (210, NULL, NULL, 'folder', ''), (202, 210, 1, 'file', 'original.PDF')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type, participant_access) VALUES (1, 'receivables', 'read')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES
      (1, 10, 20, X'01', '1', 201, NULL, NULL), (1, 10, 20, X'02', '2', 202, NULL, NULL)");
    $snapshot = $this->snapshot();
    $preview = $this->service->repair(true);
    self::assertSame(1, $preview['summary']['blocked']);
    self::assertSame($snapshot, $this->snapshot());
    $report = $this->service->repair();
    self::assertSame(1, $report['summary']['blocked']);
    $blocked = array_values(array_filter($report['repairs'], static fn(array $r) => $r['status'] === 'blocked'));
    self::assertSame('destination-reference-conflict', $blocked[0]['reason']);
    self::assertSame(202, (int)$this->connection->fetchOne("SELECT supporting_document_id FROM ProjectParticipantFieldsData WHERE option_key = X'02'"));
    self::assertSame(2, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE id IN (201, 202)'));
    self::assertSame(1, $this->service->repair()['summary']['blocked']);
    $retry = new ParticipantFileRepairService(
      $this->connection,
      20,
      static fn(array $f) => ['OtherDocuments'],
      fn(int $rootId, array $folders, string $fileName, int $fileId, ?int $moveEntryId): int => $this->createFileEntry(
        $rootId,
        $folders,
        $fileName,
        $fileId,
        $moveEntryId,
      ),
    );
    self::assertSame(1, $retry->repair()['summary']['applied']);
    self::assertSame(202, (int)$this->connection->fetchOne("SELECT supporting_document_id FROM ProjectParticipantFieldsData WHERE option_key = X'02'"));
    self::assertNotSame(210, (int)$this->connection->fetchOne('SELECT parent_id FROM DatabaseStorageDirEntries WHERE id = 202'));
  }

  /** @return iterable */
  public static function conflicts(): iterable
  {
    yield 'folder is a file' => [
      "INSERT INTO DatabaseStorageDirEntries (parent_id, type, name, file_id) VALUES (100, 'file', 'Documents', 2)",
    ];
    yield 'file name is a folder' => [
      "INSERT INTO DatabaseStorageDirEntries (id, parent_id, type, name) VALUES (300, 100, 'folder', 'Documents'), (301, 300, 'folder', 'original.PDF')",
    ];
    yield 'different file' => [
      "INSERT INTO DatabaseStorageDirEntries (id, parent_id, type, name, file_id) VALUES (300, 100, 'folder', 'Documents', NULL), (301, 300, 'file', 'original.PDF', 2)",
    ];
  }

  /**
   * @param string $sql
   *
   * @return void
   */
  #[Attributes\DataProvider('conflicts')]
  public function testConflictIsReportedWithoutReplacingEntry(string $sql): void
  {
    $this->connection->executeStatement("INSERT INTO Files VALUES (2, 'encrypted')");
    $this->connection->executeStatement($sql);
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)');
    $entries = $this->connection->fetchAllAssociative('SELECT * FROM DatabaseStorageDirEntries');
    $report = $this->service->repair();
    self::assertSame(1, $report['summary']['blocked']);
    self::assertSame('destination-name-conflict', $report['repairs'][0]['reason']);
    self::assertSame($entries, $this->connection->fetchAllAssociative('SELECT * FROM DatabaseStorageDirEntries'));
    self::assertSame(1, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM EncryptedFileOwners'));
  }

  /** @return void */
  public function testUnexpectedFailureRollsBackAllWrites(): void
  {
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)');
    $this->connection->executeStatement("CREATE TRIGGER fail_owner BEFORE INSERT ON EncryptedFileOwners BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
    $before = $this->snapshot();
    try {
      $this->service->repair();
      self::fail('Expected the ownership write to fail.');
    } catch (DBALException $exception) {
      self::assertStringContainsString('injected failure', $exception->getMessage());
    }
    self::assertSame($before, $this->snapshot());
    self::assertFalse($this->connection->isTransactionActive());
  }

  /** @return iterable */
  public static function ineligibleDocuments(): iterable
  {
    yield 'deleted source' => ["UPDATE Invoices SET deleted = '2000-01-01'"];
    yield 'deleted musician' => ["UPDATE Musicians SET deleted = '2000-01-01'"];
    yield 'deleted project' => ["UPDATE Projects SET deleted = '2000-01-01'"];
    yield 'deleted participant' => ["UPDATE ProjectParticipants SET deleted = '2000-01-01'"];
    yield 'missing root' => ['UPDATE ProjectParticipants SET database_documents_id = NULL'];
    yield 'shared root' => ['INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (11, 20, 1)'];
    yield 'broken source' => ['UPDATE DatabaseStorageDirEntries SET parent_id = 999 WHERE id = 201'];
  }

  /**
   * @param string $sql
   *
   * @return void
   */
  #[Attributes\DataProvider('ineligibleDocuments')]
  public function testIneligibleDocumentsDoNotChange(string $sql): void
  {
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)');
    $this->connection->executeStatement($sql);
    $before = $this->snapshot();
    self::assertSame([], $this->service->repair()['repairs']);
    self::assertSame($before, $this->snapshot());
  }

  /** @return void */
  public function testCallerTransactionIsNotCommittedOrRolledBack(): void
  {
    $this->connection->beginTransaction();
    try {
      $this->service->repair();
      self::fail('Expected a transaction ownership error.');
    } catch (RuntimeException $exception) {
      self::assertStringContainsString('own transaction', $exception->getMessage());
    }
    self::assertTrue($this->connection->isTransactionActive());
    $this->connection->rollBack();
  }

  /** @return void */
  public function testClubMandateRepairPreservesExistingCrossProjectLinks(): void
  {
    $this->connection->executeStatement('INSERT INTO SepaDebitMandates VALUES (1, 10, 20, 201, NULL)');
    $this->connection->executeStatement('INSERT INTO Projects VALUES (21, NULL)');
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (2, 400)');
    $this->connection->executeStatement('INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (10, 21, 2)');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries (id, parent_id, file_id, type, name) VALUES (400, NULL, NULL, 'folder', ''), (401, 400, 1, 'file', 'copy.pdf')");
    $report = $this->service->repair();
    self::assertNotSame(201, (int)$this->connection->fetchOne('SELECT written_mandate_id FROM SepaDebitMandates'));
    self::assertSame(400, (int)$this->connection->fetchOne('SELECT parent_id FROM DatabaseStorageDirEntries WHERE id = 401'));
    self::assertSame(200, (int)$this->connection->fetchOne('SELECT parent_id FROM DatabaseStorageDirEntries WHERE id = 201'));
    self::assertSame([], $report['remaining']['findings']);
    self::assertSame([], $this->service->repair()['repairs']);
  }

  /** @return void */
  public function testRevokedMandateDocumentIsRepairedFromOrphanTree(): void
  {
    $this->connection->executeStatement(
      "INSERT INTO SepaDebitMandates VALUES (1, 10, 20, 201, '2000-01-01')",
    );
    $before = $this->snapshot();
    $report = $this->service->repair();

    self::assertSame(['applied' => 2, 'deferred' => 1], $report['summary']);
    self::assertSame([], $report['remaining']['findings']);
    $relinkedEntryId = (int)$this->connection->fetchOne('SELECT written_mandate_id FROM SepaDebitMandates');
    self::assertNotSame(201, $relinkedEntryId);
    self::assertSame('2000-01-01', $this->connection->fetchOne('SELECT deleted FROM SepaDebitMandates'));
    self::assertSame(
      1,
      (int)$this->connection->fetchOne(
        'SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE id = ? AND file_id = 1 AND type = ?',
        [$relinkedEntryId, 'file'],
      ),
    );
    self::assertSame([], $this->service->repair()['repairs']);
    self::assertNotSame($before, $this->snapshot());
  }
}
