<?php
/**
 * CAFEVDB -- Orchestra management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Listener;

use OCP\Group\Events\SubAdminAddedEvent;
use OCP\Group\Events\SubAdminRemovedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use OCA\CAFEVDB\Listener\SubAdminEventListener;
use OCA\CAFEVDB\Listener\Registration;

#[CoversClass(SubAdminEventListener::class)]
#[CoversClass(Registration::class)]
final class SubAdminEventListenerTest extends TestCase
{
  public function testBothSubadminEventsAreRegistered(): void
  {
    $registered = [];
    $context = $this->createStub(IRegistrationContext::class);
    $context->method('registerEventListener')->willReturnCallback(
      static function(string $event, string $listener) use (&$registered): void {
        if ($listener === SubAdminEventListener::class) {
          $registered[] = $event;
        }
      },
    );
    Registration::register($context);
    self::assertSame([SubAdminAddedEvent::class, SubAdminRemovedEvent::class], $registered);
  }
}
