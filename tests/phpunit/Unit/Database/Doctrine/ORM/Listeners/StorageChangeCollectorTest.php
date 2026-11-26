<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Database\Doctrine\ORM\Listeners;

use RuntimeException;
use OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\StorageChangeCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Storage Change Collector Test. */
#[CoversClass(StorageChangeCollector::class)]
class StorageChangeCollectorTest extends TestCase
{
  /** @return void */
  public function testCommitHandsOverAndClearsChanges(): void
  {
    $collector = new StorageChangeCollector($this->createStub(LoggerInterface::class));
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

  /** @return void */
  public function testDiscardDropsChanges(): void
  {
    $collector = new StorageChangeCollector($this->createStub(LoggerInterface::class));
    $collector->record('s', 'a');
    $collector->discard();
    self::assertFalse($collector->hasChanges());
  }

  /** @return void */
  public function testChainOfMovesKeepsOldestPath(): void
  {
    $collector = new StorageChangeCollector($this->createStub(LoggerInterface::class));
    $collector->record('s', 'b', 'a');
    $collector->record('s', 'b', 'x');
    $collector->record('s', 'b');
    self::assertSame(['s' => ['b' => 'a']], $collector->getChanges());
  }

  /** @return void */
  public function testFailingConsumerDoesNotHinderOthers(): void
  {
    $exception = new RuntimeException('failed');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with('Unable to notify storage cache changes after commit.', ['exception' => $exception]);
    $collector = new StorageChangeCollector($logger);
    $called = false;
    $collector->addConsumer(fn() => throw $exception);
    $collector->addConsumer(function() use (&$called) {
      $called = true;
    });
    $collector->record('s', 'a');
    $collector->commit();
    self::assertTrue($called);
  }
}
