<?php
/**
 * Orchestra member, musician and project management application.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Listener;

use OCP\Files\Events\Node\BeforeNodeCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

use OCA\CAFEVDB\Listener\ParticipantFieldCloudFolderListener;
use OCA\CAFEVDB\Listener\ParticipantFieldNodeEventsTrait;
use OCA\Files_Trashbin\Events\BeforeNodeRestoredEvent;
use OCA\Files_Trashbin\Events\NodeRestoredEvent;

/** Tests filesystem event handling before participant-field lookup. */
#[CoversClass(ParticipantFieldCloudFolderListener::class)]
#[CoversTrait(ParticipantFieldNodeEventsTrait::class)]
final class ParticipantFieldCloudFolderListenerTest extends TestCase
{
  /** The target of a pre-rename event need not exist yet. */
  public function testBeforeRenameUsesSourceType(): void
  {
    $source = $this->createMock(Node::class);
    $source->method('getPath')->willReturn('/alice/files/old');
    $source->expects($this->once())->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
    $target = $this->createMock(Node::class);
    $target->method('getPath')->willReturn('/alice/files/new');
    $target->expects($this->never())->method('getType');

    $this->listenerWithoutUser()->handle(new BeforeNodeRenamedEvent($source, $target));
  }

  /** The source of a post-rename event need not exist anymore. */
  public function testAfterRenameUsesTargetType(): void
  {
    $source = $this->createMock(Node::class);
    $source->method('getPath')->willReturn('/alice/files/old');
    $source->expects($this->never())->method('getType');
    $target = $this->createMock(Node::class);
    $target->method('getPath')->willReturn('/alice/files/new');
    $target->expects($this->once())->method('getType')->willReturn(FileInfo::TYPE_FOLDER);

    $this->listenerWithoutUser()->handle(new NodeRenamedEvent($source, $target));
  }

  /** Before a trash restore, only the trash item has metadata. */
  public function testBeforeRestoreUsesTrashItemType(): void
  {
    $source = $this->createMock(Node::class);
    $source->expects($this->once())->method('getType')->willReturn(FileInfo::TYPE_FILE);
    $target = $this->createMock(Node::class);
    $target->expects($this->never())->method('getType');
    $run = true;

    $this->listenerWithoutUser()->handle(new BeforeNodeRestoredEvent($source, $target, $run));
  }

  /** A restore synchronizes the destination without looking up the now-missing trash item. */
  public function testAfterRestoreUsesRestoredNode(): void
  {
    $source = $this->createMock(Node::class);
    $source->expects($this->never())->method('getPath');
    $source->expects($this->never())->method('getType');
    $target = $this->createMock(Node::class);
    $target->expects($this->once())->method('getType')->willReturn(FileInfo::TYPE_FILE);

    $this->listenerWithoutUser()->handle(new NodeRestoredEvent($source, $target));
  }

  /** Use only the trashbin event if a patched server also emits a rename event. */
  public function testRestoreRenameDoesNotRunTwice(): void
  {
    $source = $this->createStub(Node::class);
    $source->method('getPath')->willReturn('/alice/files_trashbin/files/report.pdf.d123');
    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->never())->method('get');

    (new ParticipantFieldCloudFolderListener($container))->handle(
      new NodeRenamedEvent($source, $this->createStub(Node::class))
    );
  }

  /** Files and folders are indistinguishable until the stock create event completes. */
  public function testBeforeCreateDoesNotReadPlaceholderType(): void
  {
    $node = $this->createMock(Node::class);
    $node->expects($this->never())->method('getType');

    $this->listenerWithoutUser()->handle(new BeforeNodeCreatedEvent($node));
  }

  /** A completed mkdir must not be handled as a file upload. */
  public function testAfterCreateReadsActualNodeType(): void
  {
    $node = $this->createMock(Node::class);
    $node->method('getPath')->willReturn('/alice/files/new');
    $node->expects($this->once())->method('getType')->willReturn(FileInfo::TYPE_FOLDER);

    $this->listenerWithoutUser()->handle(new NodeCreatedEvent($node));
  }

  /** Stop handling after extracting the node information. */
  private function listenerWithoutUser(): ParticipantFieldCloudFolderListener
  {
    $session = $this->createStub(IUserSession::class);
    $session->method('getUser')->willReturn(null);
    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->once())->method('get')->with(IUserSession::class)->willReturn($session);

    return new ParticipantFieldCloudFolderListener($container);
  }
}
