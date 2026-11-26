<?php
/**
 * Shared PHPUnit environment constants, loaded after the app autoloaders.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

define('ROT_DROP_PHPUNIT', true);
define('PHPUNIT_NC_APP_NAME', \OCA\CAFEVDB\AppInfo\Application::getAppName());
define('PHPUNIT_NC_APP_NAMESPACE', \OCA\CAFEVDB::class);
define('PHPUNIT_NC_MOCKED_APPS', [\OCA\CAFEVDB::class]);
define('PHPUNIT_APPDIR', realpath(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Service\AppInfoService::getAppFolderPath()));
define('PHPUNIT_ARTIFACTS', PHPUNIT_APPDIR . '/build/artifacts/tests/phpunit');
