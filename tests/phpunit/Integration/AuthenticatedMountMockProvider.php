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
use OCA\CAFEVDB\Settings\ConfigConstants;
use OCA\CAFEVDB\Settings\OldSettingsKeys;
use OCA\CAFEVDB\Tests\MockProvider;
use OCA\RotDrop\Tests\EnumConfigSection;

/** Provides the authenticated mount test's key service. */
class AuthenticatedMountMockProvider extends MockProvider
{
  private ?AsymmetricKeyService $keyService = null;
  private ?string $groupBase = null;

  /**
   * @param string $groupBase
   *
   * @return void
   */
  public function useGroupBase(string $groupBase): void
  {
    $this->groupBase = $groupBase;
  }

  /** {@inheritdoc} */
  protected function cloudConfigGet(
    EnumConfigSection $section,
    ?string $key,
    ?string $app = null,
    ?string $user = null,
  ): mixed {
    if ($this->groupBase !== null
        && $section === EnumConfigSection::APP
        && $app === $this->appName
        && in_array($key, [ConfigConstants::USER_GROUP_KEY, OldSettingsKeys::APP_KEYS[ConfigConstants::USER_GROUP_KEY]], true)) {
      return $this->groupBase;
    }
    return parent::cloudConfigGet($section, $key, $app, $user);
  }

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
