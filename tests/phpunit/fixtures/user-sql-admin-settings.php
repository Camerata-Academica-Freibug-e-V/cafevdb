<?php
/**
 * Delegated settings fixture for user_sql authorization tests.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\UserSQL\Settings;

use OCP\Settings\IDelegatedSettings;

/** Admin. */
class Admin implements IDelegatedSettings
{
  /** {@inheritdoc} */
  public function getForm()
  {
  }
  /** {@inheritdoc} */
  public function getSection()
  {
    return 'user_sql';
  }
  /** {@inheritdoc} */
  public function getPriority()
  {
    return 25;
  }
  /** @return null|string */
  public function getName(): ?string
  {
    return null;
  }
  /** @return array */
  public function getAuthorizedAppConfig(): array
  {
    return [];
  }
}
