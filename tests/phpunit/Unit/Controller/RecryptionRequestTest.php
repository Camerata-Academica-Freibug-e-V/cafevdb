<?php
/**
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Controller;

use OC\AppFramework\Middleware\MiddlewareDispatcher;
use OC\AppFramework\Middleware\OCSMiddleware;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

use OCA\CAFEVDB\AppInfo\Application;
use OCA\CAFEVDB\Controller\EncryptionController;
use OCA\CAFEVDB\Crypto\AsymmetricKeyService;
use OCA\CAFEVDB\Middleware\ExceptionMiddleware;
use OCA\CAFEVDB\Service\AuthorizationService;
use OCA\CAFEVDB\Service\UserEncryptionService;
use OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Middleware\ExceptionMiddleware as ToolkitExceptionMiddleware;

/** Recryption requests and their OCS error responses. */
#[Attributes\CoversClass(EncryptionController::class)]
#[Attributes\CoversClass(ExceptionMiddleware::class)]
#[Attributes\UsesClass(Application::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Controller\DTO\UserRecryptionResponse::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\DTO\AbstractResponseDTO::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Exceptions\EnduserNotificationException::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Listener\BeforeMessageLoggedEventListener::class)]
#[Attributes\UsesClass(ToolkitExceptionMiddleware::class)]
class RecryptionRequestTest extends TestCase
{
  private AsymmetricKeyService $keyService;
  private UserEncryptionService $userEncryptionService;
  private ContainerInterface $container;
  private EncryptionController $controller;
  private IRequest $request;
  private IL10N $l;

  /** {@inheritdoc} */
  protected function setUp(): void
  {
    $this->request = $this->createStub(IRequest::class);
    $this->request->method('getScriptName')->willReturn('/ocs/v2.php');
    $this->request->method('getFormat')->willReturn('json');
    $this->l = $this->createStub(IL10N::class);
    $this->l->method('t')->willReturnCallback(
      static fn(string $message, mixed $parameters = []): string => vsprintf($message, (array)$parameters),
    );
    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('member');
    $session = $this->createStub(IUserSession::class);
    $session->method('getUser')->willReturn($user);
    $authorization = $this->createStub(AuthorizationService::class);
    $authorization->method('isAdmin')->willReturn(false);
    $this->container = $this->createStub(ContainerInterface::class);
    $this->container->method('get')->willReturnMap([
      [IUserSession::class, $session],
      [AuthorizationService::class, $authorization],
    ]);
    $this->keyService = $this->createStub(AsymmetricKeyService::class);
    $this->userEncryptionService = $this->createStub(UserEncryptionService::class);
    $this->createController();
  }

  /** @return void */
  private function createController(): void
  {
    $this->controller = new EncryptionController(
      'cafevdb',
      $this->request,
      $this->container,
      $this->keyService,
      $this->userEncryptionService,
      new NullLogger,
      $this->l,
    );
  }

  /** @return void */
  public function testMissingRequestIsNotFound(): void
  {
    $this->keyService->method('getRecryptionRequests')->willReturn([]);
    $this->expectException(OCSNotFoundException::class);
    $this->controller->getRecryptRequests('member');
  }

  /** @return void */
  public function testExistingRequestIsReturned(): void
  {
    $this->keyService->method('getRecryptionRequests')->willReturn(['member' => 123]);
    self::assertSame(
      ['request' => 123, 'userId' => 'member'],
      $this->controller->getRecryptRequests('member')->getData(),
    );
  }

  /** @return void */
  public function testAnotherUsersRequestIsForbidden(): void
  {
    $keyService = $this->createMock(AsymmetricKeyService::class);
    $keyService->expects(self::never())->method('getRecryptionRequests');
    $this->keyService = $keyService;
    $this->createController();
    $this->expectException(OCSForbiddenException::class);
    $this->controller->getRecryptRequests('another');
  }

  /** @return void */
  public function testMissingRequestIsNotRecrypted(): void
  {
    $this->keyService->method('getRecryptionRequests')->willReturn([]);
    $userEncryptionService = $this->createMock(UserEncryptionService::class);
    $userEncryptionService->expects(self::never())->method('recrypt');
    $this->userEncryptionService = $userEncryptionService;
    $this->createController();
    $this->expectException(OCSNotFoundException::class);
    $this->controller->handleRecryptRequest('member');
  }

  /** @return void */
  public function testPendingRequestIsRecrypted(): void
  {
    $keyService = $this->createMock(AsymmetricKeyService::class);
    $keyService->method('getRecryptionRequests')->willReturn(['member' => 123]);
    $keyService->expects(self::once())->method('pushRecryptionRequestHandledNotification')
      ->with('member')->willReturn($this->createStub(INotification::class));
    $userEncryptionService = $this->createMock(UserEncryptionService::class);
    $userEncryptionService->expects(self::once())->method('recrypt')->with('member')->willReturn(true);
    $this->keyService = $keyService;
    $this->userEncryptionService = $userEncryptionService;
    $this->createController();
    self::assertSame(200, $this->controller->handleRecryptRequest('member')->getStatus());
  }

  /** @return void */
  public function testSilentAdministrativeRecryptionDoesNotRequireRequest(): void
  {
    $keyService = $this->createMock(AsymmetricKeyService::class);
    $keyService->expects(self::never())->method('getRecryptionRequests');
    $userEncryptionService = $this->createMock(UserEncryptionService::class);
    $userEncryptionService->expects(self::once())->method('recrypt')->with('member')->willReturn(true);
    $this->keyService = $keyService;
    $this->userEncryptionService = $userEncryptionService;
    $this->createController();
    self::assertSame(200, $this->controller->handleRecryptRequest('member', notifyUser: false)->getStatus());
  }

  /** @return void */
  public function testAppUsesOcsAwareMiddleware(): void
  {
    self::assertInstanceOf(
      ExceptionMiddleware::class,
      Application::getAppContainer()->get(ToolkitExceptionMiddleware::class),
    );
  }

  /**
   * @param OCSException $exception
   *
   * @param int $status
   *
   * @return void
   */
  #[Attributes\DataProvider('ocsErrors')]
  public function testOcsErrorsReachCoreMiddleware(OCSException $exception, int $status): void
  {
    $appMiddleware = Application::getAppContainer()->get(ToolkitExceptionMiddleware::class);
    $dispatcher = new MiddlewareDispatcher();
    $dispatcher->registerMiddleware(new OCSMiddleware($this->request));
    $dispatcher->registerMiddleware($appMiddleware);
    $dispatcher->beforeController($this->controller, 'getRecryptRequests');
    $response = $dispatcher->afterException($this->controller, 'getRecryptRequests', $exception);
    $payload = json_decode($response->render(), true, flags: JSON_THROW_ON_ERROR);

    self::assertSame($status, $response->getStatus());
    self::assertSame($status, $payload['ocs']['meta']['statuscode']);
    self::assertSame('failure', $payload['ocs']['meta']['status']);
    self::assertSame($exception->getMessage(), $payload['ocs']['meta']['message']);
  }

  /** @return array */
  public static function ocsErrors(): array
  {
    return [
      'not found' => [new OCSNotFoundException('not found'), 404],
      'forbidden' => [new OCSForbiddenException('forbidden'), 403],
    ];
  }

  /** @return void */
  public function testUnexpectedExceptionsRemainServerErrors(): void
  {
    $middleware = Application::getAppContainer()->get(ToolkitExceptionMiddleware::class);
    $response = $middleware->afterException(
      $this->controller,
      'getRecryptRequests',
      new RuntimeException('unexpected failure'),
    );

    self::assertSame(500, $response->getStatus());
  }
}
