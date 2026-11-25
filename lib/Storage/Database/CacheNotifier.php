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
use Throwable;

use OCP\Files\Mount\IMountManager;
use OCP\Files\Storage\IStorage;

use OCA\CAFEVDB\Service\ConfigService;

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
  /**
   * @param ConfigService $configService
   *
   * @param IMountManager $mountManager
   *
   * @param ILogger $logger
   */
  public function __construct(
    private ConfigService $configService,
    private IMountManager $mountManager,
    private ILogger $logger,
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
    foreach ($changes as $shortId => $paths) {
      $mounts = $this->mountManager->findByStorageId(
        $this->configService->getAppName() . Storage::STORAGE_ID_TAG . Storage::PATH_SEPARATOR . $shortId
      );
      $storage = null;
      foreach ($mounts as $mount) {
        $storage = $mount->getStorage();
        break;
      }
      if ($storage === null) {
        // Nothing is mounted, so there is nothing to refresh in this
        // request; the cache of the storage is validated on next access.
        $unresolved[$shortId] = $paths;
        continue;
      }
      $this->clearPathCache($storage);
      foreach ($paths as $path => $oldPath) {
        try {
          $this->reconcile($storage, (string)$path, $oldPath);
        } catch (Throwable $t) {
          $this->logger->error('Unable to update the file cache of storage "' . $shortId . '" for path "' . $path . '".', [ 'exception' => $t ]);
          $unresolved[$shortId][$path] = $oldPath;
        }
      }
    }
    return $unresolved;
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
      // Never scan below a parent which is unknown to the cache, otherwise
      // the entry would be orphaned.
      $cached = $cache->inCache($path);
      if ($path === '' || $cached || $cache->inCache(dirname($path) === '.' ? '' : dirname($path))) {
        $updater->update($path);
        if ($cached) {
          $this->refreshFileEntry($storage, $path);
        }
      }
    } elseif ($cache->inCache($path)) {
      $updater->remove($path);
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
    $storage->getUpdater()->getPropagator()->propagateChange($path, time(), $size - $entry->getSize());
  }

  /**
   * @param IStorage $storage Possibly wrapped storage.
   *
   * @return void
   */
  private function clearPathCache(IStorage $storage): void
  {
    while ($storage !== null) {
      if ($storage instanceof Storage) {
        $storage->clearPathCache();
        return;
      }
      $storage = method_exists($storage, 'getWrapperStorage') ? $storage->getWrapperStorage() : null;
    }
  }
}
