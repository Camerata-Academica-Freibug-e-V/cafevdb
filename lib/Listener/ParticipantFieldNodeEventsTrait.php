<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author GitHub Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Listener;

use OCP\EventDispatcher\Event;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeCopiedEvent;
use OCP\Files\Events\Node\BeforeNodeCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Node;
use OCP\HintException;

use OCA\Files_Trashbin\Events\BeforeNodeRestoredEvent;
use OCA\Files_Trashbin\Events\NodeRestoredEvent;

/** Translate Nextcloud filesystem events into participant-field changes. */
trait ParticipantFieldNodeEventsTrait
{
  const EVENT = [
    NodeRenamedEvent::class,
    NodeCopiedEvent::class,
    NodeDeletedEvent::class,
    NodeCreatedEvent::class,
    BeforeNodeRenamedEvent::class,
    BeforeNodeCopiedEvent::class,
    BeforeNodeDeletedEvent::class,
    BeforeNodeCreatedEvent::class,
    BeforeNodeRestoredEvent::class,
    NodeRestoredEvent::class,
  ];

  private const ADD_KEY = 'add';
  private const DEL_KEY = 'remove';
  private const NODE_FULL_PATH = 'fullPath';
  private const NODE_TYPE = 'type';

  /** @var array<string, int> Copy targets whose generic create event is redundant. */
  private $ignoreCreatedPaths = [];

  /**
   * @param Event $event
   *
   * @return null|array{array, bool, ?Node} Changes, whether to validate only, and the newly created node.
   */
  private function describeNodeEvent(Event $event): ?array
  {
    $nodes = [];
    $checkOnly = false;
    $createdNode = null;
    switch (get_class($event)) {
      case BeforeNodeDeletedEvent::class:
        $checkOnly = true;
        // fallthrough
      case NodeDeletedEvent::class:
        /** @var BeforeNodeDeletedEvent|NodeDeletedEvent $event */
        $node = $event->getNode();
        $nodes[self::DEL_KEY] = [
          self::NODE_FULL_PATH => $node->getPath(),
          self::NODE_TYPE => $node->getType(),
        ];
        break;
      case BeforeNodeRenamedEvent::class:
        $checkOnly = true;
        // fallthrough
      case NodeRenamedEvent::class:
        /** @var BeforeNodeRenamedEvent|NodeRenamedEvent $event */
        $source = $event->getSource();
        $target = $event->getTarget();
        $sourcePath = $source->getPath();
        if (preg_match('~^/[^/]+/files_trashbin/files/~', $sourcePath)) {
          // The trashbin events handle this operation even on servers that emit rename hooks for it.
          return null;
        }
        // Only one endpoint is guaranteed to exist when this event fires.
        $type = ($checkOnly ? $source : $target)->getType();
        $nodes[self::DEL_KEY] = [
          self::NODE_FULL_PATH => $sourcePath,
          self::NODE_TYPE => $type,
        ];
        $nodes[self::ADD_KEY] = [
          self::NODE_FULL_PATH => $target->getPath(),
          self::NODE_TYPE => $type,
        ];
        break;
      case BeforeNodeRestoredEvent::class:
        $checkOnly = true;
        // fallthrough
      case NodeRestoredEvent::class:
        /** @var BeforeNodeRestoredEvent|NodeRestoredEvent $event */
        $target = $event->getTarget();
        $nodes[self::ADD_KEY] = [
          self::NODE_FULL_PATH => $target->getPath(),
          self::NODE_TYPE => ($checkOnly ? $event->getSource() : $target)->getType(),
        ];
        break;
      case BeforeNodeCopiedEvent::class:
        $checkOnly = true;
        // fallthrough
      case NodeCopiedEvent::class:
        /** @var BeforeNodeCopiedEvent|NodeCopiedEvent $event */
        $source = $event->getSource();
        $target = $event->getTarget();
        $nodes[self::ADD_KEY] = [
          self::NODE_FULL_PATH => $target->getPath(),
          self::NODE_TYPE => $source->getType(), // The target may not exist yet.
        ];
        if (!$checkOnly) {
          $this->ignoreCreatedPaths[$target->getPath()] = $target->getId();
        }
        break;
      case BeforeNodeCreatedEvent::class:
        $checkOnly = true;
        // fallthrough
      case NodeCreatedEvent::class:
        /** @var BeforeNodeCreatedEvent|NodeCreatedEvent $event */
        $node = $event->getNode();
        $path = $node->getPath();
        if (!$checkOnly && isset($this->ignoreCreatedPaths[$path])) {
          $copiedId = $this->ignoreCreatedPaths[$path];
          unset($this->ignoreCreatedPaths[$path]);
          if ($node->getId() === $copiedId) {
            return null;
          }
        }
        // Stock Nextcloud uses the same placeholder for files and mkdir before creation.
        $createdNode = $checkOnly ? null : $node;
        $nodes[self::ADD_KEY] = [
          self::NODE_FULL_PATH => $path,
          self::NODE_TYPE => $createdNode?->getType(),
        ];
        break;
      default:
        return null;
    }

    return [$nodes, $checkOnly, $createdNode];
  }

  /**
   * @param Event $event
   * @param HintException $exception
   *
   * @return HintException|AbortedEventException
   */
  private function eventVeto(Event $event, HintException $exception): HintException|AbortedEventException
  {
    return $event instanceof BeforeNodeRestoredEvent
      ? new AbortedEventException($exception->getMessage(), previous: $exception)
      : $exception;
  }
}
