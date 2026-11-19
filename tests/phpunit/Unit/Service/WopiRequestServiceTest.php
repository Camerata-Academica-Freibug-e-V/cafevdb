<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use OCA\CAFEVDB\Service\WopiRequestService;
use OCA\Richdocuments\Middleware\WOPIMiddleware;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class RequestTestWopiMiddleware
{
  public bool $validated = false;

  public function isWOPIRequest(): bool
  {
    return $this->validated;
  }
}

#[CoversClass(WopiRequestService::class)]
class WopiRequestServiceTest extends TestCase
{
  #[DataProvider('routePaths')]
  public function testEarlyClassificationDoesNotResolveMiddleware(string $path, bool $expected): void
  {
    $request = $this->createMock(IRequest::class);
    $request->expects($this->once())->method('getRawPathInfo')->willReturn($path);
    $request->expects($this->never())->method('getParam');
    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->never())->method('get');

    self::assertSame($expected, (new WopiRequestService($request, $container))->isWopiRoute());
  }

  public static function routePaths(): array
  {
    return [
      'WOPI endpoint' => ['/apps/richdocuments/wopi/files/42', true],
      'subdirectory and front controller' => ['/nextcloud/index.php/apps/richdocuments/wopi/files/42', true],
      'route root' => ['/apps/richdocuments/wopi', true],
      'similar prefix' => ['/apps/richdocuments/wopi-other/files/42', false],
      'different app' => ['/apps/other/wopi/files/42', false],
      'ordinary DAV request' => ['/remote.php/dav/files/alice', false],
      'empty path' => ['', false],
    ];
  }

  public function testValidationUsesCurrentMiddlewareStateNotThePath(): void
  {
    $request = $this->createMock(IRequest::class);
    $request->expects($this->never())->method('getRawPathInfo');
    $middleware = new RequestTestWopiMiddleware();
    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->exactly(2))->method('get')->with(WOPIMiddleware::class)->willReturn($middleware);
    $service = new WopiRequestService($request, $container);

    self::assertFalse($service->isValidatedWopiRequest());
    $middleware->validated = true;
    self::assertTrue($service->isValidatedWopiRequest());
  }
}
