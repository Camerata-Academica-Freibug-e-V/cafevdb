<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author GitHub Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Storage\Database;

use ReflectionProperty;
use RuntimeException;

use OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DatabaseStorageDirEntry;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DatabaseStorageFolder;
use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\LostAndFoundDeletionGuard;
use OCA\CAFEVDB\Service\LostAndFoundNotificationService;
use OCA\CAFEVDB\Storage\Database\LostAndFoundStorage;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\DriverManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;

/** Exercise deletion dispatch and operator notifications. */
#[Attributes\CoversClass(LostAndFoundStorage::class)]
#[Attributes\UsesClass(LostAndFoundDeletionGuard::class)]
class LostAndFoundStorageTest extends TestCase
{
  /** @return void */
  public function testDeletionRequiresTheAuthenticatedAdminAndNeverAllowsTheRoot(): void
  {
    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('alice');
    $session = $this->createStub(IUserSession::class);
    $session->method('getUser')->willReturn($user);
    $config = $this->createMock(ConfigService::class);
    $config->method('getUserSession')->willReturn($session);
    $config->expects(self::exactly(4))->method('inSubAdminGroup')->with('alice')
      ->willReturnOnConsecutiveCalls(false, true, true, true);
    $manager = $this->createStub(EntityManager::class);
    $manager->method('getUserId')->willReturnOnConsecutiveCalls('bob', 'alice', 'alice');
    $storage = $this->getMockBuilder(LostAndFoundStorage::class)->disableOriginalConstructor()
      ->onlyMethods(['file_exists'])->getMock();
    $storage->expects(self::once())->method('file_exists')->with('recovered')->willReturn(true);
    (new ReflectionProperty(LostAndFoundStorage::class, 'configService'))->setValue($storage, $config);
    (new ReflectionProperty(LostAndFoundStorage::class, 'entityManager'))->setValue($storage, $manager);

    self::assertFalse($storage->isDeletable('recovered'));
    self::assertFalse($storage->isDeletable('recovered'));
    self::assertFalse($storage->isDeletable(''));
    self::assertTrue($storage->isDeletable('recovered'));
  }

