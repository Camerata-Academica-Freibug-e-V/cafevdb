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
    $changes = null;
    $failureLogged = false;
    try {
      $changes = $this->entityManager
        ->getRepository(Entities\DatabaseStorageCacheChange::class)
        ->findBy(['storageId' => $shortId], ['id' => 'ASC']);
      if (empty($changes)) {
        return;
      }
      $this->clearPathCache($storage);
      $count = count($changes);
      $consumed = [];
      $operations = [];
      for ($index = 0; $index < $count; ++$index) {
        if (isset($consumed[$index])) {
          continue;
        }
        $change = $changes[$index];
        $path = $change->getPath();
        $oldPath = $change->getOldPath();
        $entryId = $change->getEntryId();
        $changeIds = [$change->getId()];
        $intervening = [];
        $blocked = false;
        $lastMoveIndex = $index;
        for ($next = $index + 1; $oldPath !== null && $next < $count; ++$next) {
          if (isset($consumed[$next])) {
            continue;
          }
          $later = $changes[$next];
          $sameEntry = $entryId !== null && $later->getEntryId() === $entryId;
          // Legacy outbox rows can only be combined when consecutive.
          $legacyAdjacent = $entryId === null && $later->getEntryId() === null && $next === $lastMoveIndex + 1;
          if (($sameEntry || $legacyAdjacent) && $later->getOldPath() === $path) {
            foreach ($intervening as $other) {
              if ($this->changeOverlapsPath($other, $later->getPath())) {
                $blocked = true;
                break 2;
              }
            }
            $path = $later->getPath();
            $changeIds[] = $later->getId();
            $consumed[$next] = true;
            $lastMoveIndex = $next;
            continue;
          }
          if ($sameEntry || $entryId === null
              || $this->changeOverlapsPath($later, $oldPath)
              || $this->changeOverlapsPath($later, $path)) {
            $blocked = true;
            break;
          }
          $intervening[] = $later;
        }
        if ($blocked && $entryId !== null) {
          for (; $next < $count; ++$next) {
            if (!isset($consumed[$next]) && $changes[$next]->getEntryId() === $entryId
                && $changes[$next]->getOldPath() === $path) {
              throw new RuntimeException('Cannot safely reconcile interleaved cache renames for storage "' . $shortId . '".');
            }
          }
        }
        if ($oldPath === $path) {
          $oldPath = null;
        }
        $operations[] = [$path, $oldPath, $changeIds];
      }
      $operations = $this->orderOperations($operations, $shortId);
      foreach ($operations as [$path, $oldPath, $changeIds]) {
        try {
          $this->reconcile($storage, $path, $oldPath);
        } catch (Throwable $t) {
          $this->logger->error(
            'Unable to update the file cache of storage "' . $shortId . '" for path "' . $path
              . '"; outbox row IDs: ' . implode(', ', $changeIds) . ' (pending batch: ' . $count . ' rows).',
            [ 'exception' => $t ],
          );
          $failureLogged = true;
          $unresolved[$shortId][$path] = $oldPath;
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
    } catch (Throwable $t) {
      if (!$failureLogged && $changes !== null) {
        $ids = array_map(fn(Entities\DatabaseStorageCacheChange $change) => $change->getId(), $changes);
        $this->logger->error(
          'Unable to process pending cache changes for storage "' . $shortId . '"; pending batch ('
            . count($ids) . ' rows), outbox row IDs: ' . implode(', ', $ids) . '.',
          [ 'exception' => $t ],
        );
      }
      throw $t;
    } finally {
      unset($this->processing[$shortId]);
    }
  }

  /**
   * Order renames such that a rename vacating a path is applied before
   * another rename moving onto that path.
   *
   * @param array<int, array{0:string, 1:?string, 2:array<int, int>}> $operations
   * @param string $shortId
   *
   * @return array<int, array{0:string, 1:?string, 2:array<int, int>}>
   */
  private function orderOperations(array $operations, string $shortId): array
  {
    $ordered = [];
    $pending = $operations;
    while (!empty($pending)) {
      $progress = false;
      foreach ($pending as $index => [$path, $oldPath]) {
        $blocked = false;
        if ($oldPath !== null) {
          foreach ($pending as $otherIndex => [, $otherOld]) {
            if ($otherIndex !== $index && $otherOld === $path) {
              $blocked = true;
              break;
            }
          }
        }
        if (!$blocked) {
          $ordered[] = $pending[$index];
          unset($pending[$index]);
          $progress = true;
          break;
        }
      }
      if (!$progress) {
        throw new RuntimeException('Cannot safely reconcile cyclic cache renames for storage "' . $shortId . '".');
      }
    }
    return $ordered;
  }

  /**
   * Whether a change affects a different path in the same subtree. Identical
   * paths are not reported as they are handled by re-ordering.
   *
   * @param Entities\DatabaseStorageCacheChange $change
   * @param string $path
   *
   * @return bool
   */
  private function changeOverlapsPath(Entities\DatabaseStorageCacheChange $change, string $path): bool
  {
    foreach ([$change->getPath(), $change->getOldPath()] as $other) {
      if ($other !== null && $other !== $path && ($path === '' || $other === ''
          || str_starts_with($path, $other . '/') || str_starts_with($other, $path . '/'))) {
        return true;
      }
    }
    return false;
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
