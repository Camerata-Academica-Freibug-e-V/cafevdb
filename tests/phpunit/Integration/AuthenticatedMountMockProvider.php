<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Integration;

use OCA\CAFEVDB\Crypto\AsymmetricKeyService;
use OCA\CAFEVDB\Tests\MockProvider;

/** Provides the authenticated mount test's key service. */
class AuthenticatedMountMockProvider extends MockProvider
{
  private ?AsymmetricKeyService $keyService = null;

  /**
   * @param AsymmetricKeyService $keyService
   *
   * @return void
   */
  public function useKeyService(AsymmetricKeyService $keyService): void
  {
    $this->keyService = $keyService;
  }

  /** {@inheritdoc} */
  public function getAsymmetricKeyService(): AsymmetricKeyService
  {
    return $this->keyService ?? parent::getAsymmetricKeyService();
  }
}
