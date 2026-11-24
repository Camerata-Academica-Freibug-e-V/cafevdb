<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022, 2023 Claus-Justus Heine
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
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\ParticipantFileAuditService;
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
        'Read-only audit of musician file ownership, business references and participant-storage links.',
      )
      ->addOption('json', null, InputOption::VALUE_NONE, 'Output the access audit as JSON.')
      ->addOption(
        'include-file-names',
        null,
        InputOption::VALUE_NONE,
        'Include potentially identifying file names and full mounted paths in the access audit.',
      )
      ->addOption('include-field-names', null, InputOption::VALUE_NONE, 'Include potentially identifying participant field names in the access audit.')
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
    if ((($input->getOption('json') || $input->getOption('include-file-names') || $input->getOption('include-field-names')) && !$audit)
        || ($audit && ($input->getOption('list-storages') !== false
                      || $input->getOption('find-orphans') || $input->getOption('remove-orphans')))) {
      $output->writeln('<error>Use --audit-access alone, optionally with --json, --include-file-names and --include-field-names.</error>');
      return self::INVALID;
    }
    $authenticationOutput = $audit && $input->getOption('json') && $output instanceof ConsoleOutputInterface
      ? $output->getErrorOutput()
      : $output;
    $result = $this->authenticate($input, $authenticationOutput);
    if ($result != 0) {
      return $result;
    }
    if ($audit) {
      return $this->auditAccess($input, $output);
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
      $output->writeln(json_encode($report['summary'], JSON_THROW_ON_ERROR));
      foreach ($report['findings'] as $finding) {
        $output->writeln(OutputFormatter::escape(json_encode($finding, JSON_THROW_ON_ERROR)));
      }
    }
    return $report['findings'] === [] ? self::SUCCESS : self::FAILURE;
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
