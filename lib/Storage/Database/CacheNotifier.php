<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Storage\Database;

use Psr\Log\LoggerInterface as ILogger;
use RuntimeException;
use Throwable;

use OCP\Files\Mount\IMountManager;
use OCP\Files\Storage\IStorage;

use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;

/**
 * Bring Nextcloud's file cache in line with committed changes of
 * database-backed storages. The changes are bypassing Nextcloud's file
 * system API, so the cache has to be notified explicitly.
 *
 * The recorded paths are reconciled against the current database state:
 * existing paths are (re-)scanned, vanished paths are removed and renames
 * keep the file-id of the cache entry. Nextcloud's updater takes care of
 * propagating etag, mtime and size to the ancestors.
 *
 * @see StorageChangeCollector
 */
class CacheNotifier
{
  /** @var array<string, bool> */
  private array $processing = [];

  /**
   * @param ConfigService $configService
   *
   * @param IMountManager $mountManager
   *
   * @param ILogger $logger
   *
   * @param EntityManager $entityManager
   */
  public function __construct(
    private ConfigService $configService,
    private IMountManager $mountManager,
    private ILogger $logger,
    private EntityManager $entityManager,
  ) {
  }

  /**
   * @param array<string, array<string, ?string>> $changes Storage id (short
   * id) => path => old path.
   *
   * @return array<string, array<string, ?string>> The changes which could
   * not be applied.
   */
  public function notify(array $changes): array
  {
    $unresolved = [];
    foreach (array_keys($changes) as $shortId) {
      $mounts = $this->mountManager->findByStorageId(
        $this->configService->getAppName() . Storage::STORAGE_ID_TAG . Storage::PATH_SEPARATOR . $shortId
      );
      $storage = null;
      foreach ($mounts as $mount) {
        $storage = $mount->getStorage();
        break;
      }
      if ($storage === null) {
        continue;
      }
      if (!$storage instanceof Storage) {
        $storage = $this->unwrapStorage($storage);
      }
      if ($storage === null) {
        continue;
      }
      try {
        $this->processPendingChanges($storage, $shortId, $unresolved);
      } catch (Throwable $t) {
        $this->logger->error(
          'Unable to process pending cache changes for storage "' . $shortId . '".',
          [ 'exception' => $t ],
        );
      }
    }
    return $unresolved;
  }

  /**
   * Retry durable cache changes when an authorized storage is accessed.
   *
   * @param Storage $storage
   *
   * @return void
   */
  public function notifyStorage(Storage $storage): void
  {
    $shortId = $storage->getShortId();
    $unresolved = [];
    $this->processPendingChanges($storage, $shortId, $unresolved, true);
  }

  /**
   * Apply pending changes and delete their outbox rows only after all cache
   * operations succeed.
   *
   * @param Storage $storage
   * @param string $shortId
   * @param array<string, array<string, ?string>> $unresolved
   * @param boolean $throwOnFailure
   *
   * @return void
   */
  private function processPendingChanges(
    Storage $storage,
    string $shortId,
    array &$unresolved,
    bool $throwOnFailure = false,
  ): void {
    if (isset($this->processing[$shortId])) {
      return;
    }
    $this->processing[$shortId] = true;
    try {
      $changes = $this->entityManager
        ->getRepository(Entities\DatabaseStorageCacheChange::class)
        ->findBy(['storageId' => $shortId], ['id' => 'ASC']);
      if (empty($changes)) {
        return;
      }
      $this->clearPathCache($storage);
      foreach ($changes as $change) {
        try {
          $this->reconcile($storage, $change->getPath(), $change->getOldPath());
        } catch (Throwable $t) {
          $this->logger->error(
            'Unable to update the file cache of storage "' . $shortId . '" for path "' . $change->getPath() . '".',
            [ 'exception' => $t ],
          );
          $unresolved[$shortId][$change->getPath()] = $change->getOldPath();
          if ($throwOnFailure) {
            throw new RuntimeException(
              'Unable to reconcile pending cache changes for storage "' . $shortId . '".',
              previous: $t,
            );
          }
          return;
        }
      }
      foreach ($changes as $change) {
        $this->entityManager->remove($change);
      }
      $this->entityManager->flush();
    } finally {
      unset($this->processing[$shortId]);
    }
  }

  /**
   * @param IStorage $storage
   *
   * @param string $path
   *
   * @param null|string $oldPath
   *
   * @return void
   */
  private function reconcile(IStorage $storage, string $path, ?string $oldPath): void
  {
    $cache = $storage->getCache();
    $updater = $storage->getUpdater();
    $exists = $storage->file_exists($path);

    if ($oldPath !== null) {
      if ($exists) {
        $updater->renameFromStorage($storage, $oldPath, $path);
      } else {
        $updater->remove($oldPath);
      }
      return;
    }

    if ($exists) {
      $cached = $cache->inCache($path);
      $this->ensureParentIsCached($storage, $path);
      $updater->update($path);
      if ($cached) {
        $this->refreshFileEntry($storage, $path);
      }
    } elseif ($cache->inCache($path)) {
      $updater->remove($path);
    }
  }

  /**
   * Make sure scans start below a known cache entry. Scan progressively
   * shallower ancestors when needed so new nested paths become visible.
   *
   * @param IStorage $storage
   * @param string $path
   *
   * @return void
   */
  private function ensureParentIsCached(IStorage $storage, string $path): void
  {
    if ($path === '') {
      return;
    }
    $cache = $storage->getCache();
    $updater = $storage->getUpdater();
    $parent = dirname($path);
    if ($parent === '.') {
      $parent = '';
    }
    while (!$cache->inCache($parent)) {
      $ancestor = $parent;
      while (!$cache->inCache($ancestor) && $ancestor !== '') {
        $ancestor = dirname($ancestor);
        if ($ancestor === '.') {
          $ancestor = '';
        }
      }
      $updater->update($ancestor);
      if ($ancestor === $parent && !$cache->inCache($parent)) {
        throw new RuntimeException('Unable to populate parent cache for "' . $path . '".');
      }
    }
  }

  /**
   * Nextcloud's scanner reuses the cached size and etag of an entry if its
   * storage_mtime is unchanged. As database storages have a resolution of
   * one second a change of the file contents within the same second as the
   * previous one is thus not detected. Refresh etag, size and mtime
   * explicitly and propagate the size difference to the ancestors.
   *
   * @param IStorage $storage
   *
   * @param string $path
   *
   * @return void
   */
  private function refreshFileEntry(IStorage $storage, string $path): void
  {
    if ($storage->is_dir($path)) {
      return;
    }
    $cache = $storage->getCache();
    $entry = $cache->get($path);
    $size = $storage->filesize($path);
    $mtime = $storage->filemtime($path);
    if (!$entry || $size === false || $mtime === false) {
      return;
    }
    $etag = $storage->getETag($path);
    $cache->update($entry->getId(), [
      'etag' => $etag ?: uniqid(),
      'size' => $size,
      'mtime' => $mtime,
    ]);
  }

  /**
   * @param IStorage $storage Possibly wrapped storage.
   *
   * @return void
   */
  private function clearPathCache(Storage $storage): void
  {
    $storage->clearPathCache();
  }

  /**
   * @param IStorage $storage
   *
   * @return null|Storage
   */
  private function unwrapStorage(IStorage $storage): ?Storage
  {
    while (!$storage instanceof Storage) {
      if (!method_exists($storage, 'getWrapperStorage')) {
        return null;
      }
      $storage = $storage->getWrapperStorage();
    }
    return $storage;
  }
}
