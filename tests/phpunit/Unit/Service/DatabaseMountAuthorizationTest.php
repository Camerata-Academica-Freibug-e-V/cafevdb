<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use UnexpectedValueException;

use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Database\Doctrine\Util as DoctrineUtil;
use OCA\CAFEVDB\Service\AuthorizationService;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\MountAuthenticationService;
use OCA\CAFEVDB\Service\ProjectService;
use OCA\CAFEVDB\Storage\Database\Factory;
use OCA\CAFEVDB\Storage\Database\MountProvider;
use OCA\CAFEVDB\Storage\Database;
use OCA\CAFEVDB\Storage\Database\UnavailableCache;
use OCA\CAFEVDB\Storage\Database\UnavailableMount;
use OCA\CAFEVDB\Settings\ConfigConstants;
use OCA\CAFEVDB\Wrapped\Doctrine\ORM\EntityManagerInterface;
use OCA\CAFEVDB\Wrapped\Doctrine\ORM\EntityRepository;
use OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Service\GroupFoldersService;
use OCA\GroupFolders\Folder\FolderDefinitionWithPermissions;
use OC\Files\Storage\FailedStorage;
use OC\Files\Storage\StorageFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Storage\IStorageFactory;
use OCP\Files\StorageNotAvailableException;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/** Database Mount Authorization Test. */
#[CoversClass(MountProvider::class)]
#[UsesClass(UnavailableCache::class)]
#[UsesClass(UnavailableMount::class)]
#[UsesClass(DoctrineUtil::class)]
#[AllowMockObjectsWithoutExpectations]
class DatabaseMountAuthorizationTest extends TestCase
{
  /** @return void */
  public function testAuthorizationPrecedesCredentialRecovery(): void
  {
    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('alice');
    $config = $this->createStub(ConfigService::class);
    $config->method('getL10n')->willReturn($this->createStub(IL10N::class));
    $config->method('logger')->willReturn($this->createStub(LoggerInterface::class));
    $authorization = $this->createMock(AuthorizationService::class);
    $authorization->expects($this->once())->method('authorized')
      ->with('alice', AuthorizationService::PERMISSION_FILESYSTEM)->willReturn(false);
    $authentication = $this->createMock(MountAuthenticationService::class);
    $authentication->expects($this->never())->method('authenticate');
    $provider = new MountProvider(
      $config,
      $authorization,
      $this->createStub(Factory::class),
      $this->createStub(IUserMountCache::class),
      $this->createStub(IDBConnection::class),
      $this->createStub(IUserSession::class),
      $authentication,
    );
    self::assertSame([], $provider->getMountsForUser($user, $this->createStub(IStorageFactory::class)));
  }

  /** @return void */
  public function testOrchestraAdminCanAuthenticateWithoutGeneralFilesystemPermission(): void
  {
    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('alice');
    $config = $this->createStub(ConfigService::class);
    $config->method('getL10n')->willReturn($this->createStub(IL10N::class));
    $config->method('logger')->willReturn($this->createStub(LoggerInterface::class));
    $config->method('inSubAdminGroup')->willReturn(true);
    $authorization = $this->createStub(AuthorizationService::class);
    $authorization->method('authorized')->willReturn(false);
    $authentication = $this->createMock(MountAuthenticationService::class);
    $authentication->expects($this->once())->method('authenticate')->with($user)->willReturn(false);
    $mountCache = $this->createMock(IUserMountCache::class);
    $mountCache->expects($this->once())->method('getMountsForUser')->with($user)->willReturn([]);
    $provider = new MountProvider(
      $config,
      $authorization,
      $this->createStub(Factory::class),
      $mountCache,
      $this->createStub(IDBConnection::class),
      $this->createStub(IUserSession::class),
      $authentication,
    );
    self::assertSame([], $provider->getMountsForUser($user, $this->createStub(IStorageFactory::class)));
  }

  /** @return void */
  public function testMountAuthenticationPrecedesUnavailableMountLookup(): void
  {
    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('alice');
    $config = $this->createStub(ConfigService::class);
    $config->method('getL10n')->willReturn($this->createStub(IL10N::class));
    $config->method('logger')->willReturn($this->createStub(LoggerInterface::class));
    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->never())->method('get');
    $config->method('getAppContainer')->willReturn($container);
    $authorization = $this->createMock(AuthorizationService::class);
    $authorization->expects($this->exactly(2))->method('authorized')
      ->willReturnMap([
        ['alice', AuthorizationService::PERMISSION_FILESYSTEM, true],
        ['alice', AuthorizationService::PERMISSION_FINANCE, false],
      ]);
    $authentication = $this->createMock(MountAuthenticationService::class);
    $authentication->expects($this->once())->method('authenticate')
      ->with($user)->willReturn(false);
    $mountCache = $this->createMock(IUserMountCache::class);
    $mountCache->expects($this->once())->method('getMountsForUser')->with($user)->willReturn([]);
    $provider = new MountProvider(
      $config,
      $authorization,
      $this->createStub(Factory::class),
      $mountCache,
      $this->createStub(IDBConnection::class),
      $this->createStub(IUserSession::class),
      $authentication,
    );

