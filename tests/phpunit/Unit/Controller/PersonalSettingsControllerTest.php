<?php
/**
 * CAFEVDB -- Orchestra management application.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;

use OCA\CAFEVDB\Controller\PersonalSettingsController;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\L10N\L10NFactory;
use OCA\CAFEVDB\Service\Registration;
use OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\AppInfo\AbstractApplication;

/** Personal endpoints remain available independently of orchestra settings. */
#[Attributes\CoversClass(PersonalSettingsController::class)]
#[Attributes\UsesClass(ConfigService::class)]
#[Attributes\UsesClass(L10NFactory::class)]
#[Attributes\UsesClass(Registration::class)]
#[Attributes\UsesClass(AbstractApplication::class)]
class PersonalSettingsControllerTest extends TestCase
{
  use TestRoutesAreDefinedTrait;

  private const CONTROLLER_CLASS = PersonalSettingsController::class;
  private const EXPECTED_ROUTES = ['get', 'set'];
}