  /** @return void */
  public function testBlockedFolderDoesNotRemoveEntriesAndNotifiesOperator(): void
  {
    $connection = $this->database();
    $connection->executeStatement('INSERT INTO Invoices (written_invoice_id) VALUES (3)');
    $manager = $this->createMock(EntityManager::class);
    $manager->method('getConnection')->willReturn($connection);
    $manager->method('beginTransaction')->willReturnCallback($connection->beginTransaction(...));
    $manager->method('rollback')->willReturnCallback($connection->rollBack(...));
    $manager->expects(self::never())->method('remove');
    $manager->expects(self::never())->method('flush');
    $notifications = $this->createMock(LostAndFoundNotificationService::class);
    $notifications->expects(self::once())->method('notify')->with(
      'alice', 'recovered', 2, [['entry_id' => 3, 'reference' => 'Invoices.written_invoice_id']],
    );
    $storage = $this->storage($manager, $notifications);

    self::assertFalse($storage->rmdir('recovered'));
    self::assertFalse($connection->isTransactionActive());
    self::assertSame(3, (int)$connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries'));
    $connection->close();
  }

  /** @return void */
  public function testUnreferencedFolderRemovesLeavesBeforeParent(): void
  {
    $connection = $this->database();
    $manager = $this->createMock(EntityManager::class);
    $manager->method('getConnection')->willReturn($connection);
    $manager->method('beginTransaction')->willReturnCallback($connection->beginTransaction(...));
    $manager->method('commit')->willReturnCallback($connection->commit(...));
    $file = $this->createStub(DatabaseStorageDirEntry::class);
    $folder = $this->createStub(DatabaseStorageDirEntry::class);
    $manager->method('find')->willReturnCallback(
      static fn(string $class, int $id) => $id === 3 ? $file : $folder,
    );
    $removed = [];
    $manager->expects(self::exactly(2))->method('remove')->willReturnCallback(
      static function(DatabaseStorageDirEntry $entry) use (&$removed): void {
        $removed[] = $entry;
      },
    );
    $manager->expects(self::once())->method('flush');
    $notifications = $this->createMock(LostAndFoundNotificationService::class);
    $notifications->expects(self::never())->method('notify');
    $storage = $this->storage($manager, $notifications);

    self::assertTrue($storage->rmdir('recovered'));
    self::assertSame([$file, $folder], $removed);
    self::assertFalse($connection->isTransactionActive());
    $connection->close();
  }

  /** @return void */
  public function testDeletionFailureRollsBackWithoutNotification(): void
  {
    $connection = $this->database();
    $manager = $this->createMock(EntityManager::class);
    $manager->method('getConnection')->willReturn($connection);
    $manager->method('beginTransaction')->willReturnCallback($connection->beginTransaction(...));
    $manager->method('isTransactionActive')->willReturnCallback($connection->isTransactionActive(...));
    $manager->expects(self::once())->method('rollback')->willReturnCallback($connection->rollBack(...));
    $manager->expects(self::once())->method('clear');
    $manager->method('find')->willReturn($this->createStub(DatabaseStorageDirEntry::class));
    $manager->method('flush')->willThrowException(new RuntimeException('flush failed'));
    $notifications = $this->createMock(LostAndFoundNotificationService::class);
    $notifications->expects(self::never())->method('notify');
    $storage = $this->storage($manager, $notifications);

    try {
      $storage->rmdir('recovered');
      self::fail('Expected deletion to fail.');
    } catch (RuntimeException $exception) {
      self::assertSame('flush failed', $exception->getMessage());
    }
    self::assertFalse($connection->isTransactionActive());
    self::assertSame(3, (int)$connection->fetchOne('SELECT COUNT(*) FROM DatabaseStorageDirEntries'));
    $connection->close();
  }

  /** @return \OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection */
  private function database()
  {
    $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
      'CREATE TABLE DatabaseStorageDirEntries (id INTEGER, parent_id INTEGER, file_id INTEGER, type TEXT)',
      'CREATE TABLE DatabaseStorages (root_id INTEGER, storage_id TEXT)',
      'CREATE TABLE Files (id INTEGER, type TEXT)',
      'CREATE TABLE EncryptedFileOwners (encrypted_file_id INTEGER)',
      'CREATE TABLE CompositePayments (supporting_document_id INTEGER, balance_documents_folder_id INTEGER)',
      'CREATE TABLE DonationReceipts (supporting_document_id INTEGER)',
      'CREATE TABLE InvoiceItems (balance_documents_folder_id INTEGER)',
      'CREATE TABLE Invoices (balance_documents_folder_id INTEGER, written_invoice_id INTEGER)',
      'CREATE TABLE ProjectPayments (balance_documents_folder_id INTEGER)',
      'CREATE TABLE ProjectParticipantFields (id INTEGER, data_type TEXT)',
      'CREATE TABLE ProjectParticipantFieldsData (field_id INTEGER, supporting_document_id INTEGER, option_value TEXT)',
      'CREATE TABLE SepaBulkTransactionData (database_storage_file_id INTEGER)',
      'CREATE TABLE SepaBulkTransactionBalancingData (database_storage_file_id INTEGER)',
      'CREATE TABLE SepaDebitMandates (written_mandate_id INTEGER)',
      'CREATE TABLE TaxExemptionNotices (written_notice_id INTEGER)',
      "INSERT INTO DatabaseStorageDirEntries VALUES (1,NULL,NULL,'folder'), (2,1,NULL,'folder'), (3,2,10,'file')",
      "INSERT INTO DatabaseStorages VALUES (1,'lost+found/')",
      "INSERT INTO Files VALUES (10,'encrypted')",
    ] as $statement) {
      $connection->executeStatement($statement);
    }
    return $connection;
  }

  /**
   * @param EntityManager $manager
   * @param LostAndFoundNotificationService $notifications
   *
   * @return LostAndFoundStorage
   */
  private function storage(EntityManager $manager, LostAndFoundNotificationService $notifications): LostAndFoundStorage
  {
    $storage = $this->getMockBuilder(LostAndFoundStorage::class)->disableOriginalConstructor()
      ->onlyMethods(['fileFromFileName', 'isDeletable', 'di', 'logException'])->getMock();
    $entry = $this->createStub(DatabaseStorageFolder::class);
    $entry->method('getId')->willReturn(2);
    $storage->expects(self::once())->method('fileFromFileName')->with('recovered')->willReturn($entry);
    $storage->method('isDeletable')->willReturn(true);
    $storage->method('di')->willReturn($notifications);
    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('alice');
    $session = $this->createStub(IUserSession::class);
    $session->method('getUser')->willReturn($user);
    $config = $this->createStub(ConfigService::class);
    $config->method('getUserSession')->willReturn($session);
    (new ReflectionProperty(LostAndFoundStorage::class, 'configService'))->setValue($storage, $config);
    (new ReflectionProperty(LostAndFoundStorage::class, 'entityManager'))->setValue($storage, $manager);
    return $storage;
  }
}
