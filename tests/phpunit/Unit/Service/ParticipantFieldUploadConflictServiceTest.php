<?php
/**
 * Orchestra member, musician and project management application.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use OCA\CAFEVDB\Notifications\Notifier;
use OCA\CAFEVDB\Service\ParticipantFieldUploadConflictService;

/** Covers the file preserved by conflict reconciliation and the uploader's notification. */
#[CoversClass(ParticipantFieldUploadConflictService::class)]
final class ParticipantFieldUploadConflictServiceTest extends TestCase
{
  private const HOME = '/alice/files';
  private const SOURCE = 'Participants/Details.pdf';
  private const DESTINATION = 'Participants/recovered.pdf';

  /** A successful move reports the new path, not just the reserved name. */
  public function testRenamesUploadAndNotifiesItsUploader(): void
  {
    $file = $this->conflictingFile();
    $parent = $this->createMock(Folder::class);
    $parent->method('getPath')->willReturn(self::HOME . '/Participants');
    $parent->expects($this->once())->method('getNonExistingName')
      ->with($this->matchesRegularExpression('/^upload-conflict-[a-f0-9]{32}\.pdf$/'))
      ->willReturn('recovered.pdf');
    $file->method('getParent')->willReturn($parent);
    $file->method('getName')->willReturn('Details.pdf');
    $moved = $this->createStub(File::class);
    $moved->method('getPath')->willReturn(self::HOME . '/' . self::DESTINATION);
    $file->expects($this->once())->method('move')->with(self::HOME . '/' . self::DESTINATION)->willReturn($moved);

    $this->service($file, $this->notificationManager(self::DESTINATION))->reconcile('alice', self::HOME . '/' . self::SOURCE, 42, 'uploaded');
  }

  /** A permission failure leaves the upload in place and tells the uploader what to do. */
  public function testReportsFailedRename(): void
  {
    $file = $this->conflictingFile();
    $parent = $this->createStub(Folder::class);
    $parent->method('getPath')->willReturn(self::HOME . '/Participants');
    $parent->method('getNonExistingName')->willReturn('recovered.pdf');
    $file->method('getParent')->willReturn($parent);
    $file->method('getName')->willReturn('Details.pdf');
    $file->expects($this->once())->method('move')->willThrowException(new NotPermittedException());

    $this->service($file, $this->notificationManager(null))->reconcile('alice', self::HOME . '/' . self::SOURCE, 42, 'uploaded');
  }

  /** An independently replaced item must not be moved or generate a notification. */
  public function testLeavesReplacementUntouched(): void
  {
    $replacement = $this->conflictingFile(43);
    $replacement->expects($this->never())->method('move');
    $notifications = $this->createMock(IManager::class);
    $notifications->expects($this->never())->method('createNotification');

    $this->service($replacement, $notifications)->reconcile('alice', self::HOME . '/' . self::SOURCE, 42, 'uploaded');
  }

  /** @return File&\PHPUnit\Framework\MockObject\MockObject */
  private function conflictingFile(int $id = 42): File
  {
    $file = $this->createMock(File::class);
    $file->method('getId')->willReturn($id);
    $file->method('getEtag')->willReturn('uploaded');

    return $file;
  }

  /** Prepare the expected notification, addressed only to the uploader. */
  private function notificationManager(?string $destination): IManager
  {
    $notification = $this->createMock(INotification::class);
    $notification->method('setApp')->willReturnSelf();
    $notification->method('setDateTime')->willReturnSelf();
    $notification->method('setObject')->willReturnSelf();
    $notification->expects($this->once())->method('setUser')->with('alice')->willReturnSelf();
    $notification->expects($this->once())->method('setSubject')->with(
      Notifier::PARTICIPANT_FIELD_UPLOAD_CONFLICT_SUBJECT,
      ['source' => self::SOURCE, 'destination' => $destination],
    )->willReturnSelf();
    $notifications = $this->createMock(IManager::class);
    $notifications->method('createNotification')->willReturn($notification);
    $notifications->expects($this->once())->method('notify')->with($notification);

    return $notifications;
  }

  /** Return a service resolving only the uploader's own file path. */
  private function service(File $file, IManager $notifications): ParticipantFieldUploadConflictService
  {
    $folder = $this->createMock(Folder::class);
    $folder->method('getPath')->willReturn(self::HOME);
    $folder->expects($this->once())->method('get')->with(self::SOURCE)->willReturn($file);
    $root = $this->createMock(IRootFolder::class);
    $root->expects($this->once())->method('getUserFolder')->with('alice')->willReturn($folder);

    return new ParticipantFieldUploadConflictService('cafevdb', $root, $notifications, new NullLogger());
  }
}
