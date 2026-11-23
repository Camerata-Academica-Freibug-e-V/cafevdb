<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Storage\Database;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;
use UnexpectedValueException;

use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\LockMode;

/** Read-only finance storage containing links to written debit mandates. */
class DebitMandatesStorage extends Storage
{
  use DatabaseStorageNodeNameTrait;

  /** {@inheritdoc} */
  public function __construct($params)
  {
    parent::__construct($params);
    $this->getRootFolder(create: false);
  }

  /** {@inheritdoc} */
  public function getShortId():string
  {
    return 'finance' . self::PATH_SEPARATOR . 'debit-mandates' . self::PATH_SEPARATOR;
  }

  /** {@inheritdoc} */
  protected function getStorageModificationDateTime():?DateTimeInterface
  {
    return self::ensureDate($this->rootFolder?->getUpdated());
  }

  /**
   * Reconcile the finance tree with the current written mandates.
   *
   * @return void
   */
  public function synchronize():void
  {
    $filterState = $this->disableFilter(EntityManager::SOFT_DELETEABLE_FILTER);
    $this->entityManager->beginTransaction();
    try {
      $root = $this->getRootFolder(create: true);
      $this->entityManager->lock($this->storageEntity, LockMode::PESSIMISTIC_WRITE);
      $now = new DateTimeImmutable();
      $desired = [];
      foreach ($this->getDatabaseRepository(Entities\SepaDebitMandate::class)->findAll() as $mandate) {
        $document = $mandate->getWrittenMandate();
        if ($document === null) {
          continue;
        }
        $file = $document->getFile();
        if ($file === null) {
          throw new UnexpectedValueException('A written debit mandate has no file.');
        }
        $parts = [
          $mandate->getMusician()->getUserIdSlug(),
          $mandate->getProject()->getName(),
        ];
        if ($mandate->getDeleted() !== null && $mandate->getDeleted() <= $now) {
          $parts[] = $this->getAppL10n()->t('Revoked');
        }
        $extension = pathinfo($document->getName(), PATHINFO_EXTENSION);
        $parts[] = $this->getDebitMandateFileName($mandate, $extension !== '' ? $extension : null);
        foreach ($parts as $part) {
          if (!is_string($part) || $part === '' || $part === '.' || $part === '..'
              || str_contains($part, '/') || str_contains($part, '\\')) {
            throw new UnexpectedValueException('A debit mandate has an invalid storage path component.');
          }
        }
        $path = implode(self::PATH_SEPARATOR, $parts);
        if (isset($desired[$path])) {
          throw new UnexpectedValueException('Multiple debit mandates have the same finance storage path.');
        }
        $desired[$path] = ['parts' => $parts, 'file' => $file];
      }

      $existing = [];
      $folders = [];
      $this->collectEntries($root, '', $existing, $folders);
      foreach ($desired as $path => $target) {
        $entry = $existing[$path] ?? null;
        if ($entry !== null) {
          unset($existing[$path]);
          if ($entry->getFile() !== $target['file']) {
            $this->advanceModificationTime($entry, $now);
            $entry->setFile($target['file']);
            $this->markChanged($entry->getParent(), $now);
          }
          continue;
        }
        $parts = $target['parts'];
        $name = array_pop($parts);
        $folder = $root;
        foreach ($parts as $part) {
          $folder = $folder->addSubFolder($part);
          $this->persist($folder);
        }
        $this->persist($folder->addDocument($target['file'], $name));
        $this->markChanged($folder, $now);
      }
      foreach ($existing as $entry) {
        $this->markChanged($entry->getParent(), $now);
        $entry->setFile(null);
        $entry->setParent(null);
        $this->entityManager->remove($entry);
      }
      foreach (array_reverse($folders) as $folder) {
        if ($folder->isEmpty()) {
          $this->markChanged($folder->getParent(), $now);
          $folder->setParent(null);
          $this->entityManager->remove($folder);
        }
      }
      $this->flush();
      $this->entityManager->commit();
      $this->files = [];
    } catch (Throwable $t) {
      if ($this->entityManager->isTransactionActive()) {
        $this->entityManager->rollback();
      }
      throw $t;
    } finally {
      $filterState && $this->enableFilter(EntityManager::SOFT_DELETEABLE_FILTER);
    }
  }

  /**
   * @param Entities\DatabaseStorageFolder $folder
   * @param DateTimeImmutable $now
   *
   * @return void
   */
  private function markChanged(Entities\DatabaseStorageFolder $folder, DateTimeImmutable $now):void
  {
    do {
      $this->advanceModificationTime($folder, $now);
      $folder = $folder->getParent();
    } while ($folder !== null);
  }

  /**
   * @param Entities\DatabaseStorageDirEntry $entry
   * @param DateTimeImmutable $now
   *
   * @return void
   */
  private function advanceModificationTime(Entities\DatabaseStorageDirEntry $entry, DateTimeImmutable $now):void
  {
    $previous = $entry->getUpdated()?->getTimestamp() ?? 0;
    $entry->setUpdated($now->setTimestamp(max($now->getTimestamp(), $previous + 1)));
  }

  /**
   * @param Entities\DatabaseStorageFolder $folder
   * @param string $prefix
   * @param array $entries
   * @param array $folders
   *
   * @return void
   */
  private function collectEntries(Entities\DatabaseStorageFolder $folder, string $prefix, array &$entries, array &$folders):void
  {
    foreach ($folder->getDirectoryEntries() as $entry) {
      $path = $prefix === '' ? $entry->getName() : $prefix . self::PATH_SEPARATOR . $entry->getName();
      if ($entry instanceof Entities\DatabaseStorageFolder) {
        $folders[] = $entry;
        $this->collectEntries($entry, $path, $entries, $folders);
      } elseif ($entry instanceof Entities\DatabaseStorageFile) {
        $entries[$path] = $entry;
      } else {
        throw new UnexpectedValueException('Unexpected debit-mandate storage entry.');
      }
    }
  }
}