    self::assertSame([], $provider->getMountsForUser($user, $this->createStub(IStorageFactory::class)));
  }

  /** @return void */
  public function testAuthenticatedMountLookupResolvesEntityManagerFromAppContainer(): void
  {
    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('alice');
    $session = $this->createStub(IUserSession::class);
    $session->method('getUser')->willReturn($user);
    $config = $this->createStub(ConfigService::class);
    $config->method('getL10n')->willReturn($this->createStub(IL10N::class));
    $config->method('logger')->willReturn($this->createStub(LoggerInterface::class));
    $authorization = $this->createStub(AuthorizationService::class);
    $authorization->method('authorized')->willReturnMap([
      ['alice', AuthorizationService::PERMISSION_FILESYSTEM, true],
      ['alice', AuthorizationService::PERMISSION_FINANCE, false],
    ]);
    $authentication = $this->createStub(MountAuthenticationService::class);
    $authentication->method('authenticate')->willReturn(true);

    $entityManager = $this->createMock(EntityManager::class);
    $entityManager->method('getUserId')->willReturn('alice');
    $entityManager->expects($this->once())->method('connected')->willReturn(false);
    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->once())->method('get')->with(EntityManager::class)->willReturn($entityManager);
    $config->method('getAppContainer')->willReturn($container);

    $mountCache = $this->createMock(IUserMountCache::class);
    $mountCache->expects($this->once())->method('getMountsForUser')->with($user)->willReturn([]);
    $provider = new MountProvider(
      $config,
      $authorization,
      $this->createStub(Factory::class),
      $mountCache,
      $this->createStub(IDBConnection::class),
      $session,
      $authentication,
    );

    self::assertSame([], $provider->getMountsForUser($user, $this->createStub(IStorageFactory::class)));
  }

  /** @return array */
  public static function synchronizationFailures(): array
  {
    return [
      'cached mount can be isolated' => [true, false, false, true, 5],
      'no cached mandate mount' => [false, false, false, true, 0],
      'outer transaction is active' => [true, true, true, true, 1],
      'transaction remains active' => [true, false, true, true, 1],
      'entity manager is closed' => [true, false, false, false, 1],
    ];
  }

  /**
   * @param bool $hasCachedMount
   * @param bool $hadTransaction
   * @param bool $transactionActive
   * @param bool $managerOpen
   * @param int $expectedMounts
   *
   * @return void
   */
  #[DataProvider('synchronizationFailures')]
  public function testSynchronizationFailureRestoresUnavailableCachedMount(
    bool $hasCachedMount,
    bool $hadTransaction,
    bool $transactionActive,
    bool $managerOpen,
    int $expectedMounts,
  ): void {
    require_once \OC::$SERVERROOT . '/apps/groupfolders/lib/Folder/FolderDefinition.php';
    require_once \OC::$SERVERROOT . '/apps/groupfolders/lib/Folder/FolderDefinitionWithPermissions.php';

    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('alice');
    $session = $this->createStub(IUserSession::class);
    $session->method('getUser')->willReturn($user);
    $l10n = $this->createStub(IL10N::class);
    $l10n->method('t')->willReturnCallback(fn(string $text) => $text);
    $config = $this->createStub(ConfigService::class);
    $config->method('getL10n')->willReturn($l10n);
    $config->method('getAppL10n')->willReturn($l10n);
    $exceptions = [];
    $logger = $this->createStub(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(static function ($level, $message, array $context) use (&$exceptions): void {
      if (isset($context['exception'])) {
        $exceptions[] = $context['exception'];
      }
    });
    $config->method('logger')->willReturn($logger);
    $config->method('getConfigValue')->willReturnCallback(
      fn(string $key) => match ($key) {
        ConfigConstants::SHARED_FOLDER => 'Shared',
        ConfigConstants::PROJECTS_FOLDER => 'Projects',
        ConfigConstants::FINANCE_FOLDER => 'Finance',
        default => null,
      },
    );

    $entityManager = $this->createStub(EntityManager::class);
    $entityManager->method('getUserId')->willReturn('alice');
    $entityManager->method('connected')->willReturn(true);
    $entityManager->method('isTransactionActive')->willReturnOnConsecutiveCalls($hadTransaction, $transactionActive);
    $wrappedEntityManager = $this->createStub(EntityManagerInterface::class);
    $wrappedEntityManager->method('isOpen')->willReturn($managerOpen);
    $entityManager->method('getWrappedObject')->willReturn($wrappedEntityManager);
    $projectRepository = $this->createStub(EntityRepository::class);
    $projectRepository->method('findBy')->willReturn([]);
    $entityManager->method('getRepository')->willReturn($projectRepository);
    $groupFolders = $this->createStub(GroupFoldersService::class);
    $groupFolders->method('getFolderForUser')->willReturn(new FolderDefinitionWithPermissions(
      1, 'Shared', 0, false, false, 1, 1, [],
      $this->createStub(ICacheEntry::class), \OCP\Constants::PERMISSION_READ,
    ));
    $container = $this->createStub(ContainerInterface::class);
    $container->method('get')->willReturnMap([
      [EntityManager::class, $entityManager],
      [GroupFoldersService::class, $groupFolders],
      [ProjectService::class, $this->createStub(ProjectService::class)],
    ]);
    $config->method('getAppContainer')->willReturn($container);
    $authorization = $this->createStub(AuthorizationService::class);
    $authorization->method('authorized')->willReturnMap([
      ['alice', AuthorizationService::PERMISSION_FILESYSTEM, true],
      ['alice', AuthorizationService::PERMISSION_FINANCE, true],
    ]);
    $authentication = $this->createMock(MountAuthenticationService::class);
    $authentication->expects($this->once())->method('authenticate')->with($user)->willReturn(true);

    $mandates = $this->createMock(Database\DebitMandatesStorage::class);
    $mandates->expects($this->once())->method('synchronize')
      ->willThrowException(new UnexpectedValueException('Invalid mandate path.'));
    $mandates->method('getId')->willReturn('cafevdb::database-storage/finance/debit-mandates/');
    $factory = $this->createStub(Factory::class);
    $factory->method('getBankTransactionsStorage')->willReturn($this->createStub(Database\BankTransactionsStorage::class));
    $factory->method('getTaxExemptionNoticesStorage')->willReturn($this->createStub(Database\TaxExemptionNoticesStorage::class));
    $factory->method('getDonationReceiptsStorage')->willReturn($this->createStub(Database\DonationReceiptsStorage::class));
    $factory->method('getInvoicesStorage')->willReturn($this->createStub(Database\InvoicesStorage::class));
    $factory->method('getDebitMandatesStorage')->willReturn($mandates);
    $loader = $this->getMockBuilder(StorageFactory::class)->disableOriginalConstructor()->onlyMethods(['wrap'])->getMock();
    $loader->method('wrap')->willReturnCallback(fn($mount, $storage) => $storage);

    $cached = $this->createStub(ICachedMountInfo::class);
    $cached->method('getUser')->willReturn($user);
    $cached->method('getStorageId')->willReturn(42);
    $cached->method('getRootId')->willReturn(108);
    $cached->method('getMountPoint')->willReturn('/alice/files/Shared/Finance/debit-mandates/');
    $cached->method('getRootInternalPath')->willReturn('');
    $cached->method('getMountProvider')->willReturn(MountProvider::class);
    $mountCache = $this->createMock(IUserMountCache::class);
    $mountCache->method('getMountsForUser')->willReturn($hasCachedMount ? [$cached] : []);

    $expression = $this->createStub(IExpressionBuilder::class);
    $expression->method('eq')->willReturn('condition');
    $result = $this->createStub(IResult::class);
    $result->method('fetchOne')->willReturn('cafevdb::database-storage/finance/debit-mandates/');
    $query = $this->createStub(IQueryBuilder::class);
    foreach (['select', 'from', 'innerJoin', 'where', 'andWhere'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('expr')->willReturn($expression);
    $query->method('executeQuery')->willReturn($result);
    $connection = $this->createStub(IDBConnection::class);
    $connection->method('getQueryBuilder')->willReturn($query);

    $provider = new MountProvider($config, $authorization, $factory, $mountCache, $connection, $session, $authentication);
    $mounts = $provider->getMountsForUser($user, $loader);
    self::assertCount($expectedMounts, $mounts, implode("\n", array_map(
      static fn(\Throwable $exception) => $exception->getMessage(),
      $exceptions,
    )));
    if (!$hasCachedMount) {
      return;
    }
    $mandateMount = $mounts[$expectedMounts - 1];
    if ($expectedMounts === 5) {
      self::assertNotInstanceOf(FailedStorage::class, $mounts[0]->getStorage());
      try {
        $mandateMount->getStorage()->getCache()->get('');
        self::fail('Unavailable mandate cache must fail closed.');
      } catch (StorageNotAvailableException $exception) {
        self::assertStringContainsString('synchronize debit-mandate', $exception->getMessage());
      }
    }
    self::assertInstanceOf(UnavailableMount::class, $mandateMount);
    self::assertSame('/alice/files/Shared/Finance/debit-mandates/', $mandateMount->getMountPoint());
    self::assertInstanceOf(FailedStorage::class, $mandateMount->getStorage());
    $this->expectException(StorageNotAvailableException::class);
    $mandateMount->getStorage()->file_get_contents('mandate');
  }
}
