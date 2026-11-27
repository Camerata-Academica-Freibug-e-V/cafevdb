<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author GitHub Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;

use OCA\CAFEVDB\Notifications\Notifier;
use OCA\CAFEVDB\Service\LostAndFoundNotificationService;

/** The blocked deletion is reported only to the operator. */
#[Attributes\CoversClass(LostAndFoundNotificationService::class)]
class LostAndFoundNotificationServiceTest extends TestCase
{
  /** @return void */
  public function testNotifiesOnlyTheOperatorWithBlockers(): void
  {
    $blockers = [['entry_id' => 42, 'reference' => 'Invoices.written_invoice_id']];
    $notification = $this->createMock(INotification::class);
    $notification->expects(self::once())->method('setApp')->with('cafevdb')->willReturnSelf();
    $notification->expects(self::once())->method('setUser')->with('alice')->willReturnSelf();
    $notification->method('setDateTime')->willReturnSelf();
    $notification->expects(self::once())->method('setObject')->with('lost_found_delete', '42')->willReturnSelf();
    $notification->expects(self::once())->method('setSubject')->with(
      Notifier::LOST_FOUND_DELETE_BLOCKED_SUBJECT,
      ['path' => '2026-11-27/file.pdf', 'blockers' => $blockers],
    )->willReturnSelf();
    $manager = $this->createMock(IManager::class);
    $manager->method('createNotification')->willReturn($notification);
    $manager->expects(self::once())->method('notify')->with($notification);

    (new LostAndFoundNotificationService('cafevdb', $manager))->notify('alice', '2026-11-27/file.pdf', 42, $blockers);
  }
}
