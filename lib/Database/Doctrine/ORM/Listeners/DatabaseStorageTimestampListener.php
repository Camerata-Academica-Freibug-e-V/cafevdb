<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Database\Doctrine\ORM\Listeners;

use OCA\CAFEVDB\Common\TimeFactory;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Wrapped\Doctrine\Common\EventSubscriber;
use OCA\CAFEVDB\Wrapped\Doctrine\ORM\EntityManagerInterface;
use OCA\CAFEVDB\Wrapped\Doctrine\ORM\Event\OnFlushEventArgs;
use OCA\CAFEVDB\Wrapped\Doctrine\ORM\Events;
use OCA\CAFEVDB\Wrapped\Doctrine\ORM\UnitOfWork;

/** Propagate database-backed file changes to all affected directory ancestors. */
class DatabaseStorageTimestampListener implements EventSubscriber
{
  /**
   * @param TimeFactory $clock
   * @param null|StorageChangeCollector $collector
   * @param null|string $userId
   */
  public function __construct(
    private TimeFactory $clock,
    private ?StorageChangeCollector $collector = null,
    private ?string $userId = null,
  ) {
  }

  /** {@inheritdoc} */
  public function getSubscribedEvents(): array
  {
    return [Events::onFlush];
  }

  /** {@inheritdoc} */
  public function onFlush(OnFlushEventArgs $args): void
  {
    $entityManager = $args->getObjectManager();
    $unitOfWork = $entityManager->getUnitOfWork();
    $scheduled = array_merge(
      $unitOfWork->getScheduledEntityInsertions(),
      $unitOfWork->getScheduledEntityUpdates(),
      $unitOfWork->getScheduledEntityDeletions(),
    );

    $entries = [];
    $files = [];
    $dataFiles = [];
    foreach ($scheduled as $entity) {
      if ($entity instanceof Entities\DatabaseStorageDirEntry) {
        $entries[spl_object_id($entity)] = $entity;
      } elseif ($entity instanceof Entities\EncryptedFile) {
        $files[spl_object_id($entity)] = $entity;
      } elseif ($entity instanceof Entities\EncryptedFileData) {
        $file = $entity->getFile();
        $files[spl_object_id($file)] = $file;
        $dataFiles[spl_object_id($file)] = $file;
      }
    }

    $linkedEntries = [];
    foreach ($files as $file) {
      foreach ($file->getDatabaseStorageDirEntries() as $entry) {
        $entries[spl_object_id($entry)] = $entry;
        $linkedEntries[spl_object_id($entry)] = $entry;
      }
    }

    $changes = $this->recordChanges($unitOfWork, $entries);
    foreach ($changes as $storageId => $paths) {
      foreach ($paths as $path => $details) {
        $change = new Entities\DatabaseStorageCacheChange($storageId, $path, $details['oldPath'], $details['entryId'], $this->userId);
        $entityManager->persist($change);
        $unitOfWork->computeChangeSet(
          $entityManager->getClassMetadata($change::class),
          $change,
        );
      }
    }

    // Collect both paths before recomputing any change set.
    $folders = [];
    $pending = array_values($entries);
    $visited = [];
    while ($pending) {
      $entry = array_pop($pending);
      $id = spl_object_id($entry);
      if (isset($visited[$id])) {
        continue;
      }
      $visited[$id] = true;
      $parent = $entry->getParent();
      $changeSet = $unitOfWork->getEntityChangeSet($entry);
      $original = $unitOfWork->getOriginalEntityData($entry);
      $oldParent = array_key_exists('parent', $changeSet)
        ? $changeSet['parent'][0]
        : ($original['parent'] ?? null);
      foreach ([$parent, $oldParent] as $folder) {
        if ($folder instanceof Entities\DatabaseStorageFolder) {
          $folders[spl_object_id($folder)] = $folder;
          $pending[] = $folder;
        }
      }
    }

    if (!$folders && !$linkedEntries && !$dataFiles) {
      return;
    }
    $now = $this->clock->now();
    foreach ($dataFiles as $file) {
      if (!$unitOfWork->isScheduledForDelete($file)
          && !array_key_exists('updated', $unitOfWork->getEntityChangeSet($file))) {
        $file->setUpdated($now);
        $this->recompute($unitOfWork, $entityManager, $file);
      }
    }
    foreach ($linkedEntries + $folders as $entry) {
      if (!$unitOfWork->isScheduledForDelete($entry)) {
        $entry->setUpdated($now);
        $this->recompute($unitOfWork, $entityManager, $entry);
      }
    }
  }

  /**
   * Record old and new paths of all changed entries, including all links of
   * changed files, before any change set is recomputed.
   *
   * @param UnitOfWork $unitOfWork
   * @param array<int, Entities\DatabaseStorageDirEntry> $entries
   *
   * @return array<string, array<string, ?string>>
   */
  private function recordChanges(UnitOfWork $unitOfWork, array $entries): array
  {
    $changes = [];
    foreach ($entries as $entry) {
      $changeSet = $unitOfWork->getEntityChangeSet($entry);
      $original = $unitOfWork->getOriginalEntityData($entry);
      $inserted = $unitOfWork->isScheduledForInsert($entry);
      $deleted = $unitOfWork->isScheduledForDelete($entry);

      $oldParent = array_key_exists('parent', $changeSet)
        ? $changeSet['parent'][0]
        : ($original['parent'] ?? null);
      $oldName = array_key_exists('name', $changeSet)
        ? $changeSet['name'][0]
        : ($original['name'] ?? null);
      $parent = $entry->getParent();

      $oldPath = null;
      $storageId = null;
      if (!$inserted && $oldParent instanceof Entities\DatabaseStorageFolder) {
        $oldPath = $this->path($oldParent, $oldName);
        $storageId = $oldParent->getRoot()->getAttachedStorage()?->getStorageId();
      }
      $newPath = null;
      if (!$deleted) {
        if ($parent instanceof Entities\DatabaseStorageFolder) {
          $newPath = $this->path($parent, $entry->getName());
          $storageId = $parent->getRoot()->getAttachedStorage()?->getStorageId();
        } elseif ($entry instanceof Entities\DatabaseStorageFolder && $entry->getAttachedStorage() !== null) {
          // root folder
          $newPath = '';
          $storageId = $entry->getAttachedStorage()->getStorageId();
        }
      }

      if ($storageId === null) {
        continue;
      }
      if ($newPath === null) {
        if ($oldPath !== null) {
          $changes[$storageId][$oldPath] = null;
          $this->collector?->record($storageId, $oldPath);
        }
      } else {
        $moveFrom = $oldPath !== $newPath ? $oldPath : null;
        $changes[$storageId][$newPath] = $moveFrom;
        $this->collector?->record($storageId, $newPath, $moveFrom);
      }
    }
    return $changes;
  }

  /**
   * @param Entities\DatabaseStorageFolder $parent
   * @param null|string $name
   *
   * @return string Path below the storage root without leading slash.
   */
  private function path(Entities\DatabaseStorageFolder $parent, ?string $name): string
  {
    return ltrim($parent->getPathName() . '/' . ($name ?? ''), '/');
  }

  /**
   * @param UnitOfWork $unitOfWork
   * @param EntityManagerInterface $entityManager
   * @param object $entity
   *
   * @return void
   */
  private function recompute(UnitOfWork $unitOfWork, EntityManagerInterface $entityManager, object $entity): void
  {
    $unitOfWork->recomputeSingleEntityChangeSet(
      $entityManager->getClassMetadata($entity::class),
      $entity,
    );
  }
}
