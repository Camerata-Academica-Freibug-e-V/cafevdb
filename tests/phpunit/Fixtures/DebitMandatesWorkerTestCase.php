<?php
/**
 * Concurrent debit-mandate reconciliation worker fixture.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Fixtures;

use OCA\CAFEVDB\Storage\UserStorage;
use PHPUnit\Framework\TestCase;

/** Supplies PHPUnit stubs to the concurrent worker. */
class DebitMandatesWorkerTestCase extends TestCase
{
  /** @return UserStorage */
  public function userStorage(): UserStorage
  {
    return $this->createStub(UserStorage::class);
  }
}
