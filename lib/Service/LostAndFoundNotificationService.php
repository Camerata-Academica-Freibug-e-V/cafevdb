<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author GitHub Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Service;

use DateTime;
use OCP\Notification\IManager;

use OCA\CAFEVDB\Notifications\Notifier;

/** Notify the user whose recovered-file deletion was blocked. */
class LostAndFoundNotificationService
{
  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(private string $appName, private IManager $manager)
  {
  }
  // phpcs:enable

  /**
   * @param string $userId
   * @param string $path
   * @param integer $entryId
   * @param array $blockers
   *
   * @return void
   */
  public function notify(string $userId, string $path, int $entryId, array $blockers): void
  {
    $notification = $this->manager->createNotification()
      ->setApp($this->appName)
      ->setUser($userId)
      ->setDateTime(new DateTime())
      ->setObject('lost_found_delete', (string)$entryId)
      ->setSubject(Notifier::LOST_FOUND_DELETE_BLOCKED_SUBJECT, ['path' => $path, 'blockers' => $blockers]);
    $this->manager->notify($notification);
  }
}
