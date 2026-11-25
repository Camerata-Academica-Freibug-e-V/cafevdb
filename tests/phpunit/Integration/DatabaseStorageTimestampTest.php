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

use OC\Files\Cache\Scanner;
use OC\Files\Cache\Watcher;
use OC\Files\Filesystem;
use OC\Files\Mount\MountPoint;
use OC\Files\View;

use OCA\CAFEVDB\Common\TimeFactory;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Storage\Database\InvoicesStorage;
use OCA\CAFEVDB\Storage\Database\ReadMeFactoryInterface;
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
  public function testRevisionAdvancesWithinOneSecond(): void
  {
    $this->now = new DateTimeImmutable('2040-01-01 00:00:00 UTC');
    $this->mockProvider = MockProvider::create($this);
    $clock = $this->createStub(TimeFactory::class);
    $clock->method('now')->willReturnCallback(fn() => $this->now);
    $this->mockProvider->registerClassInstance(TimeFactory::class, $clock, global: true);
    $this->applyMigrations('latest');

    try {
      $root = (new Entities\DatabaseStorageFolder)->setName('');
      $storage = (new Entities\DatabaseStorage)->setStorageId('revision-test')->setRoot($root);
      $root->setStorage($storage);
      $sub = $root->addSubFolder('sub');
      $this->entityManager->persist($root);
      $this->entityManager->persist($storage);
      $this->flushTransaction();
      $storageId = $storage->getId();
      $subId = $sub->getId();
      $this->entityManager->clear();

      $cachedTime = $this->now->getTimestamp();
      $revision = $this->revision($storageId);
      self::assertSame($cachedTime + 1, $revision);
      self::assertGreaterThan($cachedTime, max($cachedTime, $revision));

      // Changes within the same second still advance the revision.
      $this->folder($subId)->setName('first');
      $this->flushTransaction();
      $this->entityManager->clear();
      self::assertSame($revision + 1, $this->revision($storageId));

      $this->folder($subId)->setName('second');
      $this->flushTransaction();
      $this->entityManager->clear();
      self::assertSame($revision + 2, $this->revision($storageId));

      // Rolled-back changes do not advance the revision.
      $this->entityManager->beginTransaction();
      try {
        $this->folder($subId)->setName('rolled-back');
        $this->entityManager->flush();
      } finally {
        $this->entityManager->rollback();
      }
      $this->entityManager->clear();
      self::assertSame($revision + 2, $this->revision($storageId));

      // When the clock catches up, the revision stays ahead of the cached time.
      $this->advanceClock();
      $cachedTime = $this->now->getTimestamp();
      $this->folder($subId)->setName('third');
      $this->flushTransaction();
      $this->entityManager->clear();
      $revision = $this->revision($storageId);
      self::assertSame($cachedTime + 1, $revision);
      self::assertGreaterThan($cachedTime, max($cachedTime, $revision));
    } finally {
      $this->unapplyMigrations();
    }
  }

  /**
   * Exercise same-second cache invalidation through Nextcloud's real view,
   * watcher, scanner and file cache.
   *
   * With the Doctrine clock fixed at T = 2040-01-01 00:00:00 UTC, create a
   * database storage containing branch/deep/document.txt with content "first".
   * Recursively scan it, verify both directories are fully scanned, and save
   * the file's cached mtime and ETag and the root's cached storage_mtime.
   *
   * Replace the file data with "other", keeping both its length and the clock
   * unchanged. After the Doctrine flush, a new storage instance models a new
   * request with an empty in-memory path cache. Its filemtime must still be T,
   * but hasUpdated() must detect the change using the root's cached
   * storage_mtime and the advanced storage-wide revision.
   *
   * Mount the storage and access the root via Nextcloud's view. The watcher
   * performs a shallow scan: branch is marked incomplete (size -1), while
   * the cached deep file still has its original ETag. Accessing branch scans
   * it and marks deep incomplete. Listing deep then scans its immediate
   * contents. The listing must contain the file with a new ETag and the
   * original mtime; reading the storage must return "other".
   *
   * The mtime assertion currently exposes a cache interaction: when only
   * storage_mtime changes, Nextcloud's scanner can omit the unchanged mtime
   * from the cache update, and the cache copies storage_mtime into mtime.
   * Thus the revision can become the client-visible mtime instead of T.
   *
   * The mount and file-cache entries are removed and migrations unapplied in
   * the finally block. The test does not cover concurrent scans or generated
   * in-memory nodes.
   *
   * @return void
   */
  public function testSameSecondChangeThroughShallowScans(): void
  {
    $this->now = new DateTimeImmutable('2040-01-01 00:00:00 UTC');
    $this->mockProvider = MockProvider::create($this);
    $clock = $this->createStub(TimeFactory::class);
    $clock->method('now')->willReturnCallback(fn() => $this->now);
    $this->mockProvider->registerClassInstance(TimeFactory::class, $clock, global: true);
    $this->applyMigrations('latest');

    $storageId = 'integration/shallow-scan/' . bin2hex(random_bytes(8));
    // This prefix avoids initializing unrelated user-specific mount providers.
    $mountPath = '/files_external/cafevdb-shallow-scan-' . bin2hex(random_bytes(8));
    $storage = null;
    $mounted = false;
    try {
      $root = (new Entities\DatabaseStorageFolder)->setName('');
      $storageEntity = (new Entities\DatabaseStorage)->setStorageId($storageId)->setRoot($root);
      $root->setStorage($storageEntity);
      $deep = $root->addSubFolder('branch')->addSubFolder('deep');
      $file = new Entities\EncryptedFile('document.txt', 'first', 'text/plain');
      $deep->addDocument($file);
      $this->entityManager->persist($root);
      $this->entityManager->persist($storageEntity);
      $this->flushTransaction();
      $fileId = $file->getId();
      $this->entityManager->clear();

      $storage = $this->createScanStorage($storageId);
      $cache = $storage->getCache();
      $storage->getScanner()->scan('', Scanner::SCAN_RECURSIVE);
      $initialRoot = $cache->get('');
      $initialBranch = $cache->get('branch');
      $initialDeep = $cache->get('branch/deep');
      $initialFile = $cache->get('branch/deep/document.txt');
      self::assertNotFalse($initialRoot);
      self::assertNotFalse($initialBranch);
      self::assertNotFalse($initialDeep);
      self::assertNotFalse($initialFile);
      self::assertGreaterThanOrEqual(0, $initialBranch['size']);
      self::assertGreaterThanOrEqual(0, $initialDeep['size']);
      $initialMtime = $initialFile['mtime'];
      $initialEtag = $initialFile['etag'];
      $initialRootRevision = $initialRoot['storage_mtime'];

      // Keep the content size and the clock unchanged.
      $file = $this->entityManager->find(Entities\EncryptedFile::class, $fileId);
      $file->getFileData()->setData('other');
      $this->flushTransaction();
      $this->entityManager->clear();

      // A new storage instance models a new request and its empty path cache.
      $storage = $this->createScanStorage($storageId);
      $storage->getWatcher()->setPolicy(Watcher::CHECK_ONCE);
      $cache = $storage->getCache();
      self::assertSame($initialMtime, $storage->filemtime('branch/deep/document.txt'));
      self::assertTrue($storage->hasUpdated('', $initialRootRevision));
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;
      $view = new View($mountPath);

      self::assertNotFalse($view->getFileInfo('/', false));
      self::assertSame(-1, $cache->get('branch')['size']);
      self::assertSame($initialEtag, $cache->get('branch/deep/document.txt')['etag']);

      self::assertNotFalse($view->getFileInfo('/branch', false));
      self::assertSame(-1, $cache->get('branch/deep')['size']);
      $listing = $view->getDirectoryContent('/branch/deep');
      self::assertCount(1, $listing);
      self::assertSame('document.txt', $listing[0]->getName());
      self::assertNotSame($initialEtag, $listing[0]->getEtag());
      self::assertSame($initialMtime, $listing[0]->getMtime());
      self::assertSame('other', $storage->file_get_contents('branch/deep/document.txt'));
    } finally {
      if ($mounted) {
        Filesystem::getMountManager()->removeMount($mountPath);
      }
      if ($storage !== null) {
        $storage->getCache()->remove('');
      }
      $this->unapplyMigrations();
    }
  }

  /**
   * @param string $storageId
   *
   * @return InvoicesStorage
   */
  private function createScanStorage(string $storageId): InvoicesStorage
  {
    $readMeFactory = $this->createStub(ReadMeFactoryInterface::class);
    return new class([
      'configService' => $this->mockProvider->getConfigService(),
      'testStorageId' => $storageId,
      'testReadMeFactory' => $readMeFactory,
    ]) extends InvoicesStorage {
      private string $testStorageId;
      private ReadMeFactoryInterface $testReadMeFactory;

      public function __construct(array $params)
      {
        $this->testStorageId = $params['testStorageId'];
        $this->testReadMeFactory = $params['testReadMeFactory'];
        parent::__construct($params);
      }

      public function getShortId(): string
      {
        return $this->testStorageId;
      }

      protected function getReadMeFactory(): ReadMeFactoryInterface
      {
        return $this->testReadMeFactory;
      }
    };
  }

  /**
   * @param int $storageId
   *
   * @return int
   */
  private function revision(int $storageId): int
  {
    return $this->entityManager->find(Entities\DatabaseStorage::class, $storageId)->getRevision();
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
