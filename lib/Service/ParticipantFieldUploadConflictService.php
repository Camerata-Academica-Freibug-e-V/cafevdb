<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author GitHub Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Service;

use DateTime;
use Throwable;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Notification\IManager as NotificationManager;
use Psr\Log\LoggerInterface;

use OCA\CAFEVDB\Notifications\Notifier;

/** Free a reserved participant-field path without discarding the uploaded file. */
class ParticipantFieldUploadConflictService
{
  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    private string $appName,
    private IRootFolder $rootFolder,
    private NotificationManager $notificationManager,
    private LoggerInterface $logger,
  ) {
  }
  // phpcs:enable

  /**
   * @param string $userId The uploader's user ID.
   * @param File $node The newly created file at the reserved path.
   *
   * @return void
   */
  public function schedule(string $userId, File $node): void
  {
    $path = $node->getPath();
    $fileId = $node->getId();
    $etag = $node->getEtag();
    // Let DAV finish reading upload metadata and release its locks first.
    register_shutdown_function(fn() => $this->reconcile($userId, $path, $fileId, $etag));
  }

  /**
   * Recheck the conflict at request shutdown and move only the original upload.
   *
   * @param string $userId The uploader's user ID.
   * @param string $path The original absolute file path.
   * @param int $fileId The original file ID.
   * @param string $etag The original file ETag.
   *
   * @return void
   */
  public function reconcile(string $userId, string $path, int $fileId, string $etag): void
  {
    $source = null;
    $destination = null;
    try {
      $userFolder = $this->rootFolder->getUserFolder($userId);
      $prefix = $userFolder->getPath() . '/';
      if (!str_starts_with($path, $prefix)) {
        $this->logger->warning("Upload conflict is outside the uploader's files.", ['path' => $path]);
        return;
      }
      $source = substr($path, strlen($prefix));
      try {
        $file = $userFolder->get($source);
      } catch (NotFoundException) {
        return; // The conflict has already been resolved.
      }
      if (!$file instanceof File || $file->getId() !== $fileId) {
        return;
      }
      if ($file->getEtag() === $etag) {
        $parent = $file->getParent();
        // An unpredictable suffix avoids competing for the next numbered name.
        $extension = mb_strcut(pathinfo($file->getName(), PATHINFO_EXTENSION), 0, 100, 'UTF-8');
        $name = $parent->getNonExistingName('upload-conflict-' . bin2hex(random_bytes(16)) . ($extension === '' ? '' : '.' . $extension));
        $moved = $file->move($parent->getPath() . '/' . $name);
        $destination = substr($moved->getPath(), strlen($prefix));
      } else {
        $this->logger->warning('Conflicting upload changed before it could be renamed.', ['path' => $path]);
      }
    } catch (Throwable $e) {
      $this->logger->error('Could not rename conflicting upload.', ['path' => $path, 'exception' => $e]);
    }

    if ($source !== null) {
      try {
        $notification = $this->notificationManager->createNotification()
          ->setApp($this->appName)
          ->setUser($userId)
          ->setDateTime(new DateTime())
          ->setObject('participant_field_upload_conflict', (string)$fileId)
          ->setSubject(Notifier::PARTICIPANT_FIELD_UPLOAD_CONFLICT_SUBJECT, ['source' => $source, 'destination' => $destination]);
        $this->notificationManager->notify($notification);
      } catch (Throwable $e) {
        $this->logger->error('Could not notify the uploader of a participant-field conflict.', ['path' => $path, 'exception' => $e]);
      }
    }
  }
}
