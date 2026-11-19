<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2011-2016, 2020-2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Settings;

use OCP\IInitialStateService;
use OCP\Util as CoreUtil;

use OCA\CAFEVDB\Controller\EnumPersonalSettingsKey;
use OCA\CAFEVDB\Service\AssetService;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\ErrorService;
use OCA\CAFEVDB\Service\OrganizationalRolesService;

/** Prepare personal preferences without loading orchestra configuration services. */
class PersonalForm
{
  use \OCA\CAFEVDB\Traits\ConfigTrait;
  use \OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\ResponseTrait;

  public const ERROR_TEMPLATE = 'errorpage';
  public const TEMPLATE = 'personal-settings';
  public const DEFAULT_EDITOR = 'tinymce';

  /** {@inheritdoc} */
  public function __construct(
    protected ConfigService $configService,
    private AssetService $assetService,
    private ErrorService $errorService,
    private IInitialStateService $initialStateService,
    private OrganizationalRolesService $roles,
  ) {
    $this->l = $this->l10N();
  }

  /** @return \OCP\AppFramework\Http\TemplateResponse */
  public function getForm()
  {
    CoreUtil::addScript($this->appName(), $this->assetService->getJSAsset(self::TEMPLATE));
    CoreUtil::addStyle($this->appName(), $this->assetService->getCSSAsset(self::TEMPLATE));

    if (!$this->inGroup()) {
      return $this->templateResponse(self::ERROR_TEMPLATE, [
        'error' => 'notamember',
        'userId' => $this->userId(),
      ]);
    }
    try {
      $this->initialStateService->provideInitialState(
        $this->appName(),
        'CAFEVDB',
        [
          'appName' => $this->appName(),
          'financeModeAllowed' => $this->roles->inTreasurerGroup(),
          EnumPersonalSettingsKey::TOOL_TIPS_ENABLED->value => $this->getUserValue(EnumPersonalSettingsKey::TOOL_TIPS_ENABLED, 'on'),
          EnumPersonalSettingsKey::RESTORE_HISTORY->value => $this->getUserValue(EnumPersonalSettingsKey::RESTORE_HISTORY, 'off'),
          EnumPersonalSettingsKey::INITIAL_FILTER_VISIBILITY->value => $this->getUserValue(EnumPersonalSettingsKey::INITIAL_FILTER_VISIBILITY, 'off'),
          EnumPersonalSettingsKey::DIRECT_CHANGE->value => $this->getUserValue(EnumPersonalSettingsKey::DIRECT_CHANGE, 'off'),
          EnumPersonalSettingsKey::DESELECT_INVISIBLE_MISC_RECS->value => $this->getUserValue(EnumPersonalSettingsKey::DESELECT_INVISIBLE_MISC_RECS, 'off'),
          EnumPersonalSettingsKey::SHOW_DISABLED->value => $this->getUserValue(EnumPersonalSettingsKey::SHOW_DISABLED, 'off'),
          'language' => $this->configService->getCloudUserConfig()->getValueString($this->userId(), 'core', 'lang', 'en'),
          EnumPersonalSettingsKey::WYSIWYG_EDITOR->value => $this->getUserValue(EnumPersonalSettingsKey::WYSIWYG_EDITOR, self::DEFAULT_EDITOR),
          EnumPersonalSettingsKey::EXPERT_MODE->value => $this->getUserValue(EnumPersonalSettingsKey::EXPERT_MODE),
          EnumPersonalSettingsKey::DEBUG_MODE->value => (int)$this->getUserValue(EnumPersonalSettingsKey::DEBUG_MODE, 0),
          EnumPersonalSettingsKey::DEBUG_QUERY_SQL_FILTER->value => (string)$this->getUserValue(EnumPersonalSettingsKey::DEBUG_QUERY_SQL_FILTER, ''),
          EnumPersonalSettingsKey::PAGE_ROWS_DEFAULT->value => (int)$this->getUserValue(EnumPersonalSettingsKey::PAGE_ROWS_DEFAULT, 20),
          'userId' => $this->userId(),
          'localeSymbol' => $this->getLocale(),
          'currencyCode' => $this->currencyCode(),
          'currencySymbol' => $this->currencySymbol(),
          'dateTimeZone' => $this->getDateTimeZone()->getName(),
          EnumPersonalSettingsKey::FINANCE_MODE->value => $this->getUserValue(EnumPersonalSettingsKey::FINANCE_MODE),
        ],
      );
      $this->initialStateService->provideInitialState($this->appName(), 'PHPMyEdit', []);
      return $this->templateResponse(self::TEMPLATE, []);
    } catch (\Exception $e) {
      return $this->errorService->exceptionTemplate($e);
    }
  }
}
