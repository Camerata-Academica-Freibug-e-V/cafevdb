<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use OCA\CAFEVDB\Service\ParticipantFileAuditService;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;

/** Run the audit's actual metadata queries against an isolated database. */
#[Attributes\CoversClass(ParticipantFileAuditService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\ConsoleLogger::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\DeprecationLogger::class)]
class ParticipantFileAuditServiceTest extends TestCase
{
  private Connection $connection;
  private ParticipantFileAuditService $service;

  /** {@inheritdoc} */
  protected function setUp(): void
  {
    $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $this->service = new ParticipantFileAuditService($this->connection, 20);
    foreach ([
      'CREATE TABLE Files (id INTEGER PRIMARY KEY, type TEXT)',
      'CREATE TABLE EncryptedFileOwners (encrypted_file_id INTEGER, musician_id INTEGER)',
      'CREATE TABLE DatabaseStorageDirEntries (id INTEGER PRIMARY KEY, parent_id INTEGER, file_id INTEGER, type TEXT)',
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
      "CREATE TABLE ProjectParticipantFields (id INTEGER PRIMARY KEY, data_type TEXT, project_id INTEGER DEFAULT 20, deleted TEXT, participant_access TEXT DEFAULT 'none', participation_context TEXT DEFAULT 'unrestricted')",
      'CREATE TABLE ProjectParticipantFieldsData (field_id INTEGER, musician_id INTEGER, project_id INTEGER, option_key BLOB, option_value TEXT, supporting_document_id INTEGER, deleted TEXT)',
      "INSERT INTO Files VALUES (1, 'encrypted')",
      'INSERT INTO EncryptedFileOwners VALUES (1, 10)',
      "INSERT INTO DatabaseStorageDirEntries VALUES (100, NULL, NULL, 'folder'), (101, 100, NULL, 'folder'), (102, 101, 1, 'file')",
      'INSERT INTO DatabaseStorages VALUES (1, 100)',
      'INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (10, 20, 1)',
      'INSERT INTO Projects VALUES (20, NULL)',
      'INSERT INTO Musicians VALUES (10, NULL)',
    ] as $sql) {
      $this->connection->executeStatement($sql);
    }
  }

  /** {@inheritdoc} */
  protected function tearDown(): void
  {
    $this->connection->close();
  }

  /** @return iterable */
  public static function recognizedDocuments(): iterable
  {
    yield 'mandate' => ['INSERT INTO SepaDebitMandates VALUES (1, 10, 20, 102, NULL)'];
    yield 'invoice' => ['INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL)'];
    yield 'composite payment' => ['INSERT INTO CompositePayments VALUES (1, 10, 20, 102)'];
    yield 'donation receipt' => [
      'INSERT INTO CompositePayments VALUES (1, 10, 20, NULL)',
      'INSERT INTO DonationReceipts VALUES (1, 1, 102, NULL)',
    ];
    yield 'uploaded participant field' => [
      "INSERT INTO ProjectParticipantFields (id, data_type) VALUES (1, 'db-file')",
      "INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '102', NULL, NULL)",
    ];
    yield 'insurance supporting document' => [
      "INSERT INTO ProjectParticipantFields (id, data_type) VALUES (1, 'receivables')",
      "INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '19.99', 102, NULL)",
    ];
    yield 'retained soft-deleted invoice' => ["INSERT INTO Invoices VALUES (1, 10, 20, 102, '2000-01-01')"];
  }

  /**
   * @param string $sql
   * @param null|string $extraSql
   *
   * @return void
   */
  #[Attributes\DataProvider('recognizedDocuments')]
  public function testRecognizedDocumentsAreReadOnly(string $sql, ?string $extraSql = null): void
  {
    $this->connection->executeStatement($sql);
    if ($extraSql !== null) {
      $this->connection->executeStatement($extraSql);
    }
    $before = $this->snapshot();
    $report = $this->service->audit();
    self::assertSame([], $report['findings']);
    self::assertSame(1, $report['summary']['document_references']);
    self::assertSame($before, $this->snapshot());
  }

