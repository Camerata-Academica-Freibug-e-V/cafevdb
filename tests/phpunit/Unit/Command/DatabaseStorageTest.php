<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Command;

use RuntimeException;

use OCA\CAFEVDB\Command\DatabaseStorage;
use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\OrphanTreeService;
use OCA\CAFEVDB\Service\ProjectService;
use OCA\CAFEVDB\Settings\ConfigConstants;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\DriverManager;
use OCP\IL10N;
use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Audit command output, authentication and non-destructive option selection. */
#[Attributes\CoversClass(DatabaseStorage::class)]
#[Attributes\UsesClass(OrphanTreeService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ParticipantFileAuditService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\ConsoleLogger::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\DeprecationLogger::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Command\AuthenticatedCommandTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\EntityManagerTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\AuthenticatedCommandTrait::class)]
class DatabaseStorageTest extends TestCase
{
  private Connection&MockObject $connection;
  private EntityManager&MockObject $entityManager;
  private CommandTester $tester;
  private bool $loginSuccessful = true;
  private int $lastLogin = 123;
  private bool $privateKeyPresent = true;

  /** {@inheritdoc} */
  protected function setUp(): void
  {
    $this->connection = $this->createMock(Connection::class);
    $this->entityManager = $this->createMock(EntityManager::class);
    $this->entityManager->method('getConnection')->willReturn($this->connection);
    $this->connection->method('transactional')->willReturnCallback(fn(callable $callback) => $callback($this->connection));
    $this->connection->method('fetchAssociative')->willReturnCallback(static fn(string $sql) => str_contains($sql, 'AS header')
      ? ['header' => '00000001|', 'data_length' => 20]
      : ['seal_delimiter' => '|', 'recipients' => 'alice:key']);
    $this->connection->expects(self::never())->method('executeStatement');
    $this->entityManager->expects(self::never())->method('flush');
    $this->entityManager->expects(self::never())->method('remove');
    $container = $this->createStub(ContainerInterface::class);
    $config = $this->createStub(ConfigService::class);
    $userConfig = $this->createStub(IUserConfig::class);
    $userConfig->method('getValueString')->willReturnCallback(
      fn(string $uid, string $app, string $key) => $key === 'privateEncryptionKey' && !$this->privateKeyPresent ? '' : 'key',
    );
    $config->method('getCloudUserConfig')->willReturn($userConfig);
    $config->method('getConfigValue')->willReturnCallback(static function(string $key, mixed $default = null): mixed {
      self::assertSame($key === ConfigConstants::CLUB_MEMBER_PROJECT_ID_KEY ? 0 : null, $default);
      return match ($key) {
        ConfigConstants::CLUB_MEMBER_PROJECT_ID_KEY => 20,
        ConfigConstants::SHARED_FOLDER => 'ORCHESTRA',
        ConfigConstants::PROJECTS_FOLDER => 'projects',
        ConfigConstants::PROJECT_PARTICIPANTS_FOLDER => 'participants',
      };
    });
    $projectService = $this->createStub(ProjectService::class);
    $projectService->method('getProjectPathPrefix')->willReturn('2026/Project');
    $container->method('get')->willReturnMap([
      [EntityManager::class, $this->entityManager],
      [ConfigService::class, $config],
      [ProjectService::class, $projectService],
    ]);
    $users = $this->createStub(IUserManager::class);
    $user = $this->createStub(IUser::class);
    $user->method('getLastLogin')->willReturnCallback(fn() => $this->lastLogin);
    $users->method('get')->willReturn($user);
    $session = $this->createStub(IUserSession::class);
    $session->method('login')->willReturnCallback(fn() => $this->loginSuccessful);
    $l10n = $this->createStub(IL10N::class);
    $l10n->method('t')->willReturnArgument(0);
    $config->method('getAppL10n')->willReturn($l10n);
    $command = new DatabaseStorage(
      'cafevdb',
      $l10n,
      new NullLogger,
      $users,
      $session,
      $container,
    );
    (new Application)->add($command);
    $this->tester = new CommandTester($command);
    $this->tester->setInputs(['operator', 'password']);
  }

