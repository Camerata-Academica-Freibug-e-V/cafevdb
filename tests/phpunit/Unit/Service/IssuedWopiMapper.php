<?php
/**
 * WOPI mapper fixture for token issuance tests.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

/** Returns the default issued WOPI token. */
class IssuedWopiMapper
{
  /**
   * @param string $token
   *
   * @return IssuedWopiToken
   */
  public function getWopiForToken(string $token): IssuedWopiToken
  {
    return new IssuedWopiToken();
  }
}
