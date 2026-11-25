<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Command;

use OCP\IL10N;
use Psr\Log\LoggerInterface as ILogger;
use OCP\IUserSession;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Storage\Database\CacheNotifier;

/**
 * List, process or clear the outbox of pending file-cache updates of the
 * database file-system backend.
 */
class DatabaseStorageCacheOutbox extends Command
{
  use AuthenticatedCommandTrait;

  /** {@inheritdoc} */
  public function __construct(
    protected string $appName,
    protected IL10N $l,
    protected ILogger $logger,
    protected IUserManager $userManager,
    protected IUserSession $userSession,
    protected ContainerInterface $appContainer,
  ) {
    parent::__construct();
  }

  /** {@inheritdoc} */
  protected function configure()
  {
    $this
      ->setName($this->appName . ':storage:cache-outbox')
      ->setDescription('List, process or clear pending file-cache updates of the DB File-System Backend')
      ->addOption(
        'storage',
        's',
        InputOption::VALUE_REQUIRED,
        'Restrict the operation to the given storage id.',
      )
      ->addOption(
        'summary',
        null,
        InputOption::VALUE_NONE,
        'Only show the number of pending changes and the oldest creation time per storage.',
      )
      ->addOption(
        'json',
        null,
        InputOption::VALUE_NONE,
        'Print the listing as JSON.',
      )
      ->addOption(
        'process',
        'p',
        InputOption::VALUE_NONE,
        'Try to apply the pending changes now. Requires a mounted storage.',
      )
      ->addOption(
        'clear',
        'c',
        InputOption::VALUE_NONE,
        'Delete pending changes without applying them. Requires --storage or --all. '
        . 'Rescan the affected storages afterwards.',
      )
      ->addOption(
        'all',
        'a',
        InputOption::VALUE_NONE,
        'Apply --clear to the changes of all storages.',
      )
      ->addOption(
        'dry-run',
        null,
        InputOption::VALUE_NONE,
        'With --clear: only report what would be deleted.',
      )
      ->addOption(
        'force',
        'f',
        InputOption::VALUE_NONE,
        'With --clear: do not ask for confirmation.',
      )
      ;
  }

  /** {@inheritdoc} */
  protected function execute(InputInterface $input, OutputInterface $output):int
  {
    $result = $this->authenticate($input, $output);
    if ($result != 0) {
      return $result;
    }

    $storageId = $input->getOption('storage');
    $clear = $input->getOption('clear');
    $process = $input->getOption('process');

    if ($clear && $process) {
      $output->writeln('<error>--clear and --process are mutually exclusive.</error>');
      return 1;
    }
    if ($clear && $storageId === null && !$input->getOption('all')) {
      $output->writeln('<error>--clear requires --storage=<id> or --all.</error>');
      return 1;
    }

    $criteria = $storageId === null ? [] : [ 'storageId' => $storageId ];
    /** @var array<int, Entities\DatabaseStorageCacheChange> $changes */
    $changes = $this->getDatabaseRepository(Entities\DatabaseStorageCacheChange::class)
      ->findBy($criteria, [ 'storageId' => 'ASC', 'id' => 'ASC' ]);

    if ($clear) {
      return $this->clearChanges($input, $output, $changes);
    }
    if ($process) {
      return $this->processChanges($output, $changes);
    }
    $this->listChanges($input, $output, $changes);
    return 0;
  }

  /**
   * @param InputInterface $input
   * @param OutputInterface $output
   * @param array<int, Entities\DatabaseStorageCacheChange> $changes
   *
   * @return void
   */
  private function listChanges(InputInterface $input, OutputInterface $output, array $changes): void
  {
    if (empty($changes)) {
      $output->writeln('No pending cache changes.');
      return;
    }
    if ($input->getOption('summary')) {
      $summary = [];
      foreach ($changes as $change) {
        $storageId = $change->getStorageId();
        $created = $change->getCreated()?->format('Y-m-d H:i:s');
        $summary[$storageId] ??= [ 'storage' => $storageId, 'count' => 0, 'oldest' => $created ];
        ++$summary[$storageId]['count'];
        if ($created !== null && ($summary[$storageId]['oldest'] === null || $created < $summary[$storageId]['oldest'])) {
          $summary[$storageId]['oldest'] = $created;
        }
      }
      $rows = array_values($summary);
    } else {
      $rows = array_map(fn(Entities\DatabaseStorageCacheChange $change) => [
        'id' => $change->getId(),
        'storage' => $change->getStorageId(),
        'entry' => $change->getEntryId(),
        'oldPath' => $change->getOldPath(),
        'path' => $change->getPath(),
        'created' => $change->getCreated()?->format('Y-m-d H:i:s'),
        'createdBy' => $change->getCreatedBy(),
      ], $changes);
    }
    if ($input->getOption('json')) {
      $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      return;
    }
    $table = new Table($output);
    $table->setHeaders(array_keys($rows[0]));
    $table->setRows(array_map(fn(array $row) => array_map(fn($value) => $value ?? '', $row), $rows));
    $table->render();
  }

  /**
   * @param InputInterface $input
   * @param OutputInterface $output
   * @param array<int, Entities\DatabaseStorageCacheChange> $changes
   *
   * @return int
   */
  private function clearChanges(InputInterface $input, OutputInterface $output, array $changes): int
  {
    if (empty($changes)) {
      $output->writeln('No pending cache changes.');
      return 0;
    }
    $count = count($changes);
    if ($input->getOption('dry-run')) {
      $output->writeln(sprintf('Would delete %d pending cache changes.', $count));
      return 0;
    }
    if (!$input->getOption('force')) {
      $output->writeln(
        '<comment>Deleting pending changes leaves the file cache stale. '
        . 'Rescan the affected storages afterwards; renamed entries will get new file ids.</comment>'
      );
      $question = new ConfirmationQuestion(sprintf('Delete %d pending cache changes? [y/N] ', $count), false);
      if (!$this->getHelper('question')->ask($input, $output, $question)) {
        $output->writeln('Aborted.');
        return 1;
      }
    }
    foreach ($changes as $change) {
      $this->entityManager->remove($change);
    }
    $this->flush();
    $output->writeln(sprintf('Deleted %d pending cache changes. Please rescan the affected storages.', $count));
    return 0;
  }

  /**
   * @param OutputInterface $output
   * @param array<int, Entities\DatabaseStorageCacheChange> $changes
   *
   * @return int
   */
  private function processChanges(OutputInterface $output, array $changes): int
  {
    $storageIds = array_values(array_unique(array_map(
      fn(Entities\DatabaseStorageCacheChange $change) => $change->getStorageId(),
      $changes,
    )));
    if (empty($storageIds)) {
      $output->writeln('No pending cache changes.');
      return 0;
    }
    /** @var CacheNotifier $notifier */
    $notifier = $this->appContainer->get(CacheNotifier::class);
    $notifier->notify(array_fill_keys($storageIds, []));

    $this->entityManager->clear();
    $status = 0;
    foreach ($storageIds as $storageId) {
      $remaining = count($this->getDatabaseRepository(Entities\DatabaseStorageCacheChange::class)
        ->findBy([ 'storageId' => $storageId ]));
      if ($remaining > 0) {
        $status = 1;
        $output->writeln(sprintf(
          '<error>Storage "%s": %d changes remain (storage not mounted or reconciliation failed, see the log).</error>',
          $storageId,
          $remaining,
        ));
      } else {
        $output->writeln(sprintf('Storage "%s": all pending changes applied.', $storageId));
      }
    }
    return $status;
  }
}