  /** @return void */
  public function testUnrecognizedGrantIsNotJustifiedByParticipantLinkOrTextField(): void
  {
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type) VALUES (1, 'text')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '102', NULL, NULL)");
    self::assertSame(
      ['unrecognized-owned-file', 'unrelated-participant-link'],
      $this->codes($this->service->audit()),
    );
  }

  /** @return iterable */
  public static function readableSupportingFields(): iterable
  {
    yield 'unrestricted read' => ['read', 'unrestricted'];
    yield 'unrestricted read-write' => ['read-write', 'unrestricted'];
    yield 'participants read' => ['read', 'participants'];
    yield 'participants read-write' => ['read-write', 'participants'];
  }

  /** @param string $access
   * @param string $context
   */
  #[Attributes\DataProvider('readableSupportingFields')]
  public function testMissingOwnershipForReadableSupportingDocumentIsRepairable(string $access, string $context): void
  {
    $this->createMissingSupportGrant();
    $this->connection->executeStatement(
      'UPDATE ProjectParticipantFields SET participant_access = ?, participation_context = ?',
      [$access, $context],
    );
    $before = $this->snapshot();
    $report = $this->service->audit();
    self::assertSame(['missing-ownership', 'non-owner-participant-link'], $this->codes($report));
    foreach ($report['findings'] as $finding) {
      self::assertTrue($finding['repairable']);
      self::assertSame(['add-ownership', 'reconcile-encryption-seal'], $finding['repairsteps']);
    }
    self::assertSame($before, $this->snapshot());
  }

  /** @return iterable */
  public static function unsafeSupportGrants(): iterable
  {
    yield 'access disabled' => ["UPDATE ProjectParticipantFields SET participant_access = 'none'"];
    yield 'associates field' => ["UPDATE ProjectParticipantFields SET participation_context = 'associates'"];
    yield 'deleted datum' => ["UPDATE ProjectParticipantFieldsData SET deleted = '2000-01-01'"];
    yield 'deleted field' => ["UPDATE ProjectParticipantFields SET deleted = '2000-01-01'"];
    yield 'deleted participant' => ["UPDATE ProjectParticipants SET deleted = '2000-01-01'"];
    yield 'deleted project' => ["UPDATE Projects SET deleted = '2000-01-01'"];
    yield 'deleted musician' => ["UPDATE Musicians SET deleted = '2000-01-01'"];
    yield 'field from another project' => ['UPDATE ProjectParticipantFields SET project_id = 21'];
    yield 'non-encrypted file' => ["UPDATE Files SET type = 'plain'"];
    yield 'missing storage' => ['UPDATE ProjectParticipants SET database_documents_id = NULL'];
    yield 'associated participant' => ["UPDATE ProjectParticipants SET participation_status = 'associated'"];
    yield 'broken directory' => ['UPDATE DatabaseStorageDirEntries SET parent_id = 999 WHERE id = 101'];
  }

  /** @param string $sql */
  #[Attributes\DataProvider('unsafeSupportGrants')]
  public function testAmbiguousSupportGrantsAreNotMarkedRepairable(string $sql): void
  {
    $this->createMissingSupportGrant();
    $this->connection->executeStatement("UPDATE ProjectParticipantFields SET participation_context = 'participants'");
    $this->connection->executeStatement($sql);
    foreach ($this->service->audit()['findings'] as $finding) {
      self::assertFalse($finding['repairable']);
      self::assertArrayNotHasKey('repairsteps', $finding);
    }
  }

  /** @return void */
  public function testParticipantContextAccountsForNonInstrumentRoles(): void
  {
    $this->createMissingSupportGrant();
    $this->connection->executeStatement("UPDATE ProjectParticipantFields SET participation_context = 'participants'");
    $this->connection->executeStatement("INSERT INTO InstrumentFamilies VALUES (1, 'not an instrument')");
    $this->connection->executeStatement('INSERT INTO instrument_instrument_family VALUES (1, 1)');
    $this->connection->executeStatement('INSERT INTO ProjectInstruments VALUES (20, 10, 1)');
    self::assertFalse($this->service->audit()['findings'][0]['repairable']);
    $this->connection->executeStatement('INSERT INTO ProjectInstruments VALUES (20, 10, 2)');
    self::assertTrue($this->service->audit()['findings'][0]['repairable']);
  }

  /** @return void */
  public function testOtherLinksAndOtherReferencesAreNotMarkedRepairable(): void
  {
    $this->createMissingSupportGrant();
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (103, 101, 1, 'file')");
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL)');
    $findings = $this->service->audit()['findings'];
    $byCode = [];
    foreach ($findings as $finding) {
      $byCode[$finding['code'] . ':' . ($finding['source'] ?? $finding['entry_id'])] = $finding;
    }
    self::assertTrue($byCode['missing-ownership:participant-field-support']['repairable']);
    self::assertFalse($byCode['missing-ownership:invoice']['repairable']);
    self::assertTrue($byCode['non-owner-participant-link:102']['repairable']);
    self::assertFalse($byCode['non-owner-participant-link:103']['repairable']);
  }

  /** @return void */
  public function testSharedParticipantRootIsNotAnAutomaticRepairCandidate(): void
  {
    $this->createMissingSupportGrant();
    $this->connection->executeStatement(
      'INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (11, 20, 2)',
    );
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (2, 100)');
    foreach ($this->service->audit()['findings'] as $finding) {
      self::assertFalse($finding['repairable']);
    }
  }

  /** @return void */
  private function createMissingSupportGrant(): void
  {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement(
      "INSERT INTO ProjectParticipantFields (id, data_type, participant_access) VALUES (1, 'receivables', 'read')",
    );
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '19.99', 102, NULL)");
  }

  /** @return void */
  public function testOptionalNamesDistinguishTheFileFromItsParticipantLink(): void
  {
    $this->connection->executeStatement('ALTER TABLE Files ADD COLUMN file_name TEXT');
    $this->connection->executeStatement('ALTER TABLE DatabaseStorageDirEntries ADD COLUMN name TEXT');
    $this->connection->executeStatement("UPDATE Files SET file_name = 'original.pdf' WHERE id = 1");
    $this->connection->executeStatement("UPDATE DatabaseStorageDirEntries SET name = 'SupportingDocuments' WHERE id = 101");
    $this->connection->executeStatement("UPDATE DatabaseStorageDirEntries SET name = 'linked.pdf' WHERE id = 102");
    $this->connection->executeStatement(
      "INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder', ''), (201, 200, 1, 'file', 'other.pdf')",
    );
    $mountPath = '/ORCHESTRA/projects/2026/Project/participants/member/Documents';
    $before = $this->snapshot();
    $anonymous = $this->service->audit();
    $named = $this->service->audit(true, [100 => $mountPath, 200 => '/ORCHESTRA/other/Documents']);
    self::assertSame($anonymous['summary'], $named['summary']);
    foreach ($named['findings'] as $index => $finding) {
      self::assertSame('original.pdf', $finding['file_name']);
      unset($finding['file_name']);
      if (isset($finding['entry_id'])) {
        self::assertSame('linked.pdf', $finding['entry_name']);
        self::assertSame($mountPath . '/SupportingDocuments/linked.pdf', $finding['entry_path']);
        unset($finding['entry_name'], $finding['entry_path']);
      } else {
        self::assertSame([
          ['entry_id' => 102, 'path' => $mountPath . '/SupportingDocuments/linked.pdf'],
          ['entry_id' => 201, 'path' => '/ORCHESTRA/other/Documents/other.pdf'],
        ], $finding['file_paths']);
        unset($finding['file_paths']);
      }
      self::assertSame($anonymous['findings'][$index], $finding);
    }
    self::assertSame($before, $this->snapshot());
  }

  /** @return void */
  public function testOptionalNamesRemainNullForMissingRecords(): void
  {
    $this->connection->executeStatement('ALTER TABLE Files ADD COLUMN file_name TEXT');
    $this->connection->executeStatement('ALTER TABLE DatabaseStorageDirEntries ADD COLUMN name TEXT');
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 999, NULL)');
    $findings = array_values(array_filter(
      $this->service->audit(true)['findings'],
      static fn(array $finding) => $finding['code'] === 'broken-document-reference',
    ));
    self::assertCount(1, $findings);
    self::assertNull($findings[0]['file_name']);
    self::assertNull($findings[0]['entry_name']);
    self::assertNull($findings[0]['entry_path']);
  }

  /** @return iterable */
  public static function unresolvedPaths(): iterable
  {
    yield 'missing parent' => ['UPDATE DatabaseStorageDirEntries SET parent_id = 999 WHERE id = 101'];
    yield 'directory cycle' => ['UPDATE DatabaseStorageDirEntries SET parent_id = 101 WHERE id = 100'];
    yield 'non-folder parent' => ["UPDATE DatabaseStorageDirEntries SET type = 'file' WHERE id = 101"];
    yield 'missing name' => ['UPDATE DatabaseStorageDirEntries SET name = NULL WHERE id = 101'];
    yield 'unknown mount' => [null];
  }

  /** @param null|string $sql */
  #[Attributes\DataProvider('unresolvedPaths')]
  public function testIncompletePathsAreNotPresentedAsFullPaths(?string $sql): void
  {
    $this->connection->executeStatement('ALTER TABLE Files ADD COLUMN file_name TEXT');
    $this->connection->executeStatement('ALTER TABLE DatabaseStorageDirEntries ADD COLUMN name TEXT');
    $this->connection->executeStatement("UPDATE DatabaseStorageDirEntries SET name = 'document'");
    if ($sql !== null) {
      $this->connection->executeStatement($sql);
    }
    $report = $this->service->audit(true, $sql === null ? [] : [100 => '/ORCHESTRA/Documents']);
    $findings = array_values(array_filter(
      $report['findings'],
      static fn(array $finding) => ($finding['entry_id'] ?? null) === 102,
    ));
    self::assertCount(1, $findings);
    self::assertNull($findings[0]['entry_path']);
  }

  /** @return void */
  public function testFinanceLinkDoesNotReplaceMissingParticipantLink(): void
  {
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 102');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder')");
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (2, 200)');
    $report = $this->service->audit();
    self::assertSame(['missing-participant-link'], $this->codes($report));
    self::assertSame(100, $report['findings'][0]['root_id']);
  }

  /** @return void */
  public function testOtherOwnersAndWrongProjectLinksAreReportedSeparately(): void
  {
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement('INSERT INTO EncryptedFileOwners VALUES (1, 11)');
    $this->connection->executeStatement('INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (10, 21, 2), (12, 22, 3)');
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (2, 200), (3, 300)');
    $this->connection->executeStatement(
      "INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder'), (201, 200, 1, 'file'),
                                                   (300, NULL, NULL, 'folder'), (301, 300, 1, 'file')",
    );
    self::assertSame(
      ['non-owner-participant-link', 'unexpected-owner', 'unrelated-participant-link'],
      $this->codes($this->service->audit()),
    );
  }

  /** @return void */
  public function testClubMandateLinksAreValidOnlyForTheSameOwner(): void
  {
    $this->connection->executeStatement('INSERT INTO SepaDebitMandates VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement('INSERT INTO EncryptedFileOwners VALUES (1, 12)');
    $this->connection->executeStatement('INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (10, 21, 2), (11, 22, 3), (12, 23, 4)');
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (2, 200), (3, 300), (4, 400)');
    $this->connection->executeStatement(
      "INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder'), (201, 200, 1, 'file'),
                                                   (300, NULL, NULL, 'folder'), (301, 300, 1, 'file'),
                                                   (400, NULL, NULL, 'folder'), (401, 400, 1, 'file')",
    );
    $report = $this->service->audit();
    self::assertSame(['non-owner-participant-link', 'unexpected-owner', 'unrelated-participant-link'], $this->codes($report));
    $links = array_column(array_filter($report['findings'], static fn(array $finding) => isset($finding['entry_id'])), 'entry_id');
    sort($links);
    self::assertSame([301, 401], $links);
  }

  /** @return void */
  public function testProjectSpecificMandateDoesNotJustifyOtherProjectLink(): void
  {
    $this->connection->executeStatement('INSERT INTO SepaDebitMandates VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement('INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (10, 21, 2)');
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (2, 200)');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder'), (201, 200, 1, 'file')");
    foreach ([0, 99] as $clubProjectId) {
      $service = new ParticipantFileAuditService($this->connection, $clubProjectId);
      self::assertSame(['unrelated-participant-link'], $this->codes($service->audit()));
    }
  }

  /** @return void */
  public function testClubMandateStillRequiresItsOwnParticipantLink(): void
  {
    $this->connection->executeStatement('INSERT INTO SepaDebitMandates VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement('INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (10, 21, 2)');
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (2, 200)');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder')");
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 102');
    self::assertSame(['missing-participant-link'], $this->codes($this->service->audit()));
  }

  /** @return void */
  public function testSharedFilesWithExplicitReferencesForBothParticipantsAreValid(): void
  {
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL), (2, 11, 21, 201, NULL)');
    $this->connection->executeStatement('INSERT INTO EncryptedFileOwners VALUES (1, 11)');
    $this->connection->executeStatement('INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (11, 21, 2)');
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (2, 200)');
    $this->connection->executeStatement(
      "INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder'), (201, 200, 1, 'file')",
    );
    self::assertSame([], $this->service->audit()['findings']);
  }

  /** @return void */
  public function testMissingOwnershipAndParticipantAreNotSilentlySkipped(): void
  {
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement('DELETE FROM ProjectParticipants');
    self::assertSame(
      ['missing-ownership', 'missing-participant', 'missing-participant-link'],
      $this->codes($this->service->audit()),
    );
  }

  /** @return void */
  public function testBrokenReferencesAndDirectoryCyclesAreReportedWithoutRecursing(): void
  {
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 999, NULL)');
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 101 WHERE id = 100');
    self::assertSame(
      ['broken-directory-tree', 'broken-document-reference', 'unrecognized-owned-file'],
      $this->codes($this->service->audit()),
    );
  }

  /** @return void */
  public function testMalformedFieldReferenceDoesNotLeakItsValue(): void
  {
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type) VALUES (1, 'db-file')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', 'private-value', NULL, NULL)");
    $report = $this->service->audit();
    self::assertContains('broken-document-reference', $this->codes($report));
    self::assertStringNotContainsString('private-value', json_encode($report, JSON_THROW_ON_ERROR));
  }

  /** @return void */
  public function testMissingTablesFailInsteadOfReturningACleanAudit(): void
  {
    $this->connection->executeStatement('DROP TABLE DonationReceipts');
    $this->expectException(\OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Exception::class);
    $this->service->audit();
  }

  /**
   * @param array $report
   *
   * @return array
   */
  private function codes(array $report): array
  {
    $codes = array_column($report['findings'], 'code');
    sort($codes);
    return $codes;
  }

  /** @return array */
  private function snapshot(): array
  {
    $snapshot = [];
    foreach ($this->connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $table) {
      $snapshot[$table] = $this->connection->fetchAllAssociative('SELECT * FROM ' . $this->connection->quoteIdentifier($table));
    }
    return $snapshot;
  }
}
