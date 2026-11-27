<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author GitHub Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use RuntimeException;

use OCA\CAFEVDB\Service\LostAndFoundDeletionGuard;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;

/** Check recovered-subtree deletion against real metadata queries. */
#[Attributes\CoversClass(LostAndFoundDeletionGuard::class)]
class LostAndFoundDeletionGuardTest extends TestCase
{
  private Connection $connection;
  private LostAndFoundDeletionGuard $guard;

  /** {@inheritdoc} */
  protected function setUp(): void
  {
    $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
      'CREATE TABLE DatabaseStorageDirEntries (id INTEGER PRIMARY KEY, parent_id INTEGER, file_id INTEGER, type TEXT)',
      'CREATE TABLE DatabaseStorages (root_id INTEGER, storage_id TEXT)',
      'CREATE TABLE Files (id INTEGER, type TEXT)',
      'CREATE TABLE EncryptedFileOwners (encrypted_file_id INTEGER, musician_id INTEGER)',
      'CREATE TABLE CompositePayments (supporting_document_id INTEGER, balance_documents_folder_id INTEGER)',
      'CREATE TABLE DonationReceipts (supporting_document_id INTEGER)',
      'CREATE TABLE InvoiceItems (balance_documents_folder_id INTEGER)',
      'CREATE TABLE Invoices (balance_documents_folder_id INTEGER, written_invoice_id INTEGER)',
      'CREATE TABLE ProjectPayments (balance_documents_folder_id INTEGER)',
      'CREATE TABLE ProjectParticipantFields (id INTEGER, data_type TEXT)',
      'CREATE TABLE ProjectParticipantFieldsData (field_id INTEGER, supporting_document_id INTEGER, option_value TEXT, deleted TEXT)',
      'CREATE TABLE SepaBulkTransactionData (database_storage_file_id INTEGER)',
      'CREATE TABLE SepaBulkTransactionBalancingData (database_storage_file_id INTEGER)',
      'CREATE TABLE SepaDebitMandates (written_mandate_id INTEGER)',
      'CREATE TABLE TaxExemptionNotices (written_notice_id INTEGER)',
      "INSERT INTO DatabaseStorageDirEntries VALUES (1,NULL,NULL,'folder'), (2,1,NULL,'folder'),
       (3,2,10,'file'), (4,2,NULL,'folder'), (5,4,11,'file'), (6,NULL,NULL,'folder')",
      "INSERT INTO DatabaseStorages VALUES (1,'lost+found/'), (6,'other/')",
      "INSERT INTO Files VALUES (10,'encrypted'), (11,'encrypted')",
    ] as $statement) {
      $this->connection->executeStatement($statement);
    }
    $this->guard = new LostAndFoundDeletionGuard($this->connection);
    $this->connection->beginTransaction();
  }

  /** {@inheritdoc} */
  protected function tearDown(): void
  {
    if ($this->connection->isTransactionActive()) {
      $this->connection->rollBack();
    }
    $this->connection->close();
  }

  /** @return void */
  public function testUnreferencedTreeIsEligible(): void
  {
    $result = $this->guard->inspect(2);
    self::assertSame([], $result['blockers']);
    $ids = array_map('intval', array_column($result['entries'], 'id'));
    sort($ids);
    self::assertSame([2, 3, 4, 5], $ids);
  }

  /** @return iterable<string, array{string, string, int}> */
  public static function references(): iterable
  {
    yield 'invoice' => ['INSERT INTO Invoices (written_invoice_id) VALUES (5)', 'Invoices.written_invoice_id', 5];
    yield 'mandate' => ['INSERT INTO SepaDebitMandates VALUES (3)', 'SepaDebitMandates.written_mandate_id', 3];
    yield 'bulk data' => ['INSERT INTO SepaBulkTransactionData VALUES (3)', 'SepaBulkTransactionData.database_storage_file_id', 3];
    yield 'balancing data' => ['INSERT INTO SepaBulkTransactionBalancingData VALUES (3)', 'SepaBulkTransactionBalancingData.database_storage_file_id', 3];
    yield 'folder reference' => ['INSERT INTO ProjectPayments VALUES (4)', 'ProjectPayments.balance_documents_folder_id', 4];
    yield 'nested storage' => ["INSERT INTO DatabaseStorages VALUES (4,'nested/')", 'DatabaseStorages.root_id', 4];
    yield 'supporting document' => ['INSERT INTO ProjectParticipantFieldsData VALUES (1, 5, NULL, NULL)', 'ProjectParticipantFieldsData.supporting_document_id', 5];
  }

  /**
   * @param string $statement
   * @param string $reference
   * @param integer $entryId
   *
   * @return void
   */
  #[Attributes\DataProvider('references')]
  public function testReferencesBlockWholeTree(string $statement, string $reference, int $entryId): void
  {
    $this->connection->executeStatement($statement);
    self::assertContains(['entry_id' => $entryId, 'reference' => $reference], $this->guard->inspect(2)['blockers']);
  }

  /** @return void */
  public function testDeletedDbFileFieldStillBlocksDeletion(): void
  {
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFields VALUES (1,'db-file')");
    $this->connection->executeStatement("INSERT INTO ProjectParticipantFieldsData VALUES (1,NULL,'005','2020-01-01')");
    self::assertContains(
      ['entry_id' => 5, 'reference' => 'ProjectParticipantFieldsData.option_value'],
      $this->guard->inspect(2)['blockers'],
    );
  }

  /** @return void */
  public function testEncryptedFileOwnerDoesNotBlockDeletion(): void
  {
    $this->connection->executeStatement('INSERT INTO EncryptedFileOwners VALUES (10, 7)');
    self::assertSame([], $this->guard->inspect(2)['blockers']);
  }

  /** @return void */
  public function testRegisteredAncestorBlocksDeletingItsDescendant(): void
  {
    $this->connection->executeStatement("INSERT INTO DatabaseStorages VALUES (4,'nested/')");
    self::assertContains(
      ['entry_id' => 4, 'reference' => 'DatabaseStorages.root_id'],
      $this->guard->inspect(5)['blockers'],
    );
  }

  /** @return void */
  public function testInvalidFileAndNonFolderParentBlockDeletion(): void
  {
    $this->connection->executeStatement("INSERT INTO DatabaseStorageDirEntries VALUES (7,3,NULL,'folder')");
    $this->connection->executeStatement('DELETE FROM Files WHERE id = 11');
    $blockers = $this->guard->inspect(2)['blockers'];
    self::assertContains(['entry_id' => 3, 'reference' => 'invalid-directory-entry'], $blockers);
    self::assertContains(['entry_id' => 5, 'reference' => 'invalid-encrypted-file'], $blockers);
  }

  /** @return iterable<string, array{int}> */
  public static function invalidRoots(): iterable
  {
    yield 'recovery root' => [1];
    yield 'other storage' => [6];
  }

  /** @param integer $entryId
   *
   * @return void
   */
  #[Attributes\DataProvider('invalidRoots')]
  public function testCannotDeleteRootOrOtherStorage(int $entryId): void
  {
    $this->expectException(RuntimeException::class);
    $this->guard->inspect($entryId);
  }
}
