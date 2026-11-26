<?php
/**
 * WOPI mapper fixture for mount authentication tests.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

/** Returns the default mount authentication record. */
class MountTestWopiMapper
{
  /**
   * @param string $token
   *
   * @return MountTestWopi
   */
  public function getWopiForToken(string $token): MountTestWopi
  {
    return new MountTestWopi();
  }
}
