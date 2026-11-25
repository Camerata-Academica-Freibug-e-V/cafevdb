<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Integration;

use DateTimeImmutable;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;

use OCA\CAFEVDB\Common\TimeFactory;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Tests\MockProvider;
use OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations\SetupMigrationTrait;

/** Exercise timestamp propagation through the real Doctrine flush. */
class DatabaseStorageTimestampTest extends TestCase
{
  use SetupMigrationTrait;

  private DateTimeImmutable $now;

  /** @return void */
  public function testPropagationAndRollback(): void
  {
    $this->now = new DateTimeImmutable('2040-01-01 00:00:00 UTC');
    $this->mockProvider = MockProvider::create($this);
    $clock = $this->createStub(TimeFactory::class);
    $clock->method('now')->willReturnCallback(fn() => $this->now);
    $this->mockProvider->registerClassInstance(TimeFactory::class, $clock, global: true);
    $this->applyMigrations('latest');

    try {
      $root = (new Entities\DatabaseStorageFolder)->setName('root');
      $left = $root->addSubFolder('left');
      $deep = $left->addSubFolder('deep');
      $right = $root->addSubFolder('right');
      $untouched = $root->addSubFolder('untouched');
      $this->entityManager->persist($root);
      $this->flushTransaction();
      $ids = [
        'root' => $root->getId(),
        'left' => $left->getId(),
        'deep' => $deep->getId(),
        'right' => $right->getId(),
        'untouched' => $untouched->getId(),
      ];
      $this->entityManager->clear();

      $this->advanceClock();
      $file = new Entities\EncryptedFile('a.txt', 'first', 'text/plain');
      $entry = $this->folder($ids['deep'])->addDocument($file);
      $this->entityManager->persist($entry);
      $this->flushTransaction();
      $entryId = $entry->getId();
      $fileId = $file->getId();
      $this->entityManager->clear();
      $this->assertFoldersUpdated($ids, ['root', 'left', 'deep']);

      $this->advanceClock();
      $file = $this->entityManager->find(Entities\EncryptedFile::class, $fileId);
      $otherEntry = $this->folder($ids['right'])->addDocument($file, 'b.txt');
      $this->entityManager->persist($otherEntry);
      $this->flushTransaction();
      $otherEntryId = $otherEntry->getId();
      $this->entityManager->clear();
      $this->assertFoldersUpdated($ids, ['root', 'right']);

      // Same-sized content changes need not schedule the file before Gedmo runs.
      $this->advanceClock();
      $file = $this->entityManager->find(Entities\EncryptedFile::class, $fileId);
      $file->getFileData()->setData('other');
      $this->flushTransaction();
      $this->entityManager->clear();
      $this->assertFoldersUpdated($ids, ['root', 'left', 'deep', 'right']);
      foreach ([$entryId, $otherEntryId] as $id) {
        $entry = $this->entityManager->find(Entities\DatabaseStorageFile::class, $id);
        $updated = new ReflectionProperty(Entities\DatabaseStorageDirEntry::class, 'updated');
        self::assertEquals($this->now->getTimestamp(), $updated->getValue($entry)->getTimestamp());
      }
      $file = $this->entityManager->find(Entities\EncryptedFile::class, $fileId);
      self::assertEquals($this->now->getTimestamp(), $file->getUpdated()->getTimestamp());

      $this->advanceClock();
      $entry = $this->entityManager->find(Entities\DatabaseStorageFile::class, $entryId);
      $entry->setName('moved.txt')->setParent($this->folder($ids['right']));
      $this->flushTransaction();
      $this->entityManager->clear();
      $this->assertFoldersUpdated($ids, ['root', 'left', 'deep', 'right']);

      $this->advanceClock();
      $entry = $this->entityManager->find(Entities\DatabaseStorageFile::class, $entryId);
      $entry->setParent(null);
      $entry->setFile(null);
      $this->entityManager->remove($entry);
      $this->flushTransaction();
      $this->entityManager->clear();
      $this->assertFoldersUpdated($ids, ['root', 'right']);

      $this->advanceClock();
      $entry = $this->entityManager->find(Entities\DatabaseStorageFile::class, $otherEntryId);
      $entry->setName('renamed.txt');
      $this->folder($ids['deep'])->setName('renamed-deep');
      $this->flushTransaction();
      $this->entityManager->clear();
      $this->assertFoldersUpdated($ids, ['root', 'left', 'deep', 'right']);

      $this->advanceClock();
      $this->folder($ids['deep'])->setParent($this->folder($ids['right']));
      $this->flushTransaction();
      $this->entityManager->clear();
      $this->assertFoldersUpdated($ids, ['root', 'left', 'deep', 'right']);

      $this->advanceClock();
      $this->entityManager->beginTransaction();
      try {
        $entry = $this->entityManager->find(Entities\DatabaseStorageFile::class, $otherEntryId);
        $entry->setName('rolled-back.txt');
        $this->folder($ids['deep'])->setName('rolled-back-deep');
        $this->entityManager->flush();
      } finally {
        $this->entityManager->rollback();
      }
      $this->entityManager->clear();
      $this->assertFoldersUpdated($ids, []);
      $entry = $this->entityManager->find(Entities\DatabaseStorageFile::class, $otherEntryId);
      self::assertSame('renamed.txt', $entry->getName());

      $this->advanceClock();
      $deep = $this->folder($ids['deep']);
      $deep->setParent(null);
      $this->entityManager->remove($deep);
      $this->flushTransaction();
      $this->entityManager->clear();
      unset($ids['deep']);
      $this->assertFoldersUpdated($ids, ['root', 'right']);
    } finally {
      $this->unapplyMigrations();
    }
  }

  /** @return void */
  private function advanceClock(): void
  {
    $this->now = $this->now->modify('+1 minute');
  }

  /** @return void */
  private function flushTransaction(): void
  {
    $this->entityManager->beginTransaction();
    try {
      $this->entityManager->flush();
      $this->entityManager->commit();
    } catch (\Throwable $exception) {
      if ($this->entityManager->isOwnTransactionActive()) {
        $this->entityManager->rollback();
      }
      throw $exception;
    }
  }

  /**
   * @param int $id
   *
   * @return Entities\DatabaseStorageFolder
   */
  private function folder(int $id): Entities\DatabaseStorageFolder
  {
    return $this->entityManager->find(Entities\DatabaseStorageFolder::class, $id);
  }

  /**
   * @param array<string, int> $ids
   * @param list<string> $updated
   *
   * @return void
   */
  private function assertFoldersUpdated(array $ids, array $updated): void
  {
    foreach ($ids as $name => $id) {
      $actual = $this->folder($id)->getUpdated()->getTimestamp();
      if (in_array($name, $updated, true)) {
        self::assertSame($this->now->getTimestamp(), $actual, $name);
      } else {
        self::assertLessThan($this->now->getTimestamp(), $actual, $name);
      }
    }
  }
}
