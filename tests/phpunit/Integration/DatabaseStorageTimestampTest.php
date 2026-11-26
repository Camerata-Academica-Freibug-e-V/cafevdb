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
use RuntimeException;
use PHPUnit\Framework\TestCase;

use OC\Files\Cache\Scanner;
use OC\Files\Cache\Watcher;
use OC\Files\Filesystem;
use OC\Files\Mount\MountPoint;
use OC\Files\View;

use OCP\Files\Mount\IMountManager;
use Psr\Log\LoggerInterface;

use OCA\CAFEVDB\Common\TimeFactory;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\DatabaseStorageTimestampListener;
use OCA\CAFEVDB\PageRenderer\DatabaseTables;
use OCA\CAFEVDB\Storage\Database\CacheNotifier;
use OCA\CAFEVDB\Storage\Database\InvoicesStorage;
use OCA\CAFEVDB\Storage\Database\ReadMeFactoryInterface;
use OCA\CAFEVDB\Tests\MockProvider;
use OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations\SetupMigrationTrait;
use OCA\CAFEVDB\Wrapped\Doctrine\ORM\Events;

/** Exercise timestamp propagation through the real Doctrine flush. */
class DatabaseStorageTimestampTest extends TestCase
{
  use SetupMigrationTrait;

  private DateTimeImmutable $now;

  /** @return void */
  public function testCacheChangeMigrationPreservesLegacyRows(): void
  {
    $this->applyMigrations('20261125155000');
    try {
      $this->entityManager->getConnection()->executeStatement(
        'INSERT INTO ' . DatabaseTables::DATABASE_STORAGE_CACHE_CHANGES_TABLE . ' (storage_id, path, old_path) VALUES (?, ?, ?)',
        ['legacy', 'new.txt', 'old.txt'],
      );
      $this->applyMigrations('latest');
      $changes = $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
        ->findBy(['storageId' => 'legacy']);
      self::assertCount(1, $changes);
      self::assertNull($changes[0]->getEntryId());
      self::assertSame('old.txt', $changes[0]->getOldPath());
      self::assertSame('new.txt', $changes[0]->getPath());
      self::assertNotNull($changes[0]->getCreated());
      self::assertSame('unknown', $changes[0]->getCreatedBy());
    } finally {
      $this->unapplyMigrations();
    }
  }

  /** @return void */
  public function testOutboxCreationTimeIsGeneratedByDatabase(): void
  {
    $this->applyMigrations('latest');
    $storageId = 'integration/outbox-created/' . bin2hex(random_bytes(8));
    try {
      $connection = $this->entityManager->getConnection();
      $before = $connection->fetchOne('SELECT CURRENT_TIMESTAMP');

      $root = (new Entities\DatabaseStorageFolder)->setName('');
      $storage = (new Entities\DatabaseStorage)->setStorageId($storageId)->setRoot($root);
      $root->setStorage($storage);
      $root->addDocument(new Entities\EncryptedFile('document.txt', 'content', 'text/plain'));
      $this->entityManager->persist($root);
      $this->entityManager->persist($storage);
      $this->flushTransaction();

      $direct = new Entities\DatabaseStorageCacheChange($storageId, 'direct.txt');
      $this->entityManager->persist($direct);
      $this->flushTransaction();
      self::assertNotNull($direct->getCreated(), 'Doctrine must fetch the generated value after insertion.');
      $after = $connection->fetchOne('SELECT CURRENT_TIMESTAMP');
      $this->entityManager->clear();

      $changes = $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
        ->findBy(['storageId' => $storageId], ['id' => 'ASC']);
      self::assertGreaterThan(1, count($changes), 'The listener must record at least one outbox row.');
      foreach ($changes as $change) {
        self::assertNotNull($change->getCreated());
        $expectedUser = $change->getPath() === 'direct.txt'
          ? 'unknown'
          : ($this->mockProvider->getEncryptionService()->getUserId() ?: 'unknown');
        self::assertSame($expectedUser, $change->getCreatedBy());
        $created = $change->getCreated()->format('Y-m-d H:i:s');
        self::assertGreaterThanOrEqual($before, $created);
        self::assertLessThanOrEqual($after, $created);
      }
    } finally {
      $this->unapplyMigrations();
    }
  }

