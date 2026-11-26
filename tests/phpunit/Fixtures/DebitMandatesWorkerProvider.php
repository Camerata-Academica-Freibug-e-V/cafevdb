<?php
/**
 * Concurrent debit-mandate reconciliation worker fixture.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Fixtures;

use OCA\CAFEVDB\Settings\ConfigConstants;
use OCA\CAFEVDB\Tests\MockProvider;
use OCA\RotDrop\Tests\EnumConfigSection;

/** Provides database settings for the concurrent worker. */
class DebitMandatesWorkerProvider extends MockProvider
{
  public static array $databaseConfig;

  /** {@inheritdoc} */
  protected function cloudConfigGet(
    EnumConfigSection $section,
    ?string $key,
    ?string $app = null,
    ?string $user = null,
  ): mixed {
    if ($section === EnumConfigSection::APP && $app === $this->appName) {
      $value = match ($key) {
        ConfigConstants::APP_DB_NAME => self::$databaseConfig['databaseName'],
        ConfigConstants::APP_DB_SERVER => self::$databaseConfig['databaseServer'],
        ConfigConstants::APP_DB_USER => self::$databaseConfig['databaseUser'],
        ConfigConstants::APP_DB_PASSWORD => self::$databaseConfig['databasePassword'],
        default => null,
      };
      if ($value !== null) {
        return $value;
      }
    }
    return parent::cloudConfigGet($section, $key, $app, $user);
  }
}