  /** @return iterable */
  public static function invalidOptions(): iterable
  {
    yield 'dry run without repair' => [['--dry-run' => true]];
    yield 'repair with audit' => [['--repair-access' => true, '--audit-access' => true]];
    yield 'repair with removal' => [['--repair-access' => true, '--remove-orphans' => true]];
    yield 'repair with orphan search' => [['--repair-access' => true, '--find-orphans' => true]];
    yield 'repair with storage listing' => [['--repair-access' => true, '--list-storages' => null]];
    yield 'repair with orphan trees' => [['--repair-access' => true, '--find-orphan-trees' => null]];
    yield 'unknown orphan mode' => [['--find-orphan-trees' => 'prune-all']];
    yield 'empty orphan mode' => [['--find-orphan-trees' => '']];
    yield 'pruning with dry run' => [['--find-orphan-trees' => 'prune-empty', '--dry-run' => true]];
    yield 'repair with names' => [['--repair-access' => true, '--include-file-names' => true]];
    yield 'repair with field names' => [['--repair-access' => true, '--include-field-names' => true]];
    yield 'JSON without audit' => [['--json' => true]];
    yield 'file names without audit' => [['--include-file-names' => true]];
    yield 'field names without audit' => [['--include-field-names' => true]];
    yield 'removal during audit' => [['--audit-access' => true, '--remove-orphans' => true]];
    yield 'listing during audit' => [['--audit-access' => true, '--list-storages' => null]];
    yield 'orphan search during audit' => [['--audit-access' => true, '--find-orphans' => true]];
    yield 'orphan trees during audit' => [['--audit-access' => true, '--find-orphan-trees' => null]];
    yield 'removal during tree listing' => [['--find-orphan-trees' => null, '--remove-orphans' => true]];
    yield 'storage listing during tree listing' => [['--find-orphan-trees' => null, '--list-storages' => null]];
    yield 'file search during tree listing' => [['--find-orphan-trees' => null, '--find-orphans' => true]];
    yield 'field names during tree listing' => [['--find-orphan-trees' => null, '--include-field-names' => true]];
  }

  /**
   * @param array $options
   *
   * @return void
   */
  #[Attributes\DataProvider('invalidOptions')]
  public function testInvalidOptionsDoNotQueryDatabase(array $options): void
  {
    $this->connection->expects(self::never())->method('fetchAllAssociative');
    self::assertSame(DatabaseStorage::INVALID, $this->tester->execute($options));
  }

  /** @return void */
  public function testJsonOutputAndFailureStatusForUnrecognizedGrant(): void
  {
    $this->connection->method('fetchAllAssociative')->willReturnCallback(static fn(string $sql) => match (true) {
      str_contains($sql, 'FROM Files') => [['id' => 1, 'type' => 'encrypted']],
      str_contains($sql, 'FROM EncryptedFileOwners') => [['encrypted_file_id' => 1, 'musician_id' => 10]],
      default => [],
    });
    self::assertSame(DatabaseStorage::FAILURE, $this->tester->execute(
      ['--audit-access' => true, '--json' => true],
      ['capture_stderr_separately' => true],
    ));
    $report = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    self::assertSame(
      [['code' => 'unrecognized-owned-file', 'file_id' => 1, 'musician_id' => 10, 'repairable' => false]],
      $report['findings'],
    );
  }

  /** @return void */
  public function testCleanAuditSucceedsWithTextReport(): void
  {
    $this->connection->method('fetchAllAssociative')->willReturn([]);
    self::assertSame(DatabaseStorage::SUCCESS, $this->tester->execute(['--audit-access' => true]));
    $display = $this->tester->getDisplay();
    self::assertStringContainsString('read-only', $display);
    self::assertStringContainsString('Summary', $display);
    self::assertStringContainsString('Structural access findings', $display);
    self::assertStringContainsString('Encryption issues', $display);
    self::assertStringContainsString('No structural access findings.', $display);
    self::assertStringContainsString('No encryption issues detected.', $display);
    self::assertStringContainsString('"findings":0', $display);
  }

