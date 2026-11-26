<?php
/**
 * WOPI middleware fixture for request classification tests.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

/** Exposes the current WOPI request validation state. */
class RequestTestWopiMiddleware
{
  public bool $validated = false;

  /** @return bool */
  public function isWOPIRequest(): bool
  {
    return $this->validated;
  }
}
