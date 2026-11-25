<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Database\Doctrine\ORM\Listeners;

use OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\StorageChangeCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StorageChangeCollector::class)]
class StorageChangeCollectorTest extends TestCase
{
  public function testCommitHandsOverAndClearsChanges(): void
  {
    $collector = new StorageChangeCollector;
    $received = [];
    $collector->addConsumer(function(array $changes) use (&$received) {
      $received[] = $changes;
    });

    $collector->commit();
    self::assertSame([], $received);

    $collector->record('s', 'a');
    $collector->record('s', 'b', 'old');
    $collector->commit();
    self::assertSame([['s' => ['a' => null, 'b' => 'old']]], $received);
    self::assertFalse($collector->hasChanges());
  }

  public function testDiscardDropsChanges(): void
  {
    $collector = new StorageChangeCollector;
    $collector->record('s', 'a');
    $collector->discard();
    self::assertFalse($collector->hasChanges());
  }

  public function testChainOfMovesKeepsOldestPath(): void
  {
    $collector = new StorageChangeCollector;
    $collector->record('s', 'b', 'a');
    $collector->record('s', 'b', 'x');
    $collector->record('s', 'b');
    self::assertSame(['s' => ['b' => 'a']], $collector->getChanges());
  }

  public function testFailingConsumerDoesNotHinderOthers(): void
  {
    $collector = new StorageChangeCollector;
    $called = false;
    $collector->addConsumer(fn() => throw new \RuntimeException('failed'));
    $collector->addConsumer(function() use (&$called) {
      $called = true;
    });
    $collector->record('s', 'a');
    $collector->commit();
    self::assertTrue($called);
  }
}
