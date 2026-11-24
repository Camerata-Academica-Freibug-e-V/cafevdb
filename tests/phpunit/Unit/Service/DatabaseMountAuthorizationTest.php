<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Service\AuthorizationService;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\MountAuthenticationService;
use OCA\CAFEVDB\Storage\Database\Factory;
use OCA\CAFEVDB\Storage\Database\MountProvider;
use OCA\CAFEVDB\Storage\Database;
use OCA\CAFEVDB\Storage\Database\UnavailableCache;
use OCA\CAFEVDB\Storage\Database\UnavailableMount;
use OCA\CAFEVDB\Settings\ConfigConstants;
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
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(MountProvider::class)]
#[UsesClass(UnavailableCache::class)]
#[UsesClass(UnavailableMount::class)]
#[AllowMockObjectsWithoutExpectations]
class DatabaseMountAuthorizationTest extends TestCase
{
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

  public function testSynchronizationFailureRestoresUnavailableCachedMount(): void
  {
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
    $config->method('logger')->willReturn($this->createStub(LoggerInterface::class));
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
    $groupFolders = $this->createStub(GroupFoldersService::class);
    $groupFolders->method('getFolderForUser')->willReturn(new FolderDefinitionWithPermissions(
      1, 'Shared', 0, false, false, 1, 1, [],
      $this->createStub(ICacheEntry::class), \OCP\Constants::PERMISSION_READ,
    ));
    $container = $this->createStub(ContainerInterface::class);
    $container->method('get')->willReturnMap([
      [EntityManager::class, $entityManager],
      [GroupFoldersService::class, $groupFolders],
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
      ->willThrowException(new \UnexpectedValueException('Invalid mandate path.'));
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
    $mountCache->expects($this->once())->method('getMountsForUser')->with($user)->willReturn([$cached]);

    $expression = $this->createStub(IExpressionBuilder::class);
    $expression->method('eq')->willReturn('condition');
    $result = $this->createStub(IResult::class);
    $result->method('fetchOne')->willReturn('cafevdb::database-storage::/finance/debit-mandates/');
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
    self::assertCount(1, $mounts);
    self::assertInstanceOf(UnavailableMount::class, $mounts[0]);
    self::assertSame('/alice/files/Shared/Finance/debit-mandates/', $mounts[0]->getMountPoint());
    self::assertInstanceOf(FailedStorage::class, $mounts[0]->getStorage());
    $this->expectException(StorageNotAvailableException::class);
    $mounts[0]->getStorage()->file_get_contents('mandate');
  }
}
