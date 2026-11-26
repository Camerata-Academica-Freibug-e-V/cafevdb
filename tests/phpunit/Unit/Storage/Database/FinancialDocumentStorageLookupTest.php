<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Storage\Database;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Storage\Database\BankTransactionsStorage;
use OCA\CAFEVDB\Storage\Database\DonationReceiptsStorage;
use OCA\CAFEVDB\Storage\Database\Storage;
use OCA\CAFEVDB\Storage\Database\TaxExemptionNoticesStorage;
use OCA\CAFEVDB\Wrapped\Doctrine\Common\Collections\ArrayCollection;

/** Test selection of the storage entry for a shared encrypted file. */
#[Attributes\CoversMethod(BankTransactionsStorage::class, 'findDocument')]
#[Attributes\CoversMethod(DonationReceiptsStorage::class, 'findDocument')]
#[Attributes\UsesMethod(DonationReceiptsStorage::class, 'getBirthTimeFromEntity')]
#[Attributes\CoversMethod(TaxExemptionNoticesStorage::class, 'findDocument')]
class FinancialDocumentStorageLookupTest extends TestCase
{
  /**
   * @param bool $bankTransaction
   *
   * @return void
   */
  #[Attributes\DataProvider('yearBasedStorages')]
  public function testFindsOnlyEntriesInTheCorrectStorageAndYear(bool $bankTransaction):void
  {
    $storage = $this->storageWithoutConstructor(
      $bankTransaction ? BankTransactionsStorage::class : DonationReceiptsStorage::class,
    );
    if ($bankTransaction) {
      $entity = $this->createStub(Entities\SepaBulkTransaction::class);
      $entity->method('getCreated')->willReturn(new DateTimeImmutable('2024-06-01'));
    } else {
      $entity = $this->createStub(Entities\DonationReceipt::class);
      $entity->method('getDateOfReceipt')->willReturn(new DateTimeImmutable('2024-06-01'));
    }

    $root = $this->createStub(Entities\DatabaseStorageFolder::class);
    $otherRoot = $this->createStub(Entities\DatabaseStorageFolder::class);
    $year = $this->folder('2024', $root);
    $wrongYear = $this->folder('2023', $root);
    $otherStorageYear = $this->folder('2024', $otherRoot);
    $detachedYear = $this->folder('2024', null);
    $nested = $this->folder('2024', $year);
    $expected = $this->entry($year);
    $file = $this->fileWithEntries([
      $this->entry(null),
      $this->entry($wrongYear),
      $this->entry($otherStorageYear),
      $this->entry($detachedYear),
      $this->entry($nested),
    ]);

    self::assertNull($storage->findDocument($entity, $file));
    $this->setRoot($storage, $root);
    self::assertNull($storage->findDocument($entity, $file));
    $file->getDatabaseStorageDirEntries()->add($expected);
    self::assertSame($expected, $storage->findDocument($entity, $file));
  }

  /** @return iterable<string, array{bool}> */
  public static function yearBasedStorages():iterable
  {
    yield 'bank transactions' => [true];
    yield 'donation receipts' => [false];
  }

  /** @return void */
  public function testTaxNoticeLookupRequiresADirectChildOfTheRoot():void
  {
    $storage = $this->storageWithoutConstructor(TaxExemptionNoticesStorage::class);
    $entity = $this->createStub(Entities\TaxExemptionNotice::class);
    $root = $this->createStub(Entities\DatabaseStorageFolder::class);
    $otherRoot = $this->createStub(Entities\DatabaseStorageFolder::class);
    $child = $this->folder('nested', $root);
    $expected = $this->entry($root);
    $file = $this->fileWithEntries([
      $this->entry(null),
      $this->entry($otherRoot),
      $this->entry($child),
    ]);

    self::assertNull($storage->findDocument($entity, $file));
    $this->setRoot($storage, $root);
    self::assertNull($storage->findDocument($entity, $file));
    $file->getDatabaseStorageDirEntries()->add($expected);
    self::assertSame($expected, $storage->findDocument($entity, $file));
  }

  /**
   * @param class-string<BankTransactionsStorage|DonationReceiptsStorage|TaxExemptionNoticesStorage> $class
   *
   * @return BankTransactionsStorage|DonationReceiptsStorage|TaxExemptionNoticesStorage
   */
  private function storageWithoutConstructor(string $class):BankTransactionsStorage|DonationReceiptsStorage|TaxExemptionNoticesStorage
  {
    return new ReflectionClass($class)->newInstanceWithoutConstructor();
  }

  /**
   * @param Storage $storage
   * @param Entities\DatabaseStorageFolder $root
   *
   * @return void
   */
  private function setRoot(Storage $storage, Entities\DatabaseStorageFolder $root):void
  {
    new ReflectionProperty(Storage::class, 'rootFolder')->setValue($storage, $root);
  }

  /**
   * @param string $name
   * @param null|Entities\DatabaseStorageFolder $parent
   *
   * @return Entities\DatabaseStorageFolder
   */
  private function folder(string $name, ?Entities\DatabaseStorageFolder $parent):Entities\DatabaseStorageFolder
  {
    $folder = $this->createStub(Entities\DatabaseStorageFolder::class);
    $folder->method('getName')->willReturn($name);
    $folder->method('getParent')->willReturn($parent);
    return $folder;
  }

  /**
   * @param null|Entities\DatabaseStorageFolder $parent
   *
   * @return Entities\DatabaseStorageFile
   */
  private function entry(?Entities\DatabaseStorageFolder $parent):Entities\DatabaseStorageFile
  {
    $entry = $this->createStub(Entities\DatabaseStorageFile::class);
    $entry->method('getParent')->willReturn($parent);
    return $entry;
  }

  /**
   * @param array<Entities\DatabaseStorageFile> $entries
   *
   * @return Entities\EncryptedFile
   */
  private function fileWithEntries(array $entries):Entities\EncryptedFile
  {
    $file = $this->createStub(Entities\EncryptedFile::class);
    $file->method('getDatabaseStorageDirEntries')->willReturn(new ArrayCollection($entries));
    return $file;
  }
}
