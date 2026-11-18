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
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Storage\IStorageFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(MountProvider::class)]
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
}
