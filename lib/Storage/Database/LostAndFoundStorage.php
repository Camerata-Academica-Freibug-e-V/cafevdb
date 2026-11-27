<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author GitHub Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Storage\Database;

use RuntimeException;
use Throwable;

use OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DatabaseStorageDirEntry;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DatabaseStorageFile;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DatabaseStorageFolder;
use OCA\CAFEVDB\Service\LostAndFoundDeletionGuard;
use OCA\CAFEVDB\Service\LostAndFoundNotificationService;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Platforms\SQLitePlatform;

/** Inspection and conservative deletion of recovered directory trees. */
class LostAndFoundStorage extends Storage
{
  public const SHORT_ID = 'lost+found/';
  public const FOLDER_NAME = 'lost+found';

  /** {@inheritdoc} */
  public function __construct($params)
  {
    parent::__construct($params);
    $this->getRootFolder();
  }

  /** {@inheritdoc} */
  public function getShortId():string
  {
    return self::SHORT_ID;
  }

  /** @return DatabaseStorageFolder */
  public function getCollectionRoot():DatabaseStorageFolder
  {
    $this->rootFolder = null;
    $this->storageEntity = null;
    return $this->getRootFolder(create: true);
  }

  /** {@inheritdoc} */
  public function isCreatable(string $path): bool
  {
    return false;
  }

  /** {@inheritdoc} */
  public function isDeletable(string $path): bool
  {
    $userId = $this->getUserSession()->getUser()?->getUID();
    return $userId !== null && $this->configService->inSubAdminGroup($userId)
      && $this->entityManager->getUserId() === $userId
      && !in_array(trim($path, '/'), ['', '.'], true)
      && $this->file_exists($path);
  }

  /** {@inheritdoc} */
  public function unlink(string $path): bool
  {
    $entry = $this->fileFromFileName($path);
    return $entry instanceof DatabaseStorageFile && $this->deleteEntry($path, $entry);
  }

  /** {@inheritdoc} */
  public function rmdir($path)
  {
    $entry = $this->fileFromFileName($path);
    return $entry instanceof DatabaseStorageFolder && $this->deleteEntry($path, $entry);
  }

  /** {@inheritdoc} */
  public function remove(string $path): bool
  {
    $entry = $this->fileFromFileName($path);
    return $entry instanceof DatabaseStorageDirEntry && $this->deleteEntry($path, $entry);
  }

  /**
   * @param string $path
   * @param DatabaseStorageDirEntry $entry
   *
   * @return boolean
   */
  private function deleteEntry(string $path, DatabaseStorageDirEntry $entry): bool
  {
    if (!$this->isDeletable($path)) {
      return false;
    }
    if ($this->entityManager->isTransactionActive()) {
      throw new RuntimeException('Lost+found deletion requires its own transaction.');
    }
    $connection = $this->entityManager->getConnection();
    $platform = $connection->getDatabasePlatform();
    if ($platform instanceof AbstractMySQLPlatform) {
      $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    } elseif (!$platform instanceof SQLitePlatform) {
      throw new RuntimeException('Lost+found deletion requires MySQL, MariaDB or SQLite.');
    }
    $userId = $this->getUserSession()->getUser()->getUID();
    $this->entityManager->beginTransaction();
    try {
      $guard = new LostAndFoundDeletionGuard($connection);
      $inspection = $guard->inspect((int)$entry->getId());
      if ($inspection['blockers'] !== []) {
        $this->entityManager->rollback();
        $this->notifyBlockedDeletion($userId, $path, (int)$entry->getId(), $inspection['blockers']);
        return false;
      }
      foreach (array_reverse($inspection['entries']) as $row) {
        $node = $this->entityManager->find(DatabaseStorageDirEntry::class, (int)$row['id']);
        if (!$node instanceof DatabaseStorageDirEntry) {
          throw new RuntimeException('A recovered entry disappeared during deletion.');
        }
        $this->entityManager->remove($node);
      }
      $this->entityManager->flush();
      $this->entityManager->commit();
      $this->clearPathCache();
      return true;
    } catch (Throwable $exception) {
      if ($this->entityManager->isTransactionActive()) {
        $this->entityManager->rollback();
      }
      $this->clearPathCache();
      $this->entityManager->clear();
      $this->logException($exception, 'Unable to delete recovered entries.');
      throw $exception;
    }
  }

  /**
   * @param string $userId
   * @param string $path
   * @param integer $entryId
   * @param array $blockers
   *
   * @return void
   */
  private function notifyBlockedDeletion(string $userId, string $path, int $entryId, array $blockers): void
  {
    try {
      $this->di(LostAndFoundNotificationService::class)->notify($userId, $path, $entryId, $blockers);
    } catch (Throwable $exception) {
      $this->logException($exception, 'Unable to notify the user about blocked lost+found deletion.');
    }
  }
}
