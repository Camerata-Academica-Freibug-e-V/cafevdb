<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

use OCP\App\IAppManager;
use OCP\IInitialStateService;
use OCP\IL10N;

use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Settings\PersonalForm;

#[Attributes\CoversClass(PersonalForm::class)]
class PersonalFormTest extends TestCase
{
  /** @return void */
  public function testAboutInitialStateIncludesAppMetadataAndNormalizesSingleCredit():void
  {
    $form = (new ReflectionClass(PersonalForm::class))->newInstanceWithoutConstructor();
    $config = $this->createStub(ConfigService::class);
    $config->method('getAppName')->willReturn('cafevdb');
    (new ReflectionProperty(PersonalForm::class, 'configService'))->setValue($form, $config);
    $l10n = $this->createStub(IL10N::class);
    $l10n->method('t')->willReturnArgument(0);
    (new ReflectionProperty(PersonalForm::class, 'l'))->setValue($form, $l10n);
    $apps = $this->createMock(IAppManager::class);
    $apps->expects($this->once())->method('getAppInfo')->with('cafevdb')->willReturn([
      'name' => 'CafeVDB',
      'version' => '1.2.3',
      'description' => 'Orchestra app',
      'licence' => 'AGPL-3.0',
      'credits' => ['item' => ['title' => 'Dependency', 'link' => 'https://example.org']],
    ]);
    (new ReflectionProperty(PersonalForm::class, 'appManager'))->setValue($form, $apps);
    $state = $this->createMock(IInitialStateService::class);
    $state->expects($this->once())->method('provideInitialState')->with(
      'cafevdb',
      'about',
      $this->callback(function(array $about):bool {
        return $about['name'] === 'CafeVDB'
          && $about['version'] === '1.2.3'
          && $about['description'] === 'Orchestra app'
          && $about['licence'] === 'AGPL-3.0'
          && $about['credits'] === [['title' => 'Dependency', 'link' => 'https://example.org']];
      }),
    );
    (new ReflectionProperty(PersonalForm::class, 'initialStateService'))->setValue($form, $state);

    (new ReflectionMethod(PersonalForm::class, 'publishAbout'))->invoke($form);
  }
}
