<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use OCA\CAFEVDB\Service\AuthorizationService;
use OCA\CAFEVDB\Service\EncryptionService;
use OCA\CAFEVDB\Service\WopiTokenService;
use OCA\CAFEVDB\Service\WopiCredentialStore;
use OCA\Richdocuments\Db\WopiMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/** Wopi Token Service Test. */
#[CoversClass(WopiTokenService::class)]
#[AllowMockObjectsWithoutExpectations]
class WopiTokenServiceTest extends TestCase
{
  private EncryptionService $encryption;
  private WopiCredentialStore $store;
  private AuthorizationService $authorization;
  private File $file;
  private IMountPoint $mount;
  private IssuedWopiToken $wopi;
  private WopiTokenService $service;
  private ContainerInterface $container;

  /** {@inheritdoc} */
  protected function setUp(): void
  {
    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('alice');
    $session = $this->createStub(IUserSession::class);
    $session->method('getUser')->willReturn($user);
    $session->method('isLoggedIn')->willReturn(true);
    $this->authorization = $this->createMock(AuthorizationService::class);
    $this->encryption = $this->createMock(EncryptionService::class);
    $this->store = $this->createMock(WopiCredentialStore::class);
    $this->mount = $this->createStub(IMountPoint::class);
    $this->file = $this->createStub(File::class);
    $this->file->method('getMountPoint')->willReturn($this->mount);
    $folder = $this->createStub(Folder::class);
    $folder->method('getFirstNodeById')->willReturn($this->file);
    $root = $this->createStub(IRootFolder::class);
    $root->method('getUserFolder')->willReturn($folder);
    $this->wopi = new IssuedWopiToken();
    $mapper = $this->createStub(IssuedWopiMapper::class);
    $mapper->method('getWopiForToken')->willReturn($this->wopi);
    $this->container = $this->createMock(ContainerInterface::class);
    $this->container->method('get')->willReturnCallback(fn(string $id) => match ($id) {
      WopiMapper::class => $mapper,
    });
    $this->service = new WopiTokenService(
      $session, $this->authorization, $this->encryption, $root, $this->container, $this->store,
    );
  }

  /** @return void */
  public function testAuthorizationPrecedesWopiLookup(): void
  {
    $this->authorization->expects($this->once())->method('authorized')
      ->with('alice', AuthorizationService::PERMISSION_FILESYSTEM)->willReturn(false);
    $this->container->expects($this->never())->method('get');
    $this->store->expects($this->never())->method('store');
    $this->service->provideCredentials('issued-token');
  }

  /** @return void */
  public function testOrdinaryMountDoesNotStoreCredentials(): void
  {
    $this->authorization->method('authorized')->willReturn(true);
    $this->mount->method('getOption')->willReturn(false);
    $this->store->expects($this->never())->method('store');
    $this->service->provideCredentials('issued-token');
  }

  /** @return void */
  public function testGuestAndMismatchedIdentitiesAreIgnored(): void
  {
    $this->authorization->method('authorized')->willReturn(true);
    $this->store->expects($this->never())->method('store');
    $this->wopi->type = 1;
    $this->service->provideCredentials('issued-token');
    $this->wopi->type = 0;
    $this->wopi->editor = 'bob';
    $this->service->provideCredentials('issued-token');
    $this->wopi->editor = 'alice';
    $this->wopi->fileUser = 'bob';
    $this->service->provideCredentials('issued-token');
  }

  /** @return void */
  public function testStoresAppKeyWithAuthoritativeExpiry(): void
  {
    $this->eligible();
    $this->store->expects($this->once())->method('store')->with('issued-token', 'alice', 2000000000, 'app-key');
    $this->service->provideCredentials('issued-token');
  }

  /** @return void */
  public function testMissingAppKeyFailsExplicitly(): void
  {
    $this->eligible(key: '');
    $this->store->expects($this->never())->method('store');
    $this->expectException(RuntimeException::class);
    $this->service->provideCredentials('issued-token');
  }

  /** @return void */
  public function testMismatchedBoundIdentityFailsExplicitly(): void
  {
    $this->eligible(userId: 'bob');
    $this->store->expects($this->never())->method('store');
    $this->expectException(RuntimeException::class);
    $this->service->provideCredentials('issued-token');
  }

  /** @return void */
  public function testStorageFailurePropagatesToResponseMiddleware(): void
  {
    $this->eligible();
    $this->store->method('store')->willThrowException(new RuntimeException('Storage unavailable.'));
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Storage unavailable.');
    $this->service->provideCredentials('issued-token');
  }

  /**
   * @param string $key
   * @param string $userId
   *
   * @return void
   */
  private function eligible(string $key = 'app-key', string $userId = 'alice'): void
  {
    $this->authorization->method('authorized')->willReturn(true);
    $this->mount->method('getOption')->willReturn(true);
    $this->encryption->method('getUserId')->willReturn($userId);
    $this->encryption->method('getAppEncryptionKey')->willReturn($key);
  }
}
