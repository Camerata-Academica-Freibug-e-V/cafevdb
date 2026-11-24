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
    $config->method('getConfigValue')->with(ConfigConstants::CLUB_MEMBER_PROJECT_ID_KEY, 0)->willReturn(20);
    $container->method('get')->willReturnMap([[EntityManager::class, $this->entityManager], [ConfigService::class, $config]]);
    $users = $this->createStub(IUserManager::class);
    $users->method('get')->willReturn($this->createStub(IUser::class));
    $session = $this->createStub(IUserSession::class);
    $session->method('login')->willReturnCallback(fn() => $this->loginSuccessful);
    $l10n = $this->createStub(IL10N::class);
    $l10n->method('t')->willReturnArgument(0);
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
      [['code' => 'unrecognized-owned-file', 'file_id' => 1, 'musician_id' => 10]],
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
