<?php
/**
 * Orchestra member, musician and project management application.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Listener;

use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

use OCA\CAFEVDB\Listener\ParticipantFieldCloudFolderListener;

/** Tests filesystem event handling before participant-field lookup. */
#[CoversClass(ParticipantFieldCloudFolderListener::class)]
final class ParticipantFieldCloudFolderListenerTest extends TestCase
{
  /** The target of a pre-rename event need not exist yet. */
  public function testBeforeRenameUsesSourceType(): void
  {
    $source = $this->createMock(Node::class);
    $source->expects($this->once())->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
    $target = $this->createMock(Node::class);
    $target->expects($this->never())->method('getType');

    $this->listenerWithoutUser()->handle(new BeforeNodeRenamedEvent($source, $target));
  }

  /** The source of a post-rename event need not exist anymore. */
  public function testAfterRenameUsesTargetType(): void
  {
    $source = $this->createMock(Node::class);
    $source->expects($this->never())->method('getType');
    $target = $this->createMock(Node::class);
    $target->expects($this->once())->method('getType')->willReturn(FileInfo::TYPE_FOLDER);

    $this->listenerWithoutUser()->handle(new NodeRenamedEvent($source, $target));
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
