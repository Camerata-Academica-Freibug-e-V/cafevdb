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

use Closure;
use Psr\Log\LoggerInterface;

/**
 * Collect the paths of database-backed storages changed by flushes of the
 * current transaction. Consumers are called with the collected changes after
 * the outermost commit; the changes are dropped on rollback.
 *
 * The collected paths are only hints about where to look: consumers have to
 * reconcile against the committed database state. Thus changes recorded by
 * an inner transaction level which was rolled back are harmless.
 *
 * A change with an old path denotes a rename or move of that path. For
 * folders the entire subtree is affected. Ancestors of the recorded paths
 * are not recorded explicitly.
 *
 * @phpstan-type StorageChanges array<string, array<string, ?string>>
 */
class StorageChangeCollector
{
  /** @var StorageChanges storage id => path => old path */
  private array $changes = [];

  /** @var array<int, Closure(StorageChanges):void> */
  private array $consumers = [];

  /** @param LoggerInterface $logger */
  public function __construct(private LoggerInterface $logger)
  {
  }

  /**
   * @param Closure $consumer Called after the
   * outermost commit with the changes of that transaction.
   *
   * @return void
   */
  public function addConsumer(Closure $consumer): void
  {
    $this->consumers[] = $consumer;
  }

  /**
   * @param string $storageId The storage id string of the changed storage.
   *
   * @param string $path Path relative to the storage root without leading
   * slash, the empty string denotes the root.
   *
   * @param null|string $oldPath The former path in case of rename or move.
   *
   * @return void
   */
  public function record(string $storageId, string $path, ?string $oldPath = null): void
  {
    $known = $this->changes[$storageId] ?? [];
    // Keep the oldest path of a chain of moves within one transaction.
    if (array_key_exists($path, $known) && $known[$path] !== null) {
      return;
    }
    $this->changes[$storageId][$path] = $oldPath;
  }

  /** @return bool Whether there are changes waiting for commit. */
  public function hasChanges(): bool
  {
    return !empty($this->changes);
  }

  /**
   * @return StorageChanges Changes collected so far.
   */
  public function getChanges(): array
  {
    return $this->changes;
  }

  /**
   * Hand the collected changes to the consumers after the outermost commit.
   * Consumer failures are not propagated, the commit has already happened.
   *
   * @return void
   */
  public function commit(): void
  {
    $changes = $this->changes;
    $this->changes = [];
    if (empty($changes)) {
      return;
    }
    foreach ($this->consumers as $consumer) {
      try {
        $consumer($changes);
      } catch (\Throwable $t) {
        $this->logger->error('Unable to notify storage cache changes after commit.', [ 'exception' => $t ]);
      }
    }
  }

  /**
   * Drop all collected changes, e.g. after the outermost rollback.
   *
   * @return void
   */
  public function discard(): void
  {
    $this->changes = [];
  }
}