  /**
   * @param bool $json
   *
   * @return void
   */
  #[Attributes\DataProvider('fileNameFormats')]
  public function testRepairReasonsAppearInBothOutputFormats(bool $json): void
  {
    $this->connection->method('fetchAllAssociative')->willReturnCallback(static fn(string $sql) => match (true) {
      str_contains($sql, 'FROM Files') => [['id' => 1, 'type' => 'encrypted']],
      str_contains($sql, 'FROM EncryptedFileOwners') => [['encrypted_file_id' => 1, 'musician_id' => 10]],
      str_contains($sql, 'FROM Musicians') => [['id' => 10, 'deleted' => '2000-01-01']],
      default => [],
    });
    self::assertSame(DatabaseStorage::FAILURE, $this->tester->execute(
      ['--audit-access' => true, '--json' => $json],
      ['capture_stderr_separately' => true],
    ));
    $display = $this->tester->getDisplay();
    if ($json) {
      $report = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
      self::assertSame(['musician-deleted'], $report['findings'][0]['repair_reasons']);
    } else {
      self::assertStringContainsString('"repair_reasons":["musician-deleted"]', $display);
    }
  }

  /**
   * @param bool $json
   *
   * @return void
   */
  #[Attributes\DataProvider('fileNameFormats')]
  public function testRepairCandidatesAppearInBothOutputFormats(bool $json): void
  {
    $this->connection->method('fetchAllAssociative')->willReturnCallback(static fn(string $sql) => match (true) {
      str_contains($sql, 'FROM Files') => [['id' => 1, 'type' => 'encrypted']],
      str_contains($sql, 'FROM DatabaseStorageDirEntries') => [
        ['id' => 100, 'parent_id' => null, 'file_id' => null, 'type' => 'folder'],
        ['id' => 101, 'parent_id' => 100, 'file_id' => 1, 'type' => 'file'],
      ],
      str_contains($sql, 'FROM ProjectParticipants') => [['musician_id' => 10, 'project_id' => 20, 'root_id' => 100]],
      str_contains($sql, 'FROM SepaDebitMandates mandate') => [],
      str_contains($sql, 'FROM SepaDebitMandates') => [
        ['source' => 'participant-field-support', 'source_id' => 1, 'musician_id' => 10, 'project_id' => 20,
         'entry_id' => 101, 'deleted' => null, 'option_key' => '01'],
      ],
      str_contains($sql, 'FROM ProjectParticipantFieldsData d') => [
        ['field_id' => 1, 'musician_id' => 10, 'project_id' => 20, 'entry_id' => 101, 'option_key' => '01'],
      ],
      default => [],
    });
    self::assertSame(DatabaseStorage::FAILURE, $this->tester->execute(
      ['--audit-access' => true, '--json' => $json],
      ['capture_stderr_separately' => true],
    ));
    $display = $this->tester->getDisplay();
    if ($json) {
      $report = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
      self::assertCount(2, $report['findings']);
      foreach ($report['findings'] as $finding) {
        self::assertTrue($finding['repairable']);
        self::assertSame(['add-ownership', 'reconcile-encryption-seal'], $finding['repairsteps']);
      }
    } else {
      self::assertSame(2, substr_count($display, '"repairable":true'));
      self::assertSame(2, substr_count($display, '"repairsteps":["add-ownership","reconcile-encryption-seal"]'));
    }
  }

  /** @return iterable */
  public static function fileNameFormats(): iterable
  {
    yield 'text' => [false];
    yield 'JSON' => [true];
  }