  /** @return void */
  public function testOutboxRecordsListenerIdentityAndUnknownFallback(): void
  {
    $this->applyMigrations('latest');
    $events = $this->entityManager->getEventManager();
    $original = null;
    foreach ($events->getListeners(Events::onFlush) as $listener) {
      if ($listener instanceof DatabaseStorageTimestampListener) {
        $original = $listener;
        break;
      }
    }
    self::assertNotNull($original);
    $events->removeEventSubscriber($original);
    $listener = null;
    try {
      foreach (['operator', null] as $userId) {
        $listener = new DatabaseStorageTimestampListener(
          $this->mockProvider->getAppContainer()->get(TimeFactory::class),
          userId: $userId,
        );
        $events->addEventSubscriber($listener);
        $storageId = 'integration/outbox-user/' . bin2hex(random_bytes(8));
        $root = (new Entities\DatabaseStorageFolder)->setName('');
        $storage = (new Entities\DatabaseStorage)->setStorageId($storageId)->setRoot($root);
        $root->setStorage($storage);
        $root->addDocument(new Entities\EncryptedFile('document.txt', 'content', 'text/plain'));
        $this->entityManager->persist($root);
        $this->entityManager->persist($storage);
        $this->flushTransaction();
        $this->entityManager->clear();

        $changes = $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]);
        self::assertNotEmpty($changes);
        foreach ($changes as $change) {
          self::assertSame($userId ?? 'unknown', $change->getCreatedBy());
        }
        $events->removeEventSubscriber($listener);
        $listener = null;
      }
    } finally {
      if ($listener !== null) {
        $events->removeEventSubscriber($listener);
      }
      $events->addEventSubscriber($original);
      $this->unapplyMigrations();
    }
  }

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

  /**
   * Exercise same-second cache invalidation through Nextcloud's real view,
   * watcher, scanner and file cache.
   *
   * With the Doctrine clock fixed at T = 2040-01-01 00:00:00 UTC, create a
   * database storage containing branch/deep/document.txt with content "first".
   * Recursively scan it, verify both directories are fully scanned, and save
   * the file's cached mtime and ETag.
   *
   * Replace the file data with "other", keeping both its length and the clock
   * unchanged while the storage is not mounted. The committed change must
   * remain in the durable queue until a new authorized storage instance
   * encounters it. The cached file and subsequent Nextcloud listing must
   * have a new ETag and the original mtime; reading must return "other".
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
      $initialRootMtime = $initialRoot['storage_mtime'];
      // Keep the content size and the clock unchanged.
      $file = $this->entityManager->find(Entities\EncryptedFile::class, $fileId);
      $file->getFileData()->setData('other');
      $this->flushTransaction();
      $this->entityManager->clear();
      self::assertSame($initialEtag, $cache->get('branch/deep/document.txt')['etag']);
      self::assertSame($initialMtime, $cache->get('branch/deep/document.txt')['mtime']);
      self::assertNotEmpty(
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );

      // An authorized mount in the next request drains the durable queue.
      $storage = $this->createScanStorage($storageId);
      $storage->getWatcher()->setPolicy(Watcher::CHECK_ONCE);
      $cache = $storage->getCache();
      self::assertSame($initialMtime, $storage->filemtime('branch/deep/document.txt'));
      self::assertFalse($storage->hasUpdated('', $initialRootMtime));
      self::assertNotSame($initialEtag, $cache->get('branch/deep/document.txt')['etag']);
      self::assertSame($initialMtime, $cache->get('branch/deep/document.txt')['mtime']);
      self::assertSame(
        [],
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;
      $view = new View($mountPath);

      self::assertNotFalse($view->getFileInfo('/', false));
      self::assertNotFalse($view->getFileInfo('/branch', false));
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
   * Exercise create, mkdir, move, rename and delete in separate commits within
   * one second.
   *
   * @return void
   */
  public function testSameSecondCreateFolderMoveRenameAndDelete(): void
  {
    $this->now = new DateTimeImmutable('2040-01-01 00:00:00 UTC');
    $this->mockProvider = MockProvider::create($this);
    $clock = $this->createStub(TimeFactory::class);
    $clock->method('now')->willReturnCallback(fn() => $this->now);
    $this->mockProvider->registerClassInstance(TimeFactory::class, $clock, global: true);
    $this->mockProvider->registerClassInstance(IMountManager::class, Filesystem::getMountManager(), global: true);
    $this->applyMigrations('latest');

    $storageId = 'integration/create-move/' . bin2hex(random_bytes(8));
    $mountPath = '/files_external/cafevdb-create-move-' . bin2hex(random_bytes(8));
    $storage = null;
    $mounted = false;
    try {
      $root = (new Entities\DatabaseStorageFolder)->setName('');
      $storageEntity = (new Entities\DatabaseStorage)->setStorageId($storageId)->setRoot($root);
      $root->setStorage($storageEntity);
      $this->entityManager->persist($root);
      $this->entityManager->persist($storageEntity);
      $this->flushTransaction();
      $rootId = $root->getId();
      $this->entityManager->clear();

      $storage = $this->createScanStorage($storageId);
      $cache = $storage->getCache();
      $storage->getScanner()->scan('', Scanner::SCAN_RECURSIVE);
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;
      $view = new View($mountPath);
      self::assertNotEmpty(Filesystem::getMountManager()->findByStorageId($storage->getId()));
      $root = $this->folder($rootId);
      $entry = $root->addDocument(new Entities\EncryptedFile('document.txt', 'first', 'text/plain'));
      $this->entityManager->persist($entry);
      $this->flushTransaction();
      $entryId = $entry->getId();
      $pending = $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
        ->findBy(['storageId' => $storageId]);
      self::assertSame([], $pending, 'Mounted storage cache updates should be processed after commit.');
      $cachedFile = $cache->get('document.txt');
      self::assertNotFalse($cachedFile);
      $fileId = $cachedFile->getId();
      self::assertSame($this->now->getTimestamp(), $cachedFile->getMtime());
      self::assertSame('document.txt', $view->getDirectoryContent('/')[0]->getName());

      $folder = $root->addSubFolder('sub');
      $this->entityManager->persist($folder);
      $this->flushTransaction();
      self::assertNotFalse($cache->get('sub'));

      $entry->setParent($folder);
      $this->flushTransaction();
      self::assertFalse($cache->get('document.txt'));
      $movedFile = $cache->get('sub/document.txt');
      self::assertNotFalse($movedFile);
      self::assertSame($fileId, $movedFile->getId());
      self::assertSame($this->now->getTimestamp(), $movedFile->getMtime());
      self::assertSame($this->now->getTimestamp(), $this->entityManager->find(
        Entities\DatabaseStorageFile::class,
        $entryId,
      )->getUpdated()->getTimestamp());
      $listing = $view->getDirectoryContent('/sub');
      self::assertCount(1, $listing);
      self::assertSame('document.txt', $listing[0]->getName());
      self::assertSame($fileId, $listing[0]->getId());
      self::assertSame('first', $storage->file_get_contents('sub/document.txt'));

      $entry->setName('renamed.txt');
      $this->flushTransaction();
      self::assertFalse($cache->get('sub/document.txt'));
      $renamedFile = $cache->get('sub/renamed.txt');
      self::assertNotFalse($renamedFile);
      self::assertSame($fileId, $renamedFile->getId());
      self::assertSame($this->now->getTimestamp(), $renamedFile->getMtime());

      $this->entityManager->beginTransaction();
      try {
        $entry->setName('intermediate.txt');
        $this->entityManager->flush();
        $entry->setName('final.txt');
        $this->entityManager->flush();
        self::assertNotFalse($cache->get('sub/renamed.txt'));
        $this->entityManager->commit();
      } finally {
        if ($this->entityManager->isOwnTransactionActive()) {
          $this->entityManager->rollback();
        }
      }
      self::assertFalse($cache->get('sub/renamed.txt'));
      self::assertFalse($cache->get('sub/intermediate.txt'));
      $finalFile = $cache->get('sub/final.txt');
      self::assertNotFalse($finalFile);
      self::assertSame($fileId, $finalFile->getId());

      $this->entityManager->beginTransaction();
      try {
        $entry->setName('intermediate.txt');
        $this->entityManager->flush();
        $entry->setName('final.txt');
        $this->entityManager->flush();
        $this->entityManager->commit();
      } finally {
        if ($this->entityManager->isOwnTransactionActive()) {
          $this->entityManager->rollback();
        }
      }
      self::assertFalse($cache->get('sub/intermediate.txt'));
      $finalFile = $cache->get('sub/final.txt');
      self::assertNotFalse($finalFile);
      self::assertSame($fileId, $finalFile->getId());

      $entry->setParent(null);
      $entry->setFile(null);
      $this->entityManager->remove($entry);
      $this->flushTransaction();
      self::assertFalse($cache->get('sub/final.txt'));
      self::assertSame([], $view->getDirectoryContent('/sub'));
    } finally {
      Entities\DatabaseStorageFile::clearOrphans();
      if ($mounted) {
        Filesystem::getMountManager()->removeMount($mountPath);
      }
      if ($storage !== null) {
        $storage->getCache()->remove('');
      }
      $this->unapplyMigrations();
    }
  }

  /** @return void */
  public function testSameSecondChangesRefreshEveryLinkedFile(): void
  {
    $this->now = new DateTimeImmutable('2040-01-01 00:00:00 UTC');
    $this->mockProvider = MockProvider::create($this);
    $clock = $this->createStub(TimeFactory::class);
    $clock->method('now')->willReturnCallback(fn() => $this->now);
    $this->mockProvider->registerClassInstance(TimeFactory::class, $clock, global: true);
    $this->mockProvider->registerClassInstance(IMountManager::class, Filesystem::getMountManager(), global: true);
    $this->applyMigrations('latest');

    $storageId = 'integration/linked-files/' . bin2hex(random_bytes(8));
    $mountPath = '/files_external/cafevdb-linked-files-' . bin2hex(random_bytes(8));
    $storage = null;
    $mounted = false;
    try {
      $root = (new Entities\DatabaseStorageFolder)->setName('');
      $storageEntity = (new Entities\DatabaseStorage)->setStorageId($storageId)->setRoot($root);
      $root->setStorage($storageEntity);
      $folder = $root->addSubFolder('sub');
      $file = new Entities\EncryptedFile('document.txt', 'first', 'text/plain');
      $root->addDocument($file);
      $folder->addDocument($file, 'linked.txt');
      $this->entityManager->persist($root);
      $this->entityManager->persist($storageEntity);
      $this->flushTransaction();

      $storage = $this->createScanStorage($storageId);
      $cache = $storage->getCache();
      $storage->getScanner()->scan('', Scanner::SCAN_RECURSIVE);
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;
      $paths = ['document.txt', 'sub/linked.txt'];
      $ids = [];
      $etags = [];
      foreach ($paths as $path) {
        $cached = $cache->get($path);
        self::assertNotFalse($cached);
        $ids[$path] = $cached->getId();
        $etags[$path] = $cached->getEtag();
      }
      $initialRootSize = $cache->get('')->getSize();
      $initialSubSize = $cache->get('sub')->getSize();

      foreach (['other', 'longer content'] as $contents) {
        $file->setSize(strlen($contents));
        $file->getFileData()->setData($contents);
        $this->flushTransaction();
        foreach ($paths as $path) {
          $cached = $cache->get($path);
          self::assertNotFalse($cached);
          self::assertSame($ids[$path], $cached->getId());
          self::assertNotSame($etags[$path], $cached->getEtag());
          self::assertSame(strlen($contents), $cached->getSize());
          self::assertSame($this->now->getTimestamp(), $cached->getMtime());
          self::assertSame($contents, $storage->file_get_contents($path));
          $etags[$path] = $cached->getEtag();
        }
        $difference = strlen($contents) - strlen('first');
        self::assertSame($initialRootSize + 2 * $difference, $cache->get('')->getSize());
        self::assertSame($initialSubSize + $difference, $cache->get('sub')->getSize());
      }

      $cache->remove('sub');
      self::assertFalse($cache->get('sub'));
      $file->setSize(strlen('after cache removal'));
      $file->getFileData()->setData('after cache removal');
      $this->flushTransaction();
      self::assertNotFalse($cache->get('sub'));
      $cached = $cache->get('sub/linked.txt');
      self::assertNotFalse($cached);
      self::assertSame(strlen('after cache removal'), $cached->getSize());
      self::assertSame('after cache removal', $storage->file_get_contents('sub/linked.txt'));
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

  /** @return void */
  public function testDeferredInterleavedRenamesPreserveFileIds(): void
  {
    $this->now = new DateTimeImmutable('2040-01-01 00:00:00 UTC');
    $this->mockProvider = MockProvider::create($this);
    $clock = $this->createStub(TimeFactory::class);
    $clock->method('now')->willReturnCallback(fn() => $this->now);
    $this->mockProvider->registerClassInstance(TimeFactory::class, $clock, global: true);
    $this->mockProvider->registerClassInstance(IMountManager::class, Filesystem::getMountManager(), global: true);
    $this->applyMigrations('latest');

    $storageId = 'integration/interleaved-renames/' . bin2hex(random_bytes(8));
    $mountPath = '/files_external/cafevdb-interleaved-renames-' . bin2hex(random_bytes(8));
    $storage = null;
    $mounted = false;
    try {
      $root = (new Entities\DatabaseStorageFolder)->setName('');
      $storageEntity = (new Entities\DatabaseStorage)->setStorageId($storageId)->setRoot($root);
      $root->setStorage($storageEntity);
      $first = $root->addDocument(new Entities\EncryptedFile('a.txt', 'first', 'text/plain'));
      $second = $root->addDocument(new Entities\EncryptedFile('x.txt', 'second', 'text/plain'));
      $this->entityManager->persist($root);
      $this->entityManager->persist($storageEntity);
      $this->flushTransaction();

      $storage = $this->createScanStorage($storageId);
      $cache = $storage->getCache();
      $storage->getScanner()->scan('', Scanner::SCAN_RECURSIVE);
      $firstCached = $cache->get('a.txt');
      $secondCached = $cache->get('x.txt');
      self::assertNotFalse($firstCached);
      self::assertNotFalse($secondCached);
      $firstId = $firstCached->getId();
      $secondId = $secondCached->getId();

      // Separate commits while the storage is unmounted leave ordered outbox work.
      $first->setName('b.txt');
      $this->flushTransaction();
      $second->setName('y.txt');
      $this->flushTransaction();
      $first->setName('c.txt');
      $this->flushTransaction();
      $pending = $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
        ->findBy(['storageId' => $storageId], ['id' => 'ASC']);
      self::assertSame(
        [['a.txt', 'b.txt'], ['x.txt', 'y.txt'], ['b.txt', 'c.txt']],
        array_map(fn($change) => [$change->getOldPath(), $change->getPath()], $pending),
      );
      self::assertSame(
        [$first->getId(), $second->getId(), $first->getId()],
        array_map(fn($change) => $change->getEntryId(), $pending),
      );
      self::assertNotFalse($cache->get('a.txt'));
      self::assertNotFalse($cache->get('x.txt'));

      $storage = $this->createScanStorage($storageId);
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;
      $storage->hasUpdated('', $this->now->getTimestamp());
      self::assertFalse($cache->get('a.txt'));
      self::assertFalse($cache->get('b.txt'));
      self::assertFalse($cache->get('x.txt'));
      $firstCached = $cache->get('c.txt');
      $secondCached = $cache->get('y.txt');
      self::assertNotFalse($firstCached);
      self::assertNotFalse($secondCached);
      self::assertSame($firstId, $firstCached->getId());
      self::assertSame($secondId, $secondCached->getId());
      self::assertSame('first', $storage->file_get_contents('c.txt'));
      self::assertSame('second', $storage->file_get_contents('y.txt'));

      // Reusing a path vacated by another chain needs ordered application.
      Filesystem::getMountManager()->removeMount($mountPath);
      $mounted = false;
      $first->setName('d.txt');
      $this->flushTransaction();
      $second->setName('c.txt');
      $this->flushTransaction();
      $first->setName('e.txt');
      $this->flushTransaction();
      $storage = $this->createScanStorage($storageId);
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;
      $storage->hasUpdated('', $this->now->getTimestamp());
      // The rename vacating "c.txt" must be applied before the one reusing it.
      self::assertFalse($cache->get('d.txt'));
      self::assertFalse($cache->get('y.txt'));
      self::assertSame($firstId, $cache->get('e.txt')->getId());
      self::assertSame($secondId, $cache->get('c.txt')->getId());
      self::assertSame('first', $storage->file_get_contents('e.txt'));
      self::assertSame('second', $storage->file_get_contents('c.txt'));
      self::assertCount(
        0,
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );
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

  /** @return void */
  public function testCacheNotificationWaitsForOutermostCommitAndDiscardsRollback(): void
  {
    $this->now = new DateTimeImmutable('2040-01-01 00:00:00 UTC');
    $this->mockProvider = MockProvider::create($this);
    $clock = $this->createStub(TimeFactory::class);
    $clock->method('now')->willReturnCallback(fn() => $this->now);
    $this->mockProvider->registerClassInstance(TimeFactory::class, $clock, global: true);
    $this->mockProvider->registerClassInstance(IMountManager::class, Filesystem::getMountManager(), global: true);
    $this->applyMigrations('latest');

    $storageId = 'integration/transactions/' . bin2hex(random_bytes(8));
    $mountPath = '/files_external/cafevdb-transactions-' . bin2hex(random_bytes(8));
    $storage = null;
    $mounted = false;
    try {
      $root = (new Entities\DatabaseStorageFolder)->setName('');
      $storageEntity = (new Entities\DatabaseStorage)->setStorageId($storageId)->setRoot($root);
      $root->setStorage($storageEntity);
      $this->entityManager->persist($root);
      $this->entityManager->persist($storageEntity);
      $this->flushTransaction();
      $storage = $this->createScanStorage($storageId);
      $cache = $storage->getCache();
      $storage->getScanner()->scan('', Scanner::SCAN_RECURSIVE);
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;

      $this->entityManager->beginTransaction();
      $this->entityManager->beginTransaction();
      $entry = $root->addDocument(new Entities\EncryptedFile('committed.txt', 'first', 'text/plain'));
      $this->entityManager->persist($entry);
      $this->entityManager->flush();
      $this->entityManager->commit();
      self::assertFalse($cache->get('committed.txt'));
      self::assertNotEmpty(
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );
      $this->entityManager->commit();
      self::assertNotFalse($cache->get('committed.txt'));
      self::assertSame(
        [],
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );

      $this->entityManager->beginTransaction();
      $this->entityManager->beginTransaction();
      $entry = $root->addDocument(new Entities\EncryptedFile('rolled-back.txt', 'first', 'text/plain'));
      $this->entityManager->persist($entry);
      $this->entityManager->flush();
      $this->entityManager->commit();
      self::assertFalse($cache->get('rolled-back.txt'));
      $this->entityManager->rollback();
      self::assertSame(
        [],
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );
      self::assertFalse($cache->get('rolled-back.txt'));
      self::assertNotFalse($cache->get('committed.txt'));
    } finally {
      while ($this->entityManager->isOwnTransactionActive()) {
        $this->entityManager->rollback();
      }
      if ($mounted) {
        Filesystem::getMountManager()->removeMount($mountPath);
      }
      if ($storage !== null) {
        $storage->getCache()->remove('');
      }
      $this->unapplyMigrations();
    }
  }

  /** @return void */
  public function testFailedNotificationIsRetriedOnLaterStorageAccess(): void
  {
    $this->now = new DateTimeImmutable('2040-01-01 00:00:00 UTC');
    $this->mockProvider = MockProvider::create($this);
    $clock = $this->createStub(TimeFactory::class);
    $clock->method('now')->willReturnCallback(fn() => $this->now);
    $this->mockProvider->registerClassInstance(TimeFactory::class, $clock, global: true);
    $this->mockProvider->registerClassInstance(IMountManager::class, Filesystem::getMountManager(), global: true);
    $this->applyMigrations('latest');

    $storageId = 'integration/retry/' . bin2hex(random_bytes(8));
    $mountPath = '/files_external/cafevdb-retry-' . bin2hex(random_bytes(8));
    $storage = null;
    $mounted = false;
    try {
      $root = (new Entities\DatabaseStorageFolder)->setName('');
      $storageEntity = (new Entities\DatabaseStorage)->setStorageId($storageId)->setRoot($root);
      $root->setStorage($storageEntity);
      $this->entityManager->persist($root);
      $this->entityManager->persist($storageEntity);
      $this->flushTransaction();
      $storage = $this->createScanStorage($storageId, failPath: 'retry.txt');
      $cache = $storage->getCache();
      $storage->getScanner()->scan('', Scanner::SCAN_RECURSIVE);
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;

      $entry = $root->addDocument(new Entities\EncryptedFile('retry.txt', 'first', 'text/plain'));
      $this->entityManager->persist($entry);
      $this->flushTransaction();
      self::assertFalse($cache->get('retry.txt'));
      self::assertNotEmpty(
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );

      Filesystem::getMountManager()->removeMount($mountPath);
      $mounted = false;
      $storage = $this->createScanStorage($storageId);
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;
      $storage->hasUpdated('', $this->now->getTimestamp());
      self::assertNotFalse($storage->getCache()->get('retry.txt'));
      self::assertSame(
        [],
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );
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

  /** @return void */
  public function testNotifyStorageIdsProcessesOnlyMountedStorages(): void
  {
    $this->mockProvider = MockProvider::create($this);
    $this->mockProvider->registerClassInstance(IMountManager::class, Filesystem::getMountManager(), global: true);
    $this->applyMigrations('latest');
    $storageId = 'integration/notify-ids/' . bin2hex(random_bytes(8));
    $mountPath = '/files_external/cafevdb-notify-ids-' . bin2hex(random_bytes(8));
    $storage = null;
    $mounted = false;
    try {
      $root = (new Entities\DatabaseStorageFolder)->setName('');
      $storageEntity = (new Entities\DatabaseStorage)->setStorageId($storageId)->setRoot($root);
      $root->setStorage($storageEntity);
      $this->entityManager->persist($root);
      $this->entityManager->persist($storageEntity);
      $this->flushTransaction();
      $storage = $this->createScanStorage($storageId);
      $cache = $storage->getCache();
      $storage->getScanner()->scan('', Scanner::SCAN_RECURSIVE);

      $entry = $root->addDocument(new Entities\EncryptedFile('pending.txt', 'content', 'text/plain'));
      $this->entityManager->persist($entry);
      $this->flushTransaction();
      $notifier = $this->mockProvider->getAppContainer()->get(CacheNotifier::class);
      $notifier->notifyStorageIds([$storageId]);
      self::assertNotEmpty(
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );
      self::assertFalse($cache->get('pending.txt'));

      $storage = $this->createScanStorage($storageId, failPath: 'pending.txt');
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;
      self::assertArrayHasKey($storageId, $notifier->notifyStorageIds([$storageId]));
      self::assertNotEmpty(
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );

      Filesystem::getMountManager()->removeMount($mountPath);
      $mounted = false;
      $storage = $this->createScanStorage($storageId);
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;
      self::assertSame([], $notifier->notifyStorageIds([$storageId]));
      self::assertNotFalse($cache->get('pending.txt'));
      self::assertSame(
        [],
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );
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

  /** @return void */
  public function testStorageAccessRetriesAfterPartialCacheFailure(): void
  {
    $this->now = new DateTimeImmutable('2040-01-01 00:00:00 UTC');
    $this->mockProvider = MockProvider::create($this);
    $clock = $this->createStub(TimeFactory::class);
    $clock->method('now')->willReturnCallback(fn() => $this->now);
    $this->mockProvider->registerClassInstance(TimeFactory::class, $clock, global: true);
    $this->mockProvider->registerClassInstance(IMountManager::class, Filesystem::getMountManager(), global: true);
    $this->applyMigrations('latest');

    $storageId = 'integration/partial-retry/' . bin2hex(random_bytes(8));
    $mountPath = '/files_external/cafevdb-partial-retry-' . bin2hex(random_bytes(8));
    $storage = null;
    $mounted = false;
    try {
      $root = (new Entities\DatabaseStorageFolder)->setName('');
      $storageEntity = (new Entities\DatabaseStorage)->setStorageId($storageId)->setRoot($root);
      $root->setStorage($storageEntity);
      $this->entityManager->persist($root);
      $this->entityManager->persist($storageEntity);
      $this->flushTransaction();
      $storage = $this->createScanStorage($storageId);
      $cache = $storage->getCache();
      $storage->getScanner()->scan('', Scanner::SCAN_RECURSIVE);

      $first = $root->addDocument(new Entities\EncryptedFile('first.txt', 'first', 'text/plain'));
      $second = $root->addDocument(new Entities\EncryptedFile('second.txt', 'second', 'text/plain'));
      $this->entityManager->persist($first);
      $this->entityManager->persist($second);
      $this->flushTransaction();
      $storage = $this->createScanStorage($storageId, failPath: 'second.txt');
      Filesystem::getMountManager()->addMount(new MountPoint($storage, $mountPath));
      $mounted = true;

      for ($attempt = 0; $attempt < 2; ++$attempt) {
        try {
          $storage->hasUpdated('', $this->now->getTimestamp());
          self::fail('A failed reconciliation must fail storage access.');
        } catch (RuntimeException $exception) {
          self::assertStringContainsString('Unable to reconcile pending cache changes', $exception->getMessage());
        }
        self::assertNotFalse($cache->get('first.txt'));
        self::assertFalse($cache->get('second.txt'));
        self::assertCount(
          2,
          $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
            ->findBy(['storageId' => $storageId]),
        );
      }

      $firstId = $cache->get('first.txt')->getId();
      $storage->setTestFailPath(null);
      $storage->hasUpdated('', $this->now->getTimestamp());
      self::assertSame($firstId, $cache->get('first.txt')->getId());
      self::assertNotFalse($cache->get('second.txt'));
      self::assertSame(
        [],
        $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
          ->findBy(['storageId' => $storageId]),
      );
      $view = new View($mountPath);
      self::assertCount(2, $view->getDirectoryContent('/'));
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

  /** @return void */
  public function testReconciliationFailuresLogOutboxRowIds(): void
  {
    $this->applyMigrations('latest');
    try {
      foreach (['operation', 'planner'] as $failure) {
        $storageId = 'integration/outbox-logs/' . bin2hex(random_bytes(8));
        $moves = $failure === 'operation'
          ? [['a.txt', 'b.txt', 1], ['b.txt', 'c.txt', 1]]
          : [['a.txt', 'b.txt', 1], ['b.txt', 'a.txt', 2]];
        $rows = [];
        foreach ($moves as [$oldPath, $path, $entryId]) {
          $row = new Entities\DatabaseStorageCacheChange($storageId, $path, $oldPath, $entryId);
          $this->entityManager->persist($row);
          $rows[] = $row;
        }
        $other = new Entities\DatabaseStorageCacheChange($storageId, 'other.txt');
        $this->entityManager->persist($other);
        $rows[] = $other;
        $this->flushTransaction();
        $ids = array_map(fn(Entities\DatabaseStorageCacheChange $row) => $row->getId(), $rows);
        $messages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message, array $context = []) use (&$messages): void {
          $messages[] = [$message, $context];
        });
        $notifier = new CacheNotifier(
          $this->mockProvider->getConfigService(),
          Filesystem::getMountManager(),
          $logger,
          $this->entityManager,
        );
        $storage = $this->createScanStorage($storageId, failPath: 'c.txt');
        try {
          $notifier->notifyStorage($storage);
          self::fail('Reconciliation should fail.');
        } catch (RuntimeException $exception) {
          self::assertStringContainsString(
            $failure === 'operation' ? 'Unable to reconcile' : 'cyclic cache renames',
            $exception->getMessage(),
          );
        }
        self::assertCount(1, $messages);
        self::assertStringContainsString($storageId, $messages[0][0]);
        $loggedIds = $failure === 'operation' ? array_slice($ids, 0, 2) : $ids;
        self::assertStringContainsString(
          'outbox row IDs: ' . implode(', ', $loggedIds) . ($failure === 'operation' ? ' (pending' : '.'),
          $messages[0][0],
        );
        self::assertStringContainsString($failure === 'operation' ? 'pending batch: 3 rows' : 'pending batch (3 rows)', $messages[0][0]);
        self::assertArrayHasKey('exception', $messages[0][1]);
        self::assertCount(
          3,
          $this->entityManager->getRepository(Entities\DatabaseStorageCacheChange::class)
            ->findBy(['storageId' => $storageId]),
        );
      }
    } finally {
      $this->unapplyMigrations();
    }
  }

  /**
   * @param string $storageId
   * @param null|string $failPath Simulate a transient storage error for this path.
   *
   * @return InvoicesStorage
   */
  private function createScanStorage(string $storageId, ?string $failPath = null): InvoicesStorage
  {
    $readMeFactory = $this->createStub(ReadMeFactoryInterface::class);
    return new class([
      'configService' => $this->mockProvider->getConfigService(),
      'testStorageId' => $storageId,
      'testReadMeFactory' => $readMeFactory,
      'testFailPath' => $failPath,
    ]) extends InvoicesStorage {
      private string $testStorageId;
      private ReadMeFactoryInterface $testReadMeFactory;
      private ?string $testFailPath;

      public function __construct(array $params)
      {
        $this->testStorageId = $params['testStorageId'];
        $this->testReadMeFactory = $params['testReadMeFactory'];
        $this->testFailPath = $params['testFailPath'];
        parent::__construct($params);
      }

      // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
      public function file_exists(string $path): bool
      {
        if ($path === $this->testFailPath) {
          throw new RuntimeException('Simulated storage error');
        }
        return parent::file_exists($path);
      }

      public function getShortId(): string
      {
        return $this->testStorageId;
      }

      public function setTestFailPath(?string $path): void
      {
        $this->testFailPath = $path;
      }

      protected function getReadMeFactory(): ReadMeFactoryInterface
      {
        return $this->testReadMeFactory;
      }
    };
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
