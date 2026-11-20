<?php
/**
 * CAFEVDB -- Orchestra management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

use OC\AppFramework\Middleware\Security\Exceptions\NotAdminException;
use OCP\AppFramework\Http\Attribute as CoreAttributes;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IL10N;

use OCA\CAFEVDB\Attributes\SubAdminRequired;
use OCA\CAFEVDB\Controller\OrchestraSettingsController;
use OCA\CAFEVDB\Middleware\SubAdminMiddleware;
use OCA\CAFEVDB\Service\ConfigService;

/** Moving endpoints must not substitute settings delegation for orchestra rights. */
#[Attributes\CoversClass(OrchestraSettingsController::class)]
#[Attributes\CoversClass(SubAdminMiddleware::class)]
class OrchestraSettingsAuthorizationTest extends TestCase
{
  /** @return array<string, array{string, string, bool}> */
  public static function endpoints():array
  {
    return [
      'read allowed' => ['getApp', '/settings/app/get/{parameter}', true],
      'read denied' => ['getApp', '/settings/app/get/{parameter}', false],
      'write allowed' => ['setApp', '/settings/app/set/{parameter}', true],
      'write denied' => ['setApp', '/settings/app/set/{parameter}', false],
    ];
  }

  #[Attributes\DataProvider('endpoints')]
  public function testExistingRoutesStillRequireOrchestraSubAdmin(string $method, string $url, bool $allowed):void
  {
    $reflection = new ReflectionMethod(OrchestraSettingsController::class, $method);
    $this->assertNotEmpty($reflection->getAttributes(SubAdminRequired::class));
    $this->assertNotEmpty($reflection->getAttributes(CoreAttributes\NoAdminRequired::class));
    $route = $reflection->getAttributes(CoreAttributes\FrontpageRoute::class)[0]->getArguments();
    $this->assertSame('POST', $route['verb']);
    $this->assertSame($url, $route['url']);

    $config = $this->createMock(ConfigService::class);
    $config->expects($this->once())->method('isSubAdminOfGroup')->with()->willReturn($allowed);
    $l10n = $this->createStub(IL10N::class);
    $l10n->method('t')->willReturnArgument(0);
    $middleware = new SubAdminMiddleware(
      $config,
      $this->createStub(IControllerMethodReflector::class),
      $l10n,
      $this->createStub(LoggerInterface::class),
    );
    $controller = (new ReflectionClass(OrchestraSettingsController::class))->newInstanceWithoutConstructor();
    if (!$allowed) {
      $this->expectException(NotAdminException::class);
    }
    $middleware->beforeController($controller, $method);
  }
}
