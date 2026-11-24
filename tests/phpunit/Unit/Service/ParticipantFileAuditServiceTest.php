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
    $this->service = new ParticipantFileAuditService($this->connection);
    foreach ([
      'CREATE TABLE Files (id INTEGER PRIMARY KEY, type TEXT)',
      'CREATE TABLE EncryptedFileOwners (encrypted_file_id INTEGER, musician_id INTEGER)',
      'CREATE TABLE DatabaseStorageDirEntries (id INTEGER PRIMARY KEY, parent_id INTEGER, file_id INTEGER, type TEXT)',
      'CREATE TABLE DatabaseStorages (id INTEGER PRIMARY KEY, root_id INTEGER)',
      'CREATE TABLE ProjectParticipants (musician_id INTEGER, project_id INTEGER, database_documents_id INTEGER)',
      'CREATE TABLE SepaDebitMandates (sequence INTEGER, musician_id INTEGER, project_id INTEGER, written_mandate_id INTEGER, deleted TEXT)',
      'CREATE TABLE Invoices (id INTEGER PRIMARY KEY, debitor_id INTEGER, project_id INTEGER, written_invoice_id INTEGER, deleted TEXT)',
      'CREATE TABLE CompositePayments (id INTEGER PRIMARY KEY, musician_id INTEGER, project_id INTEGER, supporting_document_id INTEGER)',
      'CREATE TABLE DonationReceipts (id INTEGER PRIMARY KEY, donation_id INTEGER, supporting_document_id INTEGER, deleted TEXT)',
      'CREATE TABLE ProjectParticipantFields (id INTEGER PRIMARY KEY, data_type TEXT)',
      'CREATE TABLE ProjectParticipantFieldsData (field_id INTEGER, musician_id INTEGER, project_id INTEGER, option_key BLOB, option_value TEXT, supporting_document_id INTEGER, deleted TEXT)',
      "INSERT INTO Files VALUES (1, 'encrypted')",
      'INSERT INTO EncryptedFileOwners VALUES (1, 10)',
      "INSERT INTO DatabaseStorageDirEntries VALUES (100, NULL, NULL, 'folder'), (101, 100, NULL, 'folder'), (102, 101, 1, 'file')",
      'INSERT INTO DatabaseStorages VALUES (1, 100)',
      'INSERT INTO ProjectParticipants VALUES (10, 20, 1)',
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
      "INSERT INTO ProjectParticipantFields VALUES (1, 'db-file')",
      "INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '102', NULL, NULL)",
    ];
    yield 'insurance supporting document' => [
      "INSERT INTO ProjectParticipantFields VALUES (1, 'receivables')",
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
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields VALUES (1, 'text')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1, 10, 20, X'01', '102', NULL, NULL)");
    self::assertSame(
      ['unrecognized-owned-file', 'unrelated-participant-link'],
      $this->codes($this->service->audit()),
    );
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
    $this->connection->executeStatement('INSERT INTO ProjectParticipants VALUES (10, 21, 2), (12, 22, 3)');
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
  public function testSharedFilesWithExplicitReferencesForBothParticipantsAreValid(): void
  {
    $this->connection->executeStatement('INSERT INTO Invoices VALUES (1, 10, 20, 102, NULL), (2, 11, 21, 201, NULL)');
    $this->connection->executeStatement('INSERT INTO EncryptedFileOwners VALUES (1, 11)');
    $this->connection->executeStatement('INSERT INTO ProjectParticipants VALUES (11, 21, 2)');
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
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields VALUES (1, 'db-file')");
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
