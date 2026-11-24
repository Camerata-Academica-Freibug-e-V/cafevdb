<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Command;

use OCA\CAFEVDB\Command\DatabaseStorage;
use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\ProjectService;
use OCA\CAFEVDB\Settings\ConfigConstants;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;
use OCP\IL10N;
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
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ParticipantFileAuditService::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Command\AuthenticatedCommandTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\EntityManagerTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\AuthenticatedCommandTrait::class)]
class DatabaseStorageTest extends TestCase
{
  private Connection&MockObject $connection;
  private EntityManager&MockObject $entityManager;
  private CommandTester $tester;
  private bool $loginSuccessful = true;

  /** {@inheritdoc} */
  protected function setUp(): void
  {
    $this->connection = $this->createMock(Connection::class);
    $this->entityManager = $this->createMock(EntityManager::class);
    $this->entityManager->method('getConnection')->willReturn($this->connection);
    $this->connection->method('transactional')->willReturnCallback(fn(callable $callback) => $callback($this->connection));
    $this->connection->expects(self::never())->method('executeStatement');
    $this->entityManager->expects(self::never())->method('flush');
    $this->entityManager->expects(self::never())->method('remove');
    $container = $this->createStub(ContainerInterface::class);
    $config = $this->createStub(ConfigService::class);
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
    $users->method('get')->willReturn($this->createStub(IUser::class));
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
    yield 'JSON without audit' => [['--json' => true]];
    yield 'file names without audit' => [['--include-file-names' => true]];
    yield 'field names without audit' => [['--include-field-names' => true]];
    yield 'removal during audit' => [['--audit-access' => true, '--remove-orphans' => true]];
    yield 'listing during audit' => [['--audit-access' => true, '--list-storages' => null]];
    yield 'orphan search during audit' => [['--audit-access' => true, '--find-orphans' => true]];
  }

  /** @param array $options */
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
    self::assertStringContainsString('read-only', $this->tester->getDisplay());
    self::assertStringContainsString('"findings":0', $this->tester->getDisplay());
  }

  /** @param bool $json */
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

  /** @param bool $json */
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

  /** @param bool $json */
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

  /** @return void */
  public function testFailedLoginDoesNotAudit(): void
  {
    $this->loginSuccessful = false;
    $this->connection->expects(self::never())->method('fetchAllAssociative');
    self::assertSame(DatabaseStorage::FAILURE, $this->tester->execute(['--audit-access' => true]));
    self::assertStringContainsString('Login failed.', $this->tester->getDisplay());
  }
}
