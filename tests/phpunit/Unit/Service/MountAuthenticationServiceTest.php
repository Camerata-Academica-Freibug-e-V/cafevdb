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

use OCA\CAFEVDB\Service\EncryptionService;
use OCA\CAFEVDB\Service\MountAuthenticationService;
use OCA\CAFEVDB\Service\WopiCredentialStore;
use OCA\CAFEVDB\Service\WopiRequestService;
use OCA\Richdocuments\Db\WopiMapper;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/** Mount Authentication Service Test. */
#[CoversClass(MountAuthenticationService::class)]
#[AllowMockObjectsWithoutExpectations]
class MountAuthenticationServiceTest extends TestCase
{
  private IUserSession $session;
  private IRequest $request;
  private EncryptionService $encryption;
  private WopiCredentialStore $store;
  private ContainerInterface $container;
  private LoggerInterface $logger;
  private IUser $user;
  private MountTestWopiMapper $mapper;
  private WopiRequestService $wopiRequest;
  private MountAuthenticationService $service;

  /** {@inheritdoc} */
  protected function setUp(): void
  {
    $this->session = $this->createMock(IUserSession::class);
    $this->request = $this->createMock(IRequest::class);
    $this->encryption = $this->createMock(EncryptionService::class);
    $this->store = $this->createMock(WopiCredentialStore::class);
    $this->container = $this->createMock(ContainerInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->user = $this->createMock(IUser::class);
    $this->user->method('getUID')->willReturn('alice');
    $this->mapper = $this->createMock(MountTestWopiMapper::class);
    $this->wopiRequest = $this->createMock(WopiRequestService::class);
    $this->container->method('get')->willReturnCallback(fn(string $id) => match ($id) {
      WopiMapper::class => $this->mapper,
    });
    $this->service = new MountAuthenticationService(
      $this->session,
      $this->request,
      $this->encryption,
      $this->store,
      $this->container,
      $this->logger,
      $this->wopiRequest,
    );
  }

  /** @return void */
  public function testOrdinaryRequestsRetainSessionCheck(): void
  {
    $this->wopiRequest->method('isWopiRoute')->willReturn(false);
    $this->wopiRequest->expects($this->never())->method('isValidatedWopiRequest');
    $this->session->method('isLoggedIn')->willReturn(true, false);
    $this->session->method('getUser')->willReturn($this->user);
    $this->container->expects($this->never())->method('get');
    self::assertTrue($this->service->authenticate($this->user));
    self::assertFalse($this->service->authenticate($this->user));
  }

  /** @return void */
  public function testVolatileWopiUserGetsAppKeyWithoutPasswordLogin(): void
  {
    $this->wopiRequest();
    $this->session->method('getUser')->willReturn($this->user);
    $this->session->method('isLoggedIn')->willReturn(false);
    $bound = false;
    $this->encryption->method('getUserId')->willReturnCallback(function() use (&$bound) {
      return $bound ? 'alice' : null;
    });
    $this->encryption->expects($this->once())->method('bindWithAppKey')->with('alice', 'app-key')
      ->willReturnCallback(function() use (&$bound): void {
        $bound = true;
      });
    $this->store->expects($this->once())->method('recover')->with('wopi-token', 'alice', 2000000000)->willReturn('app-key');
    $this->session->expects($this->never())->method('login');
    $this->session->expects($this->once())->method('setUser')->with($this->user);
    $this->encryption->expects($this->never())->method('bind');
    self::assertTrue($this->service->authenticate($this->user));
  }

  /** @return void */
  public function testAlreadyBoundWopiUserDoesNotLogInAgain(): void
  {
    $this->wopiRequest();
    $this->session->method('getUser')->willReturn($this->user);
    $this->session->method('isLoggedIn')->willReturn(true);
    $this->encryption->method('getUserId')->willReturn('alice');
    $this->session->expects($this->never())->method('login');
    $this->store->expects($this->never())->method('recover');
    self::assertTrue($this->service->authenticate($this->user));
  }

  /** @return void */
  public function testUnvalidatedRequestCannotRecoverCredentials(): void
  {
    $this->wopiRequest->method('isWopiRoute')->willReturn(true);
    $this->wopiRequest->method('isValidatedWopiRequest')->willReturn(false);
    $this->mapper->expects($this->never())->method('getWopiForToken');
    $this->store->expects($this->never())->method('recover');
    self::assertFalse($this->service->authenticate($this->user));
  }

  /** @return void */
  public function testDifferentWopiIdentitiesAndGuestAreRejected(): void
  {
    $this->wopiRequest(
      new MountTestWopi(editor: 'bob'),
      new MountTestWopi(fileUser: 'bob'),
      new MountTestWopi(type: 1),
    );
    $this->store->expects($this->never())->method('recover');
    self::assertFalse($this->service->authenticate($this->user));
    self::assertFalse($this->service->authenticate($this->user));
    self::assertFalse($this->service->authenticate($this->user));
  }

  /** @return void */
  public function testUnregisteredTokenCannotBindEncryption(): void
  {
    $this->wopiRequest();
    $this->store->method('recover')->willReturn(null);
    $this->encryption->expects($this->never())->method('bindWithAppKey');
    $this->session->expects($this->never())->method('login');
    self::assertFalse($this->service->authenticate($this->user));
  }

  /** @return void */
  public function testDifferentActiveUserCannotBeReplaced(): void
  {
    $this->wopiRequest();
    $other = $this->createStub(IUser::class);
    $other->method('getUID')->willReturn('bob');
    $this->session->method('getUser')->willReturn($other);
    $this->store->expects($this->never())->method('recover');
    $this->session->expects($this->never())->method('login');
    self::assertFalse($this->service->authenticate($this->user));
  }

  /** @return void */
  public function testEncryptionBoundToAnotherUserCannotBeReplaced(): void
  {
    $this->wopiRequest();
    $this->encryption->method('getUserId')->willReturn('bob');
    $this->store->expects($this->never())->method('recover');
    $this->encryption->expects($this->never())->method('bindWithAppKey');
    self::assertFalse($this->service->authenticate($this->user));
  }

  /** @return void */
  public function testExpiredRecordCannotRecoverCredentials(): void
  {
    $this->wopiRequest(new MountTestWopi(expiry: time() - 1));
    $this->store->expects($this->never())->method('recover');
    $this->encryption->expects($this->never())->method('bindWithAppKey');
    self::assertFalse($this->service->authenticate($this->user));
  }

  /** @return void */
  public function testExpiredWopiTokenCannotRecoverCredentials(): void
  {
    $this->wopiRequest->method('isWopiRoute')->willReturn(true);
    $this->request->method('getParam')->willReturn('wopi-token');
    $this->wopiRequest->method('isValidatedWopiRequest')->willReturn(true);
    $this->mapper->method('getWopiForToken')->willThrowException(new RuntimeException());
    $this->store->expects($this->never())->method('recover');
    $this->session->expects($this->never())->method('login');
    self::assertFalse($this->service->authenticate($this->user));
  }

  /** @return void */
  public function testTokenErrorsDoNotLeakSecrets(): void
  {
    $this->wopiRequest();
    $this->store->method('recover')->willThrowException(new RuntimeException('wopi-token app-key'));
    $this->logger->expects($this->once())->method('warning')
      ->with('Unable to authenticate WOPI mounts: ' . RuntimeException::class);
    $this->session->expects($this->never())->method('login');
    self::assertFalse($this->service->authenticate($this->user));
  }

  /**
   * @param MountTestWopi ...$wopis
   *
   * @return void
   */
  private function wopiRequest(MountTestWopi ...$wopis): void
  {
    $this->wopiRequest->method('isWopiRoute')->willReturn(true);
    $this->request->method('getParam')->willReturn('wopi-token');
    $this->wopiRequest->method('isValidatedWopiRequest')->willReturn(true);
    $this->mapper->method('getWopiForToken')->willReturn(...($wopis ?: [new MountTestWopi()]));
  }
}
