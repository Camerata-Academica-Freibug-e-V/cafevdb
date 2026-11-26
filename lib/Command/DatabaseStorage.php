<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022, 2023, 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\CAFEVDB\Command;

use RuntimeException;

use OCP\IL10N;
use Psr\Log\LoggerInterface as ILogger;
use OCP\IUserSession;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use OCP\IDateTimeFormatter;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatter;

use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Database\Doctrine\DBAL\Types\EnumProjectTemporalType;
use OCA\CAFEVDB\Crypto\AsymmetricKeyStorageInterface;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\OrphanTreeService;
use OCA\CAFEVDB\Service\ParticipantFileAuditService;
use OCA\CAFEVDB\Service\ParticipantFileRepairService;
use OCA\CAFEVDB\Service\ProjectService;
use OCA\CAFEVDB\Settings\ConfigConstants;

/** Maintenance for the database file-system backend. */
class DatabaseStorage extends Command
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
      ->setName($this->appName . ':storage:database')
      ->setDescription('Maintenance for DB File-System Backend')
      ->addOption(
        'audit-access',
        null,
        InputOption::VALUE_NONE,
        'Read-only audit of musician file ownership and participant links.',
      )
      ->addOption('audit-encryption', null, InputOption::VALUE_NONE, 'Read-only audit of encryption seals and user key indicators.')
      ->addOption('repair-access', null, InputOption::VALUE_NONE, 'Backfill audit-approved participant links and ownership; does not reconcile seals.')
      ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview --repair-access without changing records.')
      ->addOption('json', null, InputOption::VALUE_NONE, 'Output the audit, repair report or orphan-tree listing as JSON.')
      ->addOption(
        'include-file-names',
        null,
        InputOption::VALUE_NONE,
        'Include names and orphan parent paths (full mounted paths for the access audit).',
      )
      ->addOption('include-field-names', null, InputOption::VALUE_NONE, 'Include potentially identifying participant field names in the access audit.')
      ->addOption(
        'find-orphan-trees',
        null,
        InputOption::VALUE_OPTIONAL,
        'List orphan directory-entry trees; use =prune-empty to remove complete folder-only trees.',
        false,
      )
      ->addOption(
        'list-storages',
        'l',
        InputOption::VALUE_OPTIONAL,
        'List all storages. The optional value is a regular expression matching the storage id.',
        false,
      )
      ->addOption(
        'find-orphans',
        'f',
        InputOption::VALUE_NONE,
        'Find orphaned file-nodes, i.e. those not connected to a directory entry.',
      )
      ->addOption(
        'remove-orphans',
        'r',
        InputOption::VALUE_NONE,
        'Remove orphaned file-node, i.e. those not connected to a directory entry.'
      )
      ;
  }

  /** {@inheritdoc} */
  protected function execute(InputInterface $input, OutputInterface $output):int
  {
    $audit = $input->getOption('audit-access');
    $auditEncryption = $input->getOption('audit-encryption');
    $repair = $input->getOption('repair-access');
    $orphanMode = $input->getOption('find-orphan-trees');
    $orphanTrees = $orphanMode !== false;
    if (($orphanTrees && !in_array($orphanMode, [null, 'prune-empty'], true))
        || ($audit && $auditEncryption)
        || ($repair && ($audit || $auditEncryption || $orphanTrees || $input->getOption('list-storages') !== false
                     || $input->getOption('find-orphans') || $input->getOption('remove-orphans')
                     || $input->getOption('include-file-names') || $input->getOption('include-field-names')))
        || ($input->getOption('dry-run') && !$repair)
        || ($input->getOption('json') && !$audit && !$auditEncryption && !$orphanTrees && !$repair)
        || ($input->getOption('include-file-names') && !$audit && !$orphanTrees)
        || ($input->getOption('include-field-names') && !$audit)
        || ($orphanTrees && ($audit || $auditEncryption || $input->getOption('list-storages') !== false
                            || $input->getOption('find-orphans') || $input->getOption('remove-orphans')))
        || (($audit || $auditEncryption) && ($input->getOption('list-storages') !== false
                      || $input->getOption('find-orphans') || $input->getOption('remove-orphans')))) {
      $output->writeln(
        '<error>Use --audit-access, --audit-encryption, --repair-access or --find-orphan-trees[=prune-empty] alone, optionally with --json. '
        . '--include-file-names requires --audit-access or --find-orphan-trees; --include-field-names requires --audit-access. '
        . '--dry-run requires --repair-access.</error>',
      );
      return self::INVALID;
    }
    $authenticationOutput = ($audit || $auditEncryption || $orphanTrees || $repair) && $input->getOption('json') && $output instanceof ConsoleOutputInterface
      ? $output->getErrorOutput()
      : $output;
    $result = $this->authenticate($input, $authenticationOutput);
    if ($result != 0) {
      return $result;
    }
    if ($audit) {
      return $this->auditAccess($input, $output);
    }
    if ($auditEncryption) {
      return $this->auditEncryption($input, $output);
    }
    if ($repair) {
      return $this->repairAccess($input, $output);
    }
    if ($orphanTrees) {
      return $this->findOrphanTrees($input, $output);
    }
    $storageWildcard = $input->getOption('list-storages');
    if ($storageWildcard !== false) {
      if ($storageWildcard !== null) {
        $storages = $this->getDatabaseRepository(Entities\DatabaseStorage::class)->findBy([
          "storageId#REGEXP(%s, '" . $storageWildcard . "')" => 1,
        ]);
      } else {
        $storages = $this->getDatabaseRepository(Entities\DatabaseStorage::class)->findAll();
      }
      $output->writeln('Registered Storages: ' . count($storages));
      $output->writeln('');
      $storages = array_map(fn(Entities\DatabaseStorage $storage) => [ 'storageId' => $storage->getStorageId(), 'root' => $storage->getRoot() ], $storages);
      $storages = array_column($storages, 'root', 'storageId');
      ksort($storages);
      foreach (array_keys($storages) as $storageId) {
        $output->writeln($storageId);
      }
      $output->writeln('');
    } elseif ($input->getOption('find-orphans')) {
      $files = $this->getDatabaseRepository(Entities\EncryptedFile::class)->findAll();

      $orphans = [];

      /** @var Entities\EncryptedFile $file */
      foreach ($files as $file) {
        if ($file->getDatabaseStorageDirEntries()->count() == 0) {
          $orphans[] = $file;
        }
      }

      if (count($orphans) == 0) {
        $output->writeln('No orphans found.');
      } else {
        $output->writeln(sprintf('Found %s orphans.', count($orphans)));
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
          /** @var IDateTimeFormatter $formatter */
          $formatter = $this->appContainer->get(IDateTimeFormatter::class);
          foreach ($orphans as $file) {
            $output->writeln(
              implode(', ', [
                $file->getId(),
                $file->getFileName(),
                'size ' . $file->getSize() . ' bytes',
                $file->getMimeType(),
                'last modified ' . $formatter->formatDate($file->getUpdated()),
                'created at ' . $formatter->formatDate($file->getCreated()),
              ])
            );
          }
        }
      }
    } elseif ($input->getOption('remove-orphans')) {
      $files = $this->getDatabaseRepository(Entities\EncryptedFile::class)->findAll();

      $orphans = [];

      /** @var Entities\EncryptedFile $file */
      foreach ($files as $file) {
        if ($file->getDatabaseStorageDirEntries()->count() == 0) {
          $orphans[] = $file;
        }
      }

      if (count($orphans) == 0) {
        $output->writeln('No orphans found.');
        return 0;
      }

      foreach ($orphans as $file) {
        $fileId = $file->getId();
        $this->remove($file, flush: true);
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
          $output->writeln(sprintf('Removed file "%s" with id "%d".', $file->getFilename(), $fileId));
        }
      }
      $output->writeln('Removed ' . count($orphans) . ' orphans.');
    }
    return 0;
  }

  /**
   * @param InputInterface $input
   * @param OutputInterface $output
   *
   * @return int
   */
  private function findOrphanTrees(InputInterface $input, OutputInterface $output): int
  {
    $pruneEmpty = $input->getOption('find-orphan-trees') === 'prune-empty';
    $service = new OrphanTreeService($this->entityManager->getConnection());
    try {
      $report = $service->inspect($input->getOption('include-file-names'), $pruneEmpty);
    } finally {
      if ($pruneEmpty) {
        $this->entityManager->clear();
      }
    }
    if ($input->getOption('json')) {
      $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    } else {
      $output->writeln($pruneEmpty
        ? 'Storage-less directory-entry trees after pruning empty trees.'
        : 'Storage-less directory-entry trees (read-only; includes soft-deleted records).');
      $output->writeln(json_encode($report['summary'], JSON_THROW_ON_ERROR));
      if ($pruneEmpty) {
        $output->writeln('Pruned roots: ' . json_encode($report['pruned_root_ids'], JSON_THROW_ON_ERROR));
      }
      foreach ($report['trees'] as $tree) {
        $output->writeln('Root ' . $tree['root_id'] . ': ' . count($tree['entries']) . ' entries');
        foreach ($tree['entries'] as $entry) {
          $output->writeln(OutputFormatter::escape(json_encode($entry, JSON_THROW_ON_ERROR)));
        }
      }
    }
    return self::SUCCESS;
  }

  /**
   * @param InputInterface $input
   * @param OutputInterface $output
   *
   * @return int
   */
  private function auditAccess(InputInterface $input, OutputInterface $output): int
  {
    $config = $this->appContainer->get(ConfigService::class);
    $clubProjectId = (int)$config->getConfigValue(ConfigConstants::CLUB_MEMBER_PROJECT_ID_KEY, 0);
    $includeFileNames = $input->getOption('include-file-names');
    $report = (new ParticipantFileAuditService($this->entityManager->getConnection(), $clubProjectId))->audit(
      $includeFileNames,
      $includeFileNames ? $this->auditMountPaths($config) : [],
      $input->getOption('include-field-names'),
    );
    if ($input->getOption('json')) {
      $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    } else {
      $output->writeln('Participant file access audit (read-only; includes soft-deleted records).');
      $output->writeln('<info>Summary</info>');
      $output->writeln(json_encode($report['summary'], JSON_THROW_ON_ERROR));
      $output->writeln('');
      $output->writeln('<info>Structural access findings</info>');
      if ($report['findings'] === []) {
        $output->writeln('<comment>No structural access findings.</comment>');
      }
      foreach ($report['findings'] as $finding) {
        $output->writeln(OutputFormatter::escape(json_encode($finding, JSON_THROW_ON_ERROR)));
      }
    }
    return $report['findings'] === [] ? self::SUCCESS : self::FAILURE;
  }

  /**
   * @param InputInterface $input
   * @param OutputInterface $output
   *
   * @return int
   */
  private function auditEncryption(InputInterface $input, OutputInterface $output): int
  {
    $config = $this->appContainer->get(ConfigService::class);
    $clubProjectId = (int)$config->getConfigValue(ConfigConstants::CLUB_MEMBER_PROJECT_ID_KEY, 0);
    $audit = (new ParticipantFileAuditService($this->entityManager->getConnection(), $clubProjectId))->audit(
      userStatus: function(string $userId) use ($config): array {
        $user = $this->userManager->get($userId);
        $userConfig = $config->getCloudUserConfig();
        return [
          'user_exists' => $user !== null,
          'logged_in' => $user !== null ? $user->getLastLogin() > 0 : null,
          'public_key_present' => $userConfig->getValueString(
            $userId,
            $this->appName,
            AsymmetricKeyStorageInterface::PUBLIC_ENCRYPTION_KEY,
            '',
          ) !== '',
          'private_key_present' => $userConfig->getValueString(
            $userId,
            $this->appName,
            AsymmetricKeyStorageInterface::PRIVATE_ENCRYPTION_KEY,
            '',
          ) !== '',
        ];
      },
    );
    $report = [
      'summary' => ['encryption_issues' => $audit['summary']['encryption_issues']],
      'encryption_diagnostics' => $audit['encryption_diagnostics'],
    ];
    if ($input->getOption('json')) {
      $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    } else {
      $output->writeln('Participant file encryption audit (read-only).');
      $output->writeln('<info>Summary</info>');
      $output->writeln(json_encode($report['summary'], JSON_THROW_ON_ERROR));
      $encryptionIssues = array_filter(
        $report['encryption_diagnostics'],
        ParticipantFileAuditService::encryptionDiagnosticIsIssue(...),
      );
      $output->writeln('');
      $output->writeln('<info>Encryption issues</info>');
      if ($encryptionIssues === []) {
        $output->writeln('<comment>No encryption issues detected.</comment>');
      }
      foreach ($encryptionIssues as $diagnostic) {
        $output->writeln(OutputFormatter::escape(json_encode($diagnostic, JSON_THROW_ON_ERROR)));
      }
    }
    return $report['summary']['encryption_issues'] === 0 ? self::SUCCESS : self::FAILURE;
  }

  /**
   * @param InputInterface $input
   * @param OutputInterface $output
   *
   * @return int
   */
  private function repairAccess(InputInterface $input, OutputInterface $output): int
  {
    $config = $this->appContainer->get(ConfigService::class);
    $l10n = $config->getAppL10n();
    $service = new ParticipantFileRepairService(
      $this->entityManager->getConnection(),
      (int)$config->getConfigValue(ConfigConstants::CLUB_MEMBER_PROJECT_ID_KEY, 0),
      function(array $finding) use ($l10n): array {
        if (in_array($finding['source'], ['participant-field', 'participant-field-support'], true)) {
          $field = $this->getDatabaseRepository(Entities\ProjectParticipantField::class)->find($finding['source_id']);
          if ($field === null) {
            throw new RuntimeException('The participant field disappeared during repair.');
          }
          $path = $this->appContainer->get(ProjectService::class)->getParticipantFieldFolderPath($field);
          if ($path === null) {
            throw new RuntimeException('The participant field has no destination path.');
          }
          return $path === '' ? [] : explode('/', $path);
        }
        return match ($finding['source']) {
          'invoice' => [$l10n->t('Invoices')],
          'donation-receipt' => [$l10n->t('DonationReceipts')],
          'debit-mandate' => [$l10n->t('DebitMandates')],
          'composite-payment' => [$l10n->t('SupportingDocuments'), $l10n->t('BankTransactions')],
          default => throw new RuntimeException('Unsupported participant document source.'),
        };
      },
    );
    try {
      $report = $service->repair($input->getOption('dry-run'));
    } finally {
      // The metadata backfill bypasses ORM orphan-removal on shared source entries.
      $this->entityManager->clear();
    }
    if ($input->getOption('json')) {
      $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    } else {
      $output->writeln($report['dry_run'] ? 'Participant document repair preview (read-only).' : 'Participant document repair (seals deferred).');
      $output->writeln(json_encode($report['summary'], JSON_THROW_ON_ERROR));
      foreach ($report['repairs'] as $repair) {
        $output->writeln(OutputFormatter::escape(json_encode($repair, JSON_THROW_ON_ERROR)));
      }
      $output->writeln('Remaining access findings: ' . count($report['remaining']['findings']));
    }
    return isset($report['summary']['blocked']) ? self::FAILURE : self::SUCCESS;
  }

  /**
   * @param ConfigService $config
   *
   * @return array<int, string> Participant mount paths, without a Nextcloud user's files prefix.
   */
  private function auditMountPaths(ConfigService $config): array
  {
    $sharedFolder = $config->getConfigValue(ConfigConstants::SHARED_FOLDER);
    $projectsFolder = $config->getConfigValue(ConfigConstants::PROJECTS_FOLDER);
    $participantsFolder = $config->getConfigValue(ConfigConstants::PROJECT_PARTICIPANTS_FOLDER);
    if (empty($sharedFolder) || empty($projectsFolder) || empty($participantsFolder)) {
      return [];
    }
    $projectService = $this->appContainer->get(ProjectService::class);
    $documentsFolder = $config->getAppL10n()->t('Documents');
    $paths = [];
    foreach ($this->entityManager->getConnection()->fetchAllAssociative(
      'SELECT s.root_id, p.name, p.year, p.type, m.user_id_slug
       FROM ProjectParticipants pp
       JOIN DatabaseStorages s ON s.id = pp.database_documents_id
       LEFT JOIN Projects p ON p.id = pp.project_id
       LEFT JOIN Musicians m ON m.id = pp.musician_id',
    ) as $participant) {
      if ($participant['root_id'] === null || $participant['name'] === null || empty($participant['user_id_slug'])) {
        continue;
      }
      $participant['type'] = EnumProjectTemporalType::from($participant['type']);
      $paths[(int)$participant['root_id']] = '/' . implode('/', [
        trim($sharedFolder, '/'),
        trim($projectsFolder, '/'),
        $projectService->getProjectPathPrefix($participant),
        trim($participantsFolder, '/'),
        $participant['user_id_slug'],
        $documentsFolder,
      ]);
    }
    return $paths;
  }
}
