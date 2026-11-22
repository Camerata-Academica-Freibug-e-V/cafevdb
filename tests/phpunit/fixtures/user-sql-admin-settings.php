<?php

namespace OCA\UserSQL\Settings;

use OCP\Settings\IDelegatedSettings;

class Admin implements IDelegatedSettings
{
  public function getForm()
  {
  }
  public function getSection()
  {
    return 'user_sql';
  }
  public function getPriority()
  {
    return 25;
  }
  public function getName(): ?string
  {
    return null;
  }
  public function getAuthorizedAppConfig(): array
  {
    return [];
  }
}