  /**
   * @param bool $json
   *
   * @return void
   */
  #[Attributes\DataProvider('fileNameFormats')]
  public function testOrphanTreesIncludeOnlyStorageLessEntriesWithoutChangingMetadata(bool $json): void
  {
    $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    try {
      $database->executeStatement('CREATE TABLE DatabaseStorages (id INTEGER PRIMARY KEY, root_id INTEGER)');
      $database->executeStatement(
        'CREATE TABLE DatabaseStorageDirEntries (id INTEGER PRIMARY KEY, parent_id INTEGER, file_id INTEGER, type TEXT, name TEXT)',
      );
      $database->executeStatement(
        "INSERT INTO DatabaseStorageDirEntries VALUES
         (1, NULL, NULL, 'folder', 'mounted'), (2, 1, 50, 'file', 'mounted.pdf'),
         (10, NULL, NULL, 'folder', 'private root'), (11, 10, NULL, 'folder', 'private folder'),
         (12, 11, 50, 'file', 'private.pdf'), (13, 10, NULL, 'folder', 'nested mount'),
         (14, 13, 51, 'file', 'nested.pdf'), (20, NULL, NULL, 'folder', '<error>empty</error>'),
         (30, 999, 52, 'file', 'broken.pdf'),
         (40, 41, NULL, 'folder', 'cycle'), (41, 40, NULL, 'folder', 'cycle')",
      );
      $database->executeStatement('INSERT INTO DatabaseStorages VALUES (1, 1), (2, 13)');
      $before = $database->fetchAllAssociative('SELECT * FROM DatabaseStorageDirEntries ORDER BY id');
      $this->connection->method('fetchAllAssociative')->willReturnCallback($database->fetchAllAssociative(...));
      $options = ['--find-orphan-trees' => null, '--json' => $json];
      self::assertSame(DatabaseStorage::SUCCESS, $this->tester->execute($options, ['capture_stderr_separately' => true]));
      $display = $this->tester->getDisplay();
      self::assertStringNotContainsString('private', $display);
      if ($json) {
        $report = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['trees' => 2, 'entries' => 4], $report['summary']);
        self::assertSame([10, 20], array_column($report['trees'], 'root_id'));
        self::assertSame([10, 11, 12], array_column($report['trees'][0]['entries'], 'id'));
        self::assertSame(50, $report['trees'][0]['entries'][2]['file_id']);
      } else {
        self::assertStringContainsString('Root 10: 3 entries', $display);
        self::assertStringContainsString('Root 20: 1 entries', $display);
        self::assertStringNotContainsString('Root 1:', $display);
      }
      self::assertSame(DatabaseStorage::SUCCESS, $this->tester->execute(
        $options + ['--include-file-names' => true],
        ['capture_stderr_separately' => true],
      ));
      self::assertStringContainsString('private.pdf', $this->tester->getDisplay());
      self::assertStringContainsString('<error>empty', $this->tester->getDisplay());
      self::assertSame($before, $database->fetchAllAssociative('SELECT * FROM DatabaseStorageDirEntries ORDER BY id'));
      self::assertSame(2, (int)$database->fetchOne('SELECT COUNT(*) FROM DatabaseStorages'));
    } finally {
      $database->close();
    }
  }

  /** @return void */
  public function testEmptyOrphanTreeListingSucceeds(): void
  {
    $this->connection->method('fetchAllAssociative')->willReturn([]);
    self::assertSame(DatabaseStorage::SUCCESS, $this->tester->execute(
      ['--find-orphan-trees' => null, '--json' => true],
      ['capture_stderr_separately' => true],
    ));
    self::assertSame(
      ['summary' => ['trees' => 0, 'entries' => 0], 'trees' => []],
      json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
    );
  }

  /** @return void */
  public function testFailedLoginDoesNotListOrphanTrees(): void
  {
    $this->loginSuccessful = false;
    $this->connection->expects(self::never())->method('fetchAllAssociative');
    self::assertSame(DatabaseStorage::FAILURE, $this->tester->execute(['--find-orphan-trees' => null]));
  }

  /** @return void */
  public function testOrphanTreeQueryFailureIsNotReportedAsAnEmptyListing(): void
  {
    $this->connection->method('fetchAllAssociative')->willThrowException(new RuntimeException('Tree query failed'));
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Tree query failed');
    $this->tester->execute(['--find-orphan-trees' => null]);
  }

  /**
   * @param bool $json
   *
   * @return void
   */
  #[Attributes\DataProvider('fileNameFormats')]
  public function testFieldNamesAreOptionalInBothOutputFormats(bool $json): void
  {
    $this->connection->method('fetchAllAssociative')->willReturnCallback(static fn(string $sql) => match (true) {
      str_contains($sql, 'FROM Files') => [['id' => 1, 'type' => 'encrypted']],
      str_contains($sql, 'FROM DatabaseStorageDirEntries') => [
        ['id' => 100, 'parent_id' => null, 'file_id' => null, 'type' => 'folder'],
        ['id' => 101, 'parent_id' => 100, 'file_id' => 1, 'type' => 'file'],
      ],
      str_contains($sql, 'FROM ProjectParticipants') => [['musician_id' => 10, 'project_id' => 20, 'root_id' => 100]],
      str_contains($sql, 'FROM SepaDebitMandates mandate') => [],
      str_contains($sql, 'FROM SepaDebitMandates') => [
        ['source' => 'participant-field-support', 'source_id' => 1, 'musician_id' => 10, 'project_id' => 20,
         'entry_id' => 101, 'deleted' => null, 'option_key' => '01'],
      ],
      str_contains($sql, 'FROM ProjectParticipantFieldsData d') => [],
      str_contains($sql, 'FROM ProjectParticipantFields') => [
        ['id' => 1, 'data_type' => 'receivables', 'multiplicity' => 'recurring',
         'participant_access' => 'read', 'name' => 'private field'],
      ],
      default => [],
    });
    $options = ['--audit-access' => true, '--json' => $json];
    $this->tester->execute($options, ['capture_stderr_separately' => true]);
    $display = $this->tester->getDisplay();
    self::assertStringContainsString('participant_fields', $display);
    self::assertStringNotContainsString('private field', $display);
    $this->tester->execute($options + ['--include-field-names' => true], ['capture_stderr_separately' => true]);
    $display = $this->tester->getDisplay();
    if ($json) {
      $report = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
      self::assertSame('private field', $report['findings'][0]['participant_fields'][0]['field_name']);
    } else {
      self::assertStringContainsString('"field_name":"private field"', $display);
    }
  }

  /**
   * @param bool $json
   *
   * @return void
   */
  #[Attributes\DataProvider('fileNameFormats')]
  public function testFileNamesAreIncludedOnlyWhenRequested(bool $json): void
  {
    $this->connection->method('fetchAllAssociative')->willReturnCallback(static fn(string $sql) => match (true) {
      str_contains($sql, 'FROM Files') => [['id' => 1, 'type' => 'encrypted', 'file_name' => '<info>private.pdf</info>']],
      str_contains($sql, 'FROM EncryptedFileOwners') => [['encrypted_file_id' => 1, 'musician_id' => 10]],
      str_contains($sql, 'FROM ProjectParticipants pp') => [
        ['root_id' => 100, 'name' => 'Project', 'year' => 2026, 'type' => 'temporary', 'user_id_slug' => 'member'],
      ],
      str_contains($sql, 'FROM DatabaseStorageDirEntries') => [
        ['id' => 100, 'parent_id' => null, 'file_id' => null, 'type' => 'folder', 'name' => ''],
        ['id' => 101, 'parent_id' => 100, 'file_id' => 1, 'type' => 'file', 'name' => 'linked.pdf'],
      ],
      str_contains($sql, 'FROM ProjectParticipants') => [['musician_id' => 10, 'project_id' => 20, 'root_id' => 100]],
      default => [],
    });
    $options = ['--audit-access' => true, '--json' => $json];
    $this->tester->execute($options, ['capture_stderr_separately' => true]);
    self::assertStringNotContainsString('private.pdf', $this->tester->getDisplay());
    self::assertStringNotContainsString('ORCHESTRA', $this->tester->getDisplay());
    $this->tester->execute(
      $options + ['--include-file-names' => true],
      ['capture_stderr_separately' => true],
    );
    $display = $this->tester->getDisplay();
    $path = '/ORCHESTRA/projects/2026/Project/participants/member/Documents/linked.pdf';
    if ($json) {
      $report = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
      self::assertSame('<info>private.pdf</info>', $report['findings'][0]['file_name']);
      self::assertSame([['entry_id' => 101, 'path' => $path]], $report['findings'][0]['file_paths']);
      self::assertSame($path, $report['findings'][1]['entry_path']);
    } else {
      self::assertStringContainsString('"file_name":' . json_encode('<info>private.pdf</info>', JSON_THROW_ON_ERROR), $display);
      self::assertStringContainsString('"entry_path":' . json_encode($path, JSON_THROW_ON_ERROR), $display);
    }
  }

  /** @return void */
  public function testAuditUsesConfiguredClubProject(): void
  {
    $this->connection->method('fetchAllAssociative')->willReturnCallback(static fn(string $sql) => match (true) {
      str_contains($sql, 'FROM Files') => [['id' => 1, 'type' => 'encrypted']],
      str_contains($sql, 'FROM EncryptedFileOwners') => [['encrypted_file_id' => 1, 'musician_id' => 10]],
      str_contains($sql, 'SELECT id, user_id_slug FROM Musicians') => [['id' => 10, 'user_id_slug' => 'alice']],
      str_contains($sql, 'FROM DatabaseStorageDirEntries') => [
        ['id' => 100, 'parent_id' => null, 'file_id' => null, 'type' => 'folder'],
        ['id' => 101, 'parent_id' => 100, 'file_id' => 1, 'type' => 'file'],
        ['id' => 200, 'parent_id' => null, 'file_id' => null, 'type' => 'folder'],
        ['id' => 201, 'parent_id' => 200, 'file_id' => 1, 'type' => 'file'],
      ],
      str_contains($sql, 'FROM ProjectParticipants') => [
        ['musician_id' => 10, 'project_id' => 20, 'root_id' => 100],
        ['musician_id' => 10, 'project_id' => 21, 'root_id' => 200],
      ],
      str_contains($sql, 'FROM SepaDebitMandates mandate') => [],
      str_contains($sql, 'FROM SepaDebitMandates') => [
        ['source' => 'debit-mandate', 'source_id' => 1, 'musician_id' => 10, 'project_id' => 20,
         'entry_id' => 101, 'deleted' => null, 'option_key' => null],
      ],
      default => [],
    });
    self::assertSame(DatabaseStorage::SUCCESS, $this->tester->execute(['--audit-access' => true]));
    self::assertStringContainsString('"findings":0', $this->tester->getDisplay());
  }

  /**
   * @param bool $json
   *
   * @return void
   */
  #[Attributes\DataProvider('fileNameFormats')]
  public function testEncryptionDiagnosticsAreReportedAndCauseFailure(bool $json): void
  {
    $this->lastLogin = 0;
    $this->privateKeyPresent = false;
    $this->connection->method('fetchAllAssociative')->willReturnCallback(static fn(string $sql) => match (true) {
      str_contains($sql, 'FROM Files') => [['id' => 1, 'type' => 'encrypted']],
      str_contains($sql, 'FROM EncryptedFileOwners') => [['encrypted_file_id' => 1, 'musician_id' => 10]],
      str_contains($sql, 'SELECT id, user_id_slug FROM Musicians') => [['id' => 10, 'user_id_slug' => 'bob']],
      str_contains($sql, 'FROM DatabaseStorageDirEntries') => [
        ['id' => 100, 'parent_id' => null, 'file_id' => null, 'type' => 'folder'],
        ['id' => 101, 'parent_id' => 100, 'file_id' => 1, 'type' => 'file'],
      ],
      str_contains($sql, 'FROM ProjectParticipants') => [['musician_id' => 10, 'project_id' => 20, 'root_id' => 100]],
      str_contains($sql, 'FROM SepaDebitMandates mandate') => [],
      str_contains($sql, 'FROM SepaDebitMandates') => [
        ['source' => 'invoice', 'source_id' => 1, 'musician_id' => 10, 'project_id' => 20,
         'entry_id' => 101, 'deleted' => null, 'option_key' => null],
      ],
      default => [],
    });
    self::assertSame(DatabaseStorage::FAILURE, $this->tester->execute(
      ['--audit-access' => true, '--json' => $json],
      ['capture_stderr_separately' => true],
    ));
    $display = $this->tester->getDisplay();
    if ($json) {
      $report = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
      self::assertSame([], $report['findings']);
      self::assertSame(1, $report['summary']['encryption_issues']);
      $diagnostic = $report['encryption_diagnostics'][0];
      self::assertFalse($diagnostic['recipient_present']);
      self::assertFalse($diagnostic['logged_in']);
      self::assertFalse($diagnostic['private_key_present']);
      self::assertTrue($diagnostic['public_key_present']);
    } else {
      self::assertStringContainsString('"recipient_present":false', $display);
      self::assertStringContainsString('"logged_in":false', $display);
      self::assertStringContainsString('"private_key_present":false', $display);
    }
    self::assertStringNotContainsString('alice:key', $display);
  }

  /** @return void */
  public function testHealthyEncryptionDiagnosticsAreNotShownAsIssues(): void
  {
    $this->connection->method('fetchAllAssociative')->willReturnCallback(static fn(string $sql) => match (true) {
      str_contains($sql, 'FROM Files') => [['id' => 1, 'type' => 'encrypted']],
      str_contains($sql, 'FROM EncryptedFileOwners') => [['encrypted_file_id' => 1, 'musician_id' => 10]],
      str_contains($sql, 'SELECT id, user_id_slug FROM Musicians') => [['id' => 10, 'user_id_slug' => 'alice']],
      str_contains($sql, 'FROM DatabaseStorageDirEntries') => [
        ['id' => 100, 'parent_id' => null, 'file_id' => null, 'type' => 'folder'],
        ['id' => 101, 'parent_id' => 100, 'file_id' => 1, 'type' => 'file'],
      ],
      str_contains($sql, 'FROM ProjectParticipants') => [['musician_id' => 10, 'project_id' => 20, 'root_id' => 100]],
      str_contains($sql, 'FROM SepaDebitMandates mandate') => [],
      str_contains($sql, 'FROM SepaDebitMandates') => [
        ['source' => 'invoice', 'source_id' => 1, 'musician_id' => 10, 'project_id' => 20,
         'entry_id' => 101, 'deleted' => null, 'option_key' => null],
      ],
      default => [],
    });
    self::assertSame(DatabaseStorage::SUCCESS, $this->tester->execute(
      ['--audit-access' => true],
      ['capture_stderr_separately' => true],
    ));
    $display = $this->tester->getDisplay();
    self::assertStringContainsString('Encryption issues', $display);
    self::assertStringContainsString('No encryption issues detected.', $display);
    self::assertStringNotContainsString('"file_id":1', $display);
    self::assertStringNotContainsString('"user_id":"alice"', $display);
  }

  /** @return void */
  public function testFailedLoginDoesNotAudit(): void
  {
    $this->loginSuccessful = false;
    $this->connection->expects(self::never())->method('fetchAllAssociative');
    self::assertSame(DatabaseStorage::FAILURE, $this->tester->execute(['--audit-access' => true]));
    self::assertStringContainsString('Login failed.', $this->tester->getDisplay());
  }
}
