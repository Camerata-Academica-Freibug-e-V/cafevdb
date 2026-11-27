<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use LogicException;

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
      "CREATE TABLE ProjectParticipantFields (id INTEGER PRIMARY KEY, data_type TEXT, project_id INTEGER DEFAULT 20, deleted TEXT, "
      . "participant_access TEXT DEFAULT 'none', participation_context TEXT DEFAULT 'unrestricted', "
      . "multiplicity TEXT DEFAULT 'simple', name TEXT DEFAULT 'private field')",
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

  /** @return void */
  public function testEncryptionDiagnosticsInspectEachOwnerWithoutDecryptingOrExposingSealData(): void
  {
    $this->connection->executeStatement('ALTER TABLE Musicians ADD COLUMN user_id_slug TEXT');
    $this->connection->executeStatement("UPDATE Musicians SET user_id_slug = 'alice' WHERE id = 10");
    $this->connection->executeStatement("INSERT INTO Musicians (id, user_id_slug) VALUES (11, 'bob')");
    $this->connection->executeStatement('INSERT INTO EncryptedFileOwners VALUES (1, 11)');
    $this->connection->executeStatement('CREATE TABLE FileData (file_id INTEGER PRIMARY KEY, data BLOB)');
    $this->connection->executeStatement(
      'INSERT INTO FileData VALUES (1, CAST(? AS BLOB))',
      ["00000004|\0xyz|alice:secret-key;@board:other-key"],
    );
    $before = $this->snapshot();
    $report = $this->service->audit(userStatus: static fn(string $id) => [
      'user_exists' => true,
      'logged_in' => $id === 'alice',
      'public_key_present' => $id === 'alice',
      'private_key_present' => $id === 'alice',
    ]);
    self::assertSame([
      ['file_id' => 1, 'musician_id' => 10, 'user_id' => 'alice', 'ownership_granted' => true,
       'seal_status' => 'sealed', 'recipient_present' => true, 'user_exists' => true, 'logged_in' => true,
       'public_key_present' => true, 'private_key_present' => true],
      ['file_id' => 1, 'musician_id' => 11, 'user_id' => 'bob', 'ownership_granted' => true,
       'seal_status' => 'sealed', 'recipient_present' => false, 'user_exists' => true, 'logged_in' => false,
       'public_key_present' => false, 'private_key_present' => false],
    ], $report['encryption_diagnostics']);
    self::assertSame(1, $report['summary']['encryption_issues']);
    self::assertStringNotContainsString('secret-key', json_encode($report, JSON_THROW_ON_ERROR));
    self::assertStringNotContainsString('other-key', json_encode($report, JSON_THROW_ON_ERROR));
    self::assertSame($before, $this->snapshot());
  }

  /** @return iterable */
  public static function invalidSeals(): iterable
  {
    yield 'missing data' => [null, 'missing-data'];
    yield 'unsealed' => ['plaintext', 'unsealed-or-invalid'];
    yield 'truncated payload' => ['00000004|x', 'invalid-seal'];
    yield 'wrong separator' => ['00000001|x!alice:key', 'invalid-seal'];
    yield 'malformed recipient' => ['00000001|x|alice', 'invalid-seal'];
    yield 'empty key' => ['00000001|x|alice:', 'invalid-seal'];
  }

  /** @param null|string $data
   * @param string $expected
   *
   * @return void
   */
  #[Attributes\DataProvider('invalidSeals')]
  public function testUnverifiableSealsAreNotReportedAsAccessible(?string $data, string $expected): void
  {
    $this->connection->executeStatement('ALTER TABLE Musicians ADD COLUMN user_id_slug TEXT');
    $this->connection->executeStatement("UPDATE Musicians SET user_id_slug = 'alice' WHERE id = 10");
    $this->connection->executeStatement('CREATE TABLE FileData (file_id INTEGER PRIMARY KEY, data BLOB)');
    if ($data !== null) {
      $this->connection->executeStatement('INSERT INTO FileData VALUES (1, CAST(? AS BLOB))', [$data]);
    }
    $report = $this->service->audit(userStatus: static fn(string $id) => [
      'user_exists' => true, 'logged_in' => true, 'public_key_present' => true, 'private_key_present' => true,
    ]);
    self::assertSame($expected, $report['encryption_diagnostics'][0]['seal_status']);
    self::assertNull($report['encryption_diagnostics'][0]['recipient_present']);
    self::assertSame(1, $report['summary']['encryption_issues']);
  }

  /** @return void */
  public function testAuditIncludesRepairableMissingOwnerAndUnknownUserIdentity(): void
  {
    $this->createMissingSupportGrant();
    $this->connection->executeStatement('ALTER TABLE Musicians ADD COLUMN user_id_slug TEXT');
    $this->connection->executeStatement('CREATE TABLE FileData (file_id INTEGER PRIMARY KEY, data BLOB)');
    $this->connection->executeStatement("INSERT INTO FileData VALUES (1, CAST('00000001|x|alice:key' AS BLOB))");
    $report = $this->service->audit(userStatus: static function(string $id): array {
      throw new LogicException('A missing user ID must not be resolved.');
    });
    $diagnostic = $report['encryption_diagnostics'][0];
    self::assertFalse($diagnostic['ownership_granted']);
    self::assertSame('sealed', $diagnostic['seal_status']);
    self::assertNull($diagnostic['user_id']);
    self::assertNull($diagnostic['recipient_present']);
    self::assertNull($diagnostic['logged_in']);
    self::assertSame(1, $report['summary']['encryption_issues']);
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

  /** @return void */
  public function testFieldAssociationsCoverBothReferenceTypesAndSharedFiles(): void
  {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (103, 101, 1, 'file')");
    $this->connection->executeStatement(
      "INSERT INTO ProjectParticipantFields (id, data_type, multiplicity, participant_access, name) VALUES
       (1, 'receivables', 'recurring', 'read-write', 'private support'),
       (2, 'db-file', 'simple', 'read', 'private upload'),
       (3, 'text', 'simple', 'none', 'not a file')",
    );
    $this->connection->executeStatement(
      "INSERT INTO ProjectParticipantFieldsData VALUES
       (1, 10, 20, X'01', '103', 102, NULL),
       (2, 11, 21, X'02', '103', NULL, NULL),
       (3, 10, 20, X'03', '102', NULL, NULL)",
    );
    $before = $this->snapshot();
    $report = $this->service->audit();
    $finding = array_values(array_filter(
      $report['findings'],
      static fn(array $item) => $item['code'] === 'non-owner-participant-link' && $item['entry_id'] === 102,
    ))[0];
    self::assertSame([
      ['field_id' => 1, 'field_type' => 'receivables', 'multiplicity' => 'recurring', 'participant_access' => 'read-write',
       'relation' => 'supporting_document_id', 'entry_id' => 102, 'musician_id' => 10, 'project_id' => 20, 'option_key' => '01'],
      ['field_id' => 2, 'field_type' => 'db-file', 'multiplicity' => 'simple', 'participant_access' => 'read',
       'relation' => 'option_value', 'entry_id' => 103, 'musician_id' => 11, 'project_id' => 21, 'option_key' => '02'],
    ], $finding['participant_fields']);
    self::assertStringNotContainsString('private', json_encode($report, JSON_THROW_ON_ERROR));
    $named = $this->service->audit(includeFieldNames: true);
    $finding = array_values(array_filter(
      $named['findings'],
      static fn(array $item) => $item['code'] === 'non-owner-participant-link' && $item['entry_id'] === 102,
    ))[0];
    self::assertSame(['private support', 'private upload'], array_column($finding['participant_fields'], 'field_name'));
    self::assertSame($before, $this->snapshot());
  }

  /** @return void */
  public function testBrokenFieldReferencesIncludeMetadataWithoutLeakingMalformedValue(): void
  {
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type) VALUES (1, 'db-file')");
    $this->connection->executeStatement(
      "INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', 'private-value', NULL, NULL),
       (1, 10, 20, X'02', '999', NULL, NULL)",
    );
    $findings = array_values(array_filter(
      $this->service->audit()['findings'],
      static fn(array $item) => $item['code'] === 'broken-document-reference',
    ));
    self::assertCount(2, $findings);
    foreach ($findings as $finding) {
      self::assertSame(1, $finding['participant_fields'][0]['field_id']);
      self::assertSame($finding['entry_id'], $finding['participant_fields'][0]['entry_id']);
    }
    self::assertStringNotContainsString('private-value', json_encode($findings, JSON_THROW_ON_ERROR));
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
   *
   * @return void
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

  /**
   * @param string $sql
   *
   * @return void
   */
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

  /** @return iterable */
  public static function blockedRepairReasons(): iterable
  {
    yield 'deleted musician' => ["UPDATE Musicians SET deleted = '2000-01-01'", 'musician-deleted'];
    yield 'deleted project' => ["UPDATE Projects SET deleted = '2000-01-01'", 'project-deleted'];
    yield 'deleted participant' => ["UPDATE ProjectParticipants SET deleted = '2000-01-01'", 'participant-deleted'];
    yield 'deleted document' => ["UPDATE ProjectParticipantFieldsData SET deleted = '2000-01-01'", 'document-deleted'];
    yield 'deleted field' => ["UPDATE ProjectParticipantFields SET deleted = '2000-01-01'", 'field-deleted'];
    yield 'unreadable field' => ["UPDATE ProjectParticipantFields SET participant_access = 'none'", 'field-access-not-readable'];
    yield 'excluded context' => ["UPDATE ProjectParticipantFields SET participation_context = 'associates'", 'field-context-not-eligible'];
    yield 'field from another project' => ['UPDATE ProjectParticipantFields SET project_id = 21', 'field-project-mismatch'];
    yield 'non-encrypted file' => ["UPDATE Files SET type = 'plain'", 'file-not-encrypted'];
    yield 'shared root' => ['INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (11, 20, 1)', 'participant-storage-shared'];
  }

  /**
   * @param string $sql
   * @param string $reason
   *
   * @return void
   */
  #[Attributes\DataProvider('blockedRepairReasons')]
  public function testKnownBlockedRepairReasonsAreIncluded(string $sql, string $reason): void
  {
    $this->createMissingSupportGrant();
    $this->connection->executeStatement($sql);
    $before = $this->snapshot();
    $findings = array_values(array_filter(
      $this->service->audit()['findings'],
      static fn(array $finding) => $finding['code'] === 'missing-ownership',
    ));
    self::assertCount(1, $findings);
    self::assertFalse($findings[0]['repairable']);
    self::assertContains($reason, $findings[0]['repair_reasons']);
    self::assertArrayNotHasKey('repairsteps', $findings[0]);
    self::assertSame($before, $this->snapshot());
  }

  /** @return void */
  public function testDeletedMusicianReasonAppearsOnBothMandateFindings(): void
  {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement('INSERT INTO SepaDebitMandates VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement("UPDATE Musicians SET deleted = '2000-01-01'");
    $report = $this->service->audit();
    self::assertSame(['missing-ownership', 'non-owner-participant-link'], $this->codes($report));
    foreach ($report['findings'] as $finding) {
      self::assertFalse($finding['repairable']);
      self::assertSame(['musician-deleted'], $finding['repair_reasons']);
    }
  }

  /** @return void */
  public function testInvoiceMissingParticipantLinkIsRepairableForAssociatedParticipant(): void
  {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement("UPDATE ProjectParticipants SET participation_status = 'associated'");
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder')");
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 102');
    $report = $this->service->audit();
    $findings = array_values(array_filter(
      $report['findings'],
      static fn(array $finding) => in_array($finding['code'], ['missing-ownership', 'missing-participant-link'], true)
        && ($finding['source'] ?? null) === 'invoice',
    ));
    self::assertCount(2, $findings);
    foreach ($findings as $finding) {
      self::assertTrue($finding['repairable']);
      self::assertSame(['add-participant-link', 'add-ownership', 'reconcile-encryption-seal'], $finding['repairsteps']);
    }
  }

  /** @return void */
  public function testFieldBlockerIsReportedOnTheRelatedParticipantLink(): void
  {
    $this->createMissingSupportGrant();
    $this->connection->executeStatement("UPDATE ProjectParticipantFields SET participant_access = 'none'");
    $findings = array_values(array_filter(
      $this->service->audit()['findings'],
      static fn(array $finding) => $finding['code'] === 'non-owner-participant-link',
    ));
    self::assertCount(1, $findings);
    self::assertSame(['field-access-not-readable'], $findings[0]['repair_reasons']);
  }

  /** @return void */
  public function testPrivateDbFileFieldBlockerIsReportedOnTheRelatedParticipantLink(): void
  {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type, multiplicity) VALUES (1, 'db-file', 'parallel')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '102', NULL, NULL)");
    $before = $this->snapshot();
    $report = $this->service->audit();
    self::assertSame(['missing-ownership', 'non-owner-participant-link'], $this->codes($report));
    foreach ($report['findings'] as $finding) {
      self::assertFalse($finding['repairable']);
      self::assertSame(['field-access-not-readable'], $finding['repair_reasons']);
      self::assertArrayNotHasKey('repairsteps', $finding);
    }
    self::assertSame($before, $this->snapshot());
  }

  /** @return void */
  public function testDbFileBlockerIsNotAttributedWhenAnotherReferenceMayJustifyOwnership(): void
  {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type) VALUES (1, 'db-file')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '102', NULL, NULL)");
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement("UPDATE Musicians SET deleted = '2000-01-01'");
    $findings = array_values(array_filter(
      $this->service->audit()['findings'],
      static fn(array $finding) => $finding['code'] === 'non-owner-participant-link',
    ));
    self::assertCount(1, $findings);
    self::assertFalse($findings[0]['repairable']);
    self::assertSame(['musician-deleted'], $findings[0]['repair_reasons']);
  }

  /** @return void */
  public function testFieldBlockerIsNotAttributedToLinkWithAnotherClubMandateReference(): void
  {
    $this->createMissingSupportGrant();
    $this->connection->executeStatement("UPDATE ProjectParticipantFields SET project_id = 21, participant_access = 'none'");
    $this->connection->executeStatement('UPDATE ProjectParticipantFieldsData SET project_id = 21');
    $this->connection->executeStatement('UPDATE ProjectParticipants SET project_id = 21');
    $this->connection->executeStatement('INSERT INTO Projects VALUES (21, NULL)');
    $this->connection->executeStatement('INSERT INTO SepaDebitMandates VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement("UPDATE Musicians SET deleted = '2000-01-01'");
    $findings = array_values(array_filter(
      $this->service->audit()['findings'],
      static fn(array $finding) => $finding['code'] === 'non-owner-participant-link',
    ));
    self::assertCount(1, $findings);
    self::assertSame(['musician-deleted', 'club-mandate-source-link-missing'], $findings[0]['repair_reasons']);
  }

  /** @return void */
  public function testReasonsDoNotCarryOverBetweenFindings(): void
  {
    $this->connection->executeStatement("UPDATE Musicians SET deleted = '2000-01-01'");
    $this->connection->executeStatement("UPDATE Files SET type = 'plain'");
    $report = $this->service->audit();
    $findings = array_column($report['findings'], null, 'code');
    self::assertSame(['file-not-encrypted'], $findings['invalid-encrypted-file']['repair_reasons']);
    self::assertSame(['musician-deleted', 'file-not-encrypted'], $findings['unrelated-participant-link']['repair_reasons']);
  }

  /** @return void */
  public function testRepairableFindingDoesNotIncludeBlockedReasons(): void
  {
    $this->createMissingSupportGrant();
    foreach ($this->service->audit()['findings'] as $finding) {
      self::assertTrue($finding['repairable']);
      self::assertArrayNotHasKey('repair_reasons', $finding);
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
  public function testFinancialReferenceAlsoJustifiesOtherLinksInTheSameParticipantStorage(): void
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
    self::assertTrue($byCode['missing-ownership:invoice']['repairable']);
    self::assertTrue($byCode['non-owner-participant-link:102']['repairable']);
    self::assertTrue($byCode['non-owner-participant-link:103']['repairable']);
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

  /** @return iterable */
  public static function mandateScope(): iterable
  {
    yield 'club project' => [20, true];
    yield 'other project' => [99, false];
    yield 'unset club project' => [0, false];
  }

  /**
   * @param int $clubProjectId
   * @param bool $crossProject
   *
   * @return void
   */
  #[Attributes\DataProvider('mandateScope')]
  public function testMandateRepairCandidatesRespectProjectAndMusician(int $clubProjectId, bool $crossProject): void
  {
    $this->createMissingMandateGrant();
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL)');
    $before = $this->snapshot();
    $report = (new ParticipantFileAuditService($this->connection, $clubProjectId))->audit();
    $findings = [];
    foreach ($report['findings'] as $finding) {
      $findings[$finding['code'] . ':' . ($finding['source'] ?? $finding['entry_id'])] = $finding;
    }
    foreach (['missing-ownership:debit-mandate', 'non-owner-participant-link:102'] as $key) {
      self::assertTrue($findings[$key]['repairable']);
      self::assertSame(['add-ownership', 'reconcile-encryption-seal'], $findings[$key]['repairsteps']);
    }
    self::assertTrue($findings['missing-ownership:invoice']['repairable']);
    self::assertSame($crossProject, $findings['non-owner-participant-link:201']['repairable']);
    self::assertFalse($findings['non-owner-participant-link:301']['repairable']);
    self::assertSame($before, $this->snapshot());
  }

  /** @return void */
  public function testClubMandateCrossProjectLinkReportsMissingCanonicalSourceLink(): void
  {
    $this->createMissingMandateGrant();
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (400, NULL, NULL, 'folder')");
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 400 WHERE id = 102');
    $before = $this->snapshot();
    $findings = array_column(
      array_filter($this->service->audit()['findings'], static fn(array $f) => $f['code'] === 'non-owner-participant-link'),
      null,
      'entry_id',
    );
    self::assertFalse($findings[201]['repairable']);
    self::assertContains('club-mandate-source-link-missing', $findings[201]['repair_reasons']);
    self::assertArrayNotHasKey('repairsteps', $findings[201]);
    self::assertFalse($findings[301]['repairable']);
    self::assertNotContains('club-mandate-source-link-missing', $findings[301]['repair_reasons'] ?? []);
    self::assertSame($before, $this->snapshot());

    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 101 WHERE id = 102');
    $findings = array_column(
      array_filter($this->service->audit()['findings'], static fn(array $f) => $f['code'] === 'non-owner-participant-link'),
      null,
      'entry_id',
    );
    self::assertTrue($findings[201]['repairable']);
    self::assertArrayNotHasKey('repair_reasons', $findings[201]);
  }

  /** @return void */
  public function testClubMandateReasonIgnoresRevocationWhenCanonicalLinkExists(): void
  {
    $this->createMissingMandateGrant();
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (400, NULL, NULL, 'folder'), (401, 400, 1, 'file')");
    $this->connection->executeStatement("UPDATE SepaDebitMandates SET deleted = '2000-01-01'");
    $this->connection->executeStatement("UPDATE ProjectParticipants SET deleted = '2000-01-01' WHERE project_id = 21");
    $findings = array_column(
      array_filter($this->service->audit()['findings'], static fn(array $f) => $f['code'] === 'non-owner-participant-link'),
      null,
      'entry_id',
    );
    self::assertNotContains('club-mandate-source-link-missing', $findings[201]['repair_reasons'] ?? []);
    self::assertNotContains('document-deleted', $findings[201]['repair_reasons'] ?? []);

    $this->connection->executeStatement('UPDATE SepaDebitMandates SET deleted = NULL');
    $this->connection->executeStatement('INSERT INTO SepaDebitMandates VALUES (2, 10, 20, 401, NULL)');
    $findings = array_column(
      array_filter($this->service->audit()['findings'], static fn(array $f) => $f['code'] === 'non-owner-participant-link'),
      null,
      'entry_id',
    );
    self::assertFalse($findings[201]['repairable']);
    self::assertNotContains('club-mandate-source-link-missing', $findings[201]['repair_reasons'] ?? []);
  }

  /** @return iterable */
  public static function unsafeMandateGrants(): iterable
  {
    yield 'deleted musician' => ["UPDATE Musicians SET deleted = '2000-01-01'"];
    yield 'deleted source project' => ["UPDATE Projects SET deleted = '2000-01-01' WHERE id = 20"];
    yield 'deleted source participant' => ["UPDATE ProjectParticipants SET deleted = '2000-01-01' WHERE musician_id = 10 AND project_id = 20"];
    yield 'non-encrypted file' => ["UPDATE Files SET type = 'plain'"];
    yield 'broken source entry' => ['UPDATE DatabaseStorageDirEntries SET parent_id = 999 WHERE id = 101'];
    yield 'missing source participant link' => ['UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 102'];
    yield 'shared source root' => ['UPDATE DatabaseStorages SET root_id = 100 WHERE id = 3'];
  }

  /**
   * @param string $sql
   *
   * @return void
   */
  #[Attributes\DataProvider('unsafeMandateGrants')]
  public function testAmbiguousMandatesAreNotMarkedRepairable(string $sql): void
  {
    $this->createMissingMandateGrant();
    $this->connection->executeStatement($sql);
    foreach ($this->service->audit()['findings'] as $finding) {
      self::assertFalse($finding['repairable']);
      self::assertArrayNotHasKey('repairsteps', $finding);
    }
  }

  /** @return void */
  public function testRevokedMandateDocumentInOrphanTreeRemainsAuditableAndRepairable(): void
  {
    $this->connection->executeStatement(
      "INSERT INTO SepaDebitMandates VALUES (1, 10, 20, 102, '2000-01-01')",
    );
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder')");
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 102');

    $findings = array_values(array_filter(
      $this->service->audit()['findings'],
      static fn(array $finding) => ($finding['source'] ?? null) === 'debit-mandate',
    ));
    self::assertCount(1, $findings);
    self::assertSame('missing-participant-link', $findings[0]['code']);
    self::assertTrue($findings[0]['repairable']);
    self::assertSame(['add-participant-link'], $findings[0]['repairsteps']);
    self::assertNotContains('document-deleted', $findings[0]['repair_reasons'] ?? []);
  }

  /** @return iterable */
  public static function unsafeMandateDestinations(): iterable
  {
    yield 'deleted destination participant' => [
      "UPDATE ProjectParticipants SET deleted = '2000-01-01' WHERE musician_id = 10 AND project_id = 21",
    ];
    yield 'deleted destination project' => ["UPDATE Projects SET deleted = '2000-01-01' WHERE id = 21"];
    yield 'broken destination tree' => ['UPDATE DatabaseStorageDirEntries SET parent_id = 999 WHERE id = 201'];
    yield 'shared destination root' => ['UPDATE DatabaseStorages SET root_id = 200 WHERE id = 3'];
  }

  /**
   * @param string $sql
   *
   * @return void
   */
  #[Attributes\DataProvider('unsafeMandateDestinations')]
  public function testUnsafeMandateDestinationIsNotRepairable(string $sql): void
  {
    $this->createMissingMandateGrant();
    $this->connection->executeStatement($sql);
    $findings = array_values(array_filter(
      $this->service->audit()['findings'],
      static fn(array $finding) => ($finding['entry_id'] ?? null) === 201,
    ));
    self::assertNotEmpty($findings);
    foreach ($findings as $finding) {
      self::assertFalse($finding['repairable']);
    }
  }

  /** @return void */
  private function createMissingMandateGrant(): void
  {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement('INSERT INTO SepaDebitMandates VALUES (1, 10, 20, 102, NULL)');
    $this->connection->executeStatement('INSERT INTO Projects VALUES (21, NULL), (22, NULL)');
    $this->connection->executeStatement('INSERT INTO Musicians VALUES (11, NULL)');
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (2, 200), (3, 300)');
    $this->connection->executeStatement(
      'INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (10, 21, 2), (11, 22, 3)',
    );
    $this->connection->executeStatement(
      "INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder'), (201, 200, 1, 'file'),
                                                   (300, NULL, NULL, 'folder'), (301, 300, 1, 'file')",
    );
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

  /** @return iterable */
  public static function financialDocuments(): iterable
  {
    yield 'invoice' => ['invoice', 'INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)'];
    yield 'payment' => ['composite-payment', 'INSERT INTO CompositePayments VALUES (1, 10, 20, 201)'];
    yield 'receipt' => ['donation-receipt', 'INSERT INTO CompositePayments VALUES (1, 10, 20, NULL)',
      'INSERT INTO DonationReceipts VALUES (1, 1, 201, NULL)'];
  }

  /**
   * @param string $source
   * @param string $sql
   * @param null|string $extraSql
   *
   * @return void
   */
  #[Attributes\DataProvider('financialDocuments')]
  public function testFinancialDocumentGrantsAreRepairableOnlyForTheRightParticipant(
    string $source,
    string $sql,
    ?string $extraSql = null,
  ): void {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement(
      "INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder'), (201, 200, 1, 'file'),
                                                   (300, NULL, NULL, 'folder'), (301, 300, 1, 'file'),
                                                   (400, NULL, NULL, 'folder'), (401, 400, 1, 'file')",
    );
    $this->connection->executeStatement('INSERT INTO DatabaseStorages VALUES (2, 300), (3, 400)');
    $this->connection->executeStatement(
      'INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (10, 21, 2), (11, 20, 3)',
    );
    $this->connection->executeStatement($sql);
    if ($extraSql !== null) {
      $this->connection->executeStatement($extraSql);
    }
    $before = $this->snapshot();
    $findings = [];
    foreach ($this->service->audit()['findings'] as $finding) {
      $findings[$finding['code'] . ':' . ($finding['source'] ?? $finding['entry_id'])] = $finding;
    }
    foreach (['missing-ownership:' . $source, 'non-owner-participant-link:102'] as $key) {
      self::assertTrue($findings[$key]['repairable']);
      self::assertSame(['add-ownership', 'reconcile-encryption-seal'], $findings[$key]['repairsteps']);
    }
    self::assertFalse($findings['non-owner-participant-link:301']['repairable']);
    self::assertFalse($findings['non-owner-participant-link:401']['repairable']);
    self::assertSame($before, $this->snapshot());
  }

  /** @return iterable */
  public static function unsafeFinancialDocuments(): iterable
  {
    yield 'deleted invoice' => ['invoice', "UPDATE Invoices SET deleted = '2000-01-01'"];
    yield 'deleted receipt' => ['receipt', "UPDATE DonationReceipts SET deleted = '2000-01-01'"];
    yield 'deleted musician' => ['payment', "UPDATE Musicians SET deleted = '2000-01-01'"];
    yield 'deleted project' => ['payment', "UPDATE Projects SET deleted = '2000-01-01'"];
    yield 'deleted participant' => ['payment', "UPDATE ProjectParticipants SET deleted = '2000-01-01'"];
    yield 'unencrypted file' => ['payment', "UPDATE Files SET type = 'plain'"];
    yield 'broken participant tree' => ['payment', 'UPDATE DatabaseStorageDirEntries SET parent_id = 999 WHERE id = 101'];
    yield 'invalid source entry' => ['payment', "UPDATE DatabaseStorageDirEntries SET type = 'folder' WHERE id = 201"];
    yield 'shared participant root' => ['payment', 'INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (11, 20, 1)'];
  }

  /**
   * @param string $type
   * @param string $sql
   *
   * @return void
   */
  #[Attributes\DataProvider('unsafeFinancialDocuments')]
  public function testUnsafeFinancialDocumentsAreNotMarkedRepairable(string $type, string $sql): void
  {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder'), (201, 200, 1, 'file')");
    if ($type === 'invoice') {
      $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL)');
    } else {
      $this->connection->executeStatement('INSERT INTO CompositePayments VALUES (1, 10, 20, ' . ($type === 'payment' ? '201' : 'NULL') . ')');
      if ($type === 'receipt') {
        $this->connection->executeStatement('INSERT INTO DonationReceipts VALUES (1, 1, 201, NULL)');
      }
    }
    $this->connection->executeStatement($sql);
    $findings = $this->service->audit()['findings'];
    self::assertNotEmpty($findings);
    foreach ($findings as $finding) {
      self::assertFalse($finding['repairable']);
      self::assertArrayNotHasKey('repairsteps', $finding);
    }
  }

  /** @return iterable */
  public static function paymentLinkOwnership(): iterable
  {
    foreach (['invoice', 'composite-payment', 'donation-receipt', 'debit-mandate'] as $source) {
      yield $source . ' owner missing' => [$source, false, ['add-participant-link', 'add-ownership', 'reconcile-encryption-seal']];
      yield $source . ' owner already present' => [$source, true, ['add-participant-link']];
    }
  }

  /**
   * @param string $source
   * @param bool $hasOwner
   * @param array $steps
   *
   * @return void
   */
  #[Attributes\DataProvider('paymentLinkOwnership')]
  public function testMissingDocumentLinkIsRepairable(string $source, bool $hasOwner, array $steps): void
  {
    if (!$hasOwner) {
      $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    }
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 102');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder')");
    $this->connection->executeStatement("UPDATE ProjectParticipants SET participation_status = 'associated'");
    $this->insertFinancialDocument($source, 294, 102);
    $before = $this->snapshot();
    $report = $this->service->audit();
    self::assertSame($hasOwner ? ['missing-participant-link'] : ['missing-ownership', 'missing-participant-link'], $this->codes($report));
    foreach ($report['findings'] as $finding) {
      self::assertSame($source, $finding['source']);
      self::assertSame(294, (int)$finding['source_id']);
      self::assertTrue($finding['repairable']);
      self::assertSame($steps, $finding['repairsteps']);
    }
    self::assertSame($before, $this->snapshot());
  }

  /** @return void */
  public function testMissingLinksReportEachDistinctBusinessReference(): void
  {
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder')");
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 102');
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL), (2, 10, 20, 102, NULL)');
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type) VALUES (1, 'db-file')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '102', NULL, NULL)");
    $findings = array_values(array_filter(
      $this->service->audit()['findings'],
      static fn(array $finding) => $finding['code'] === 'missing-participant-link',
    ));
    self::assertCount(3, $findings);
    self::assertSame(['invoice', 'invoice', 'participant-field'], array_column($findings, 'source'));
    self::assertSame([1, 2, 1], array_map('intval', array_column($findings, 'source_id')));
    foreach ($findings as $finding) {
      self::assertTrue($finding['repairable']);
    }
  }

  /** @return iterable */
  public static function unsafeMissingPaymentLinks(): iterable
  {
    yield 'deleted musician' => ["UPDATE Musicians SET deleted = '2000-01-01'"];
    yield 'deleted project' => ["UPDATE Projects SET deleted = '2000-01-01'"];
    yield 'deleted participant' => ["UPDATE ProjectParticipants SET deleted = '2000-01-01'"];
    yield 'unencrypted file' => ["UPDATE Files SET type = 'plain'"];
    yield 'missing storage' => ['UPDATE ProjectParticipants SET database_documents_id = NULL'];
    yield 'shared storage root' => ['INSERT INTO ProjectParticipants (musician_id, project_id, database_documents_id) VALUES (11, 20, 1)'];
    yield 'missing storage root' => ['UPDATE DatabaseStorages SET root_id = 999'];
    yield 'root is not a folder' => ["UPDATE DatabaseStorageDirEntries SET type = 'file' WHERE id = 100"];
    yield 'root has a parent' => ['UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 100'];
    yield 'broken source chain' => ['UPDATE DatabaseStorageDirEntries SET parent_id = 999 WHERE id = 102'];
    yield 'invalid source entry' => ["UPDATE DatabaseStorageDirEntries SET type = 'folder' WHERE id = 102"];
  }

  /** @return iterable */
  public static function unsafeMissingDocumentLinks(): iterable
  {
    foreach (['invoice', 'composite-payment', 'donation-receipt', 'debit-mandate'] as $source) {
      foreach (self::unsafeMissingPaymentLinks() as $name => [$sql]) {
        yield $source . ' ' . $name => [$source, $sql];
      }
    }
    yield 'deleted invoice' => ['invoice', "UPDATE Invoices SET deleted = '2000-01-01'"];
    yield 'deleted receipt' => ['donation-receipt', "UPDATE DonationReceipts SET deleted = '2000-01-01'"];
  }

  /** @return void */
  private function createMissingPrivateFieldLink(): void
  {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder')");
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 102');
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields (id, data_type) VALUES (1, 'db-file')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '102', NULL, NULL)");
  }

  /** @return void */
  public function testPrivateDbFileLinkRepairDoesNotGrantOwnership(): void
  {
    $this->createMissingPrivateFieldLink();
    $this->connection->executeStatement("UPDATE ProjectParticipants SET participation_status = 'associated'");
    $before = $this->snapshot();
    $report = $this->service->audit();
    self::assertSame(['missing-ownership', 'missing-participant-link'], $this->codes($report));
    $findings = array_column($report['findings'], null, 'code');
    self::assertFalse($findings['missing-ownership']['repairable']);
    self::assertContains('field-access-not-readable', $findings['missing-ownership']['repair_reasons']);
    self::assertArrayNotHasKey('repairsteps', $findings['missing-ownership']);
    self::assertTrue($findings['missing-participant-link']['repairable']);
    self::assertSame(['add-participant-link'], $findings['missing-participant-link']['repairsteps']);
    self::assertArrayNotHasKey('repair_reasons', $findings['missing-participant-link']);
    self::assertSame($before, $this->snapshot());
  }

  /** @return iterable */
  public static function unsafeMissingFieldLinks(): iterable
  {
    yield from self::unsafeMissingPaymentLinks();
    yield 'deleted datum' => ["UPDATE ProjectParticipantFieldsData SET deleted = '2000-01-01'"];
    yield 'deleted field' => ["UPDATE ProjectParticipantFields SET deleted = '2000-01-01'"];
    yield 'field project mismatch' => ['UPDATE ProjectParticipantFields SET project_id = 21'];
  }

  /** @return iterable */
  public static function missingSupportLinkOwners(): iterable
  {
    yield 'existing owner' => [true, ['add-participant-link']];
    yield 'missing owner' => [false, ['add-participant-link', 'add-ownership', 'reconcile-encryption-seal']];
  }

  /**
   * @param bool $hasOwner
   * @param array $steps
   *
   * @return void
   */
  #[Attributes\DataProvider('missingSupportLinkOwners')]
  public function testMissingReadableSupportLinkIsRepairable(bool $hasOwner, array $steps): void
  {
    $this->createMissingSupportGrant();
    if ($hasOwner) {
      $this->connection->executeStatement('INSERT INTO EncryptedFileOwners VALUES (1, 10)');
    }
    $this->connection->executeStatement("UPDATE ProjectParticipantFields SET participant_access = 'read-write'");
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder')");
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 102');
    $before = $this->snapshot();
    $report = $this->service->audit();
    self::assertSame($hasOwner ? ['missing-participant-link'] : ['missing-ownership', 'missing-participant-link'], $this->codes($report));
    foreach ($report['findings'] as $finding) {
      self::assertSame('participant-field-support', $finding['source']);
      self::assertTrue($finding['repairable']);
      self::assertSame($steps, $finding['repairsteps']);
    }
    self::assertSame($before, $this->snapshot());
  }

  /** @return iterable */
  public static function unsafeMissingSupportLinks(): iterable
  {
    yield from self::unsafeMissingFieldLinks();
    yield 'access disabled' => ["UPDATE ProjectParticipantFields SET participant_access = 'none'"];
    yield 'excluded context' => ["UPDATE ProjectParticipantFields SET participation_context = 'associates'"];
    yield 'associated participant' => [
      "UPDATE ProjectParticipantFields SET participation_context = 'participants'",
      "UPDATE ProjectParticipants SET participation_status = 'associated'",
    ];
  }

  /**
   * @param string $sql
   * @param null|string $extraSql
   *
   * @return void
   */
  #[Attributes\DataProvider('unsafeMissingSupportLinks')]
  public function testUnsafeMissingSupportLinksAreNotRepairable(string $sql, ?string $extraSql = null): void
  {
    $this->createMissingSupportGrant();
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder')");
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 102');
    $this->connection->executeStatement($sql);
    if ($extraSql !== null) {
      $this->connection->executeStatement($extraSql);
    }
    $findings = $this->service->audit()['findings'];
    self::assertNotEmpty($findings);
    foreach ($findings as $finding) {
      self::assertFalse($finding['repairable']);
      self::assertArrayNotHasKey('repairsteps', $finding);
    }
  }

  /**
   * @param string $sql
   *
   * @return void
   */
  #[Attributes\DataProvider('unsafeMissingFieldLinks')]
  public function testUnsafePrivateDbFileLinksAreNotRepairable(string $sql): void
  {
    $this->createMissingPrivateFieldLink();
    $this->connection->executeStatement($sql);
    $report = $this->service->audit();
    self::assertNotEmpty($report['findings']);
    foreach ($report['findings'] as $finding) {
      self::assertFalse($finding['repairable']);
      self::assertArrayNotHasKey('repairsteps', $finding);
    }
  }

  /**
   * @param string $source
   * @param string $sql
   *
   * @return void
   */
  #[Attributes\DataProvider('unsafeMissingDocumentLinks')]
  public function testUnsafeMissingDocumentLinksAreNotMarkedRepairable(string $source, string $sql): void
  {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement('UPDATE DatabaseStorageDirEntries SET parent_id = 200 WHERE id = 102');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder')");
    $this->insertFinancialDocument($source, 294, 102);
    $this->connection->executeStatement($sql);
    $findings = $this->service->audit()['findings'];
    self::assertNotEmpty($findings);
    foreach ($findings as $finding) {
      self::assertFalse($finding['repairable']);
      self::assertArrayNotHasKey('repairsteps', $finding);
    }
  }

  /**
   * @param string $source
   * @param int $sourceId
   * @param int $entryId
   *
   * @return void
   */
  private function insertFinancialDocument(string $source, int $sourceId, int $entryId): void
  {
    match ($source) {
      'invoice' => $this->connection->executeStatement(
        'INSERT INTO Invoices VALUES (?, 10, 20, ?, NULL)',
        [$sourceId, $entryId],
      ),
      'composite-payment' => $this->connection->executeStatement(
        'INSERT INTO CompositePayments VALUES (?, 10, 20, ?)',
        [$sourceId, $entryId],
      ),
      'donation-receipt' => $this->insertDonationReceipt($sourceId, $entryId),
      'debit-mandate' => $this->connection->executeStatement(
        'INSERT INTO SepaDebitMandates VALUES (?, 10, 20, ?, NULL)',
        [$sourceId, $entryId],
      ),
      default => throw new LogicException('Unsupported financial document source: ' . $source),
    };
  }

  /**
   * @param int $sourceId
   * @param int $entryId
   *
   * @return int
   */
  private function insertDonationReceipt(int $sourceId, int $entryId): int
  {
    $this->connection->executeStatement(
      'INSERT INTO CompositePayments VALUES (?, 10, 20, NULL)',
      [$sourceId],
    );
    return $this->connection->executeStatement(
      'INSERT INTO DonationReceipts VALUES (?, ?, ?, NULL)',
      [$sourceId, $sourceId, $entryId],
    );
  }

  /** @return void */
  public function testDeletedFinancialReferenceIsNotJustifiedByAnotherActiveReference(): void
  {
    $this->connection->executeStatement('DELETE FROM EncryptedFileOwners');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (200, NULL, NULL, 'folder'), (201, 200, 1, 'file')");
    $this->connection->executeStatement(
      "INSERT INTO Invoices VALUES (1, 10, 20, 201, NULL), (2, 10, 20, 201, '2000-01-01')",
    );
    $findings = array_values(array_filter(
      $this->service->audit()['findings'],
      static fn(array $finding) => $finding['code'] === 'missing-ownership',
    ));
    self::assertCount(2, $findings);
    $findings = array_column($findings, null, 'source_id');
    self::assertTrue($findings[1]['repairable']);
    self::assertFalse($findings[2]['repairable']);
  }

  /** @return void */
  public function testTaxExemptionNoticeIsNotRecognizedAsARepairCandidate(): void
  {
    $this->connection->executeStatement('CREATE TABLE TaxExemptionNotices (id INTEGER PRIMARY KEY, written_notice_id INTEGER)');
    $this->connection->executeStatement('ALTER TABLE DonationReceipts ADD COLUMN tax_exemption_notice_id INTEGER');
    $this->connection->executeStatement("INSERT INTO Files VALUES (2, 'encrypted')");
    $this->connection->executeStatement('INSERT INTO EncryptedFileOwners VALUES (2, 10)');
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (103, 101, 2, 'file')");
    $this->connection->executeStatement('INSERT INTO CompositePayments VALUES (1, 10, 20, NULL)');
    $this->connection->executeStatement('INSERT INTO TaxExemptionNotices VALUES (1, 103)');
    $this->connection->executeStatement(
      'INSERT INTO DonationReceipts (id, donation_id, tax_exemption_notice_id) VALUES (1, 1, 1)',
    );
    $findings = array_values(array_filter(
      $this->service->audit()['findings'],
      static fn(array $finding) => ($finding['file_id'] ?? null) === 2,
    ));
    self::assertSame(['unrecognized-owned-file', 'unrelated-participant-link'], $this->codes(['findings' => $findings]));
    foreach ($findings as $finding) {
      self::assertFalse($finding['repairable']);
    }
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

  /**
   * @param null|string $sql
   *
   * @return void
   */
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
