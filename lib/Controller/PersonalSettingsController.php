<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2020-2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\CAFEVDB\Controller;

use Throwable;

use OCA\RotDrop\DevScripts\PhpToTypeScript;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute as CoreAttributes;
use Psr\Container\ContainerInterface;
use OCP\IRequest;

use OCA\CAFEVDB\Common\Util;
use OCA\CAFEVDB\Exceptions;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Settings\ConfigConstants;
use OCA\CAFEVDB\Storage\UserStorage;

/**
 * AJAX end-points for personal settings.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
#[PhpToTypeScript\Attributes\TypeScript]
class PersonalSettingsController extends Controller
{
  use \OCA\CAFEVDB\Traits\ConfigTrait;
  use \OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\ResponseTrait;

  public const BASE_PATH = 'settings';
  public const END_POINT_GET = 'get';
  public const END_POINT_PERSONAL_SET = 'personal/set';

  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    ?string $appName,
    IRequest $request,
    protected ConfigService $configService,
    protected ContainerInterface $appContainer,
  ) {
    parent::__construct($appName, $request);
    $this->l = $this->l10N();
  }
  // phpcs:enable

  /**
   * Store user settings.
   *
   * @param string $parameter
   *
   * @param mixed $value
   *
   * @return Http\Response
   */
  #[CoreAttributes\NoAdminRequired]
  #[CoreAttributes\FrontpageRoute(
    verb: 'POST',
    url: '/' . self::BASE_PATH . '/' . self::END_POINT_PERSONAL_SET . '/{parameter}',
  )]
  public function set(string $parameter, mixed $value):Http\Response
  {
    $parameter = Util::dashesToCamelCase($parameter);
    try {
      $key = EnumPersonalSettingsKey::get($parameter);
    } catch (Throwable $t) {
      throw new Exceptions\EnduserNotificationException(
        $this->l->t('Unknown configuration key "%s".', $parameter),
      );
    }
    switch ($key) {
      case EnumPersonalSettingsKey::DESELECT_INVISIBLE_MISC_RECS:
      case EnumPersonalSettingsKey::DIRECT_CHANGE:
      case EnumPersonalSettingsKey::EXPERT_MODE:
      case EnumPersonalSettingsKey::FINANCE_MODE:
      case EnumPersonalSettingsKey::INITIAL_FILTER_VISIBILITY:
      case EnumPersonalSettingsKey::RESTORE_HISTORY:
      case EnumPersonalSettingsKey::SHOW_DISABLED:
      case EnumPersonalSettingsKey::TOOL_TIPS_ENABLED:
        $realValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
        if ($realValue === null) {
          throw new Exceptions\EnduserNotificationException(
            $this->l->t('Value "%1$s" for set "%2$s" is not convertible to boolean.', [$value, $parameter]),
          );
        }
        $stringValue = $realValue ? 'on' : 'off';
        $this->setUserValue($parameter, $stringValue);
        return (new DTO\MessagesResponse([
          $this->l->t('Switching %2$s %1$s', [ $this->l->t($stringValue), $this->l->t($parameter), ]),
        ]))->response();

      case EnumPersonalSettingsKey::PAGE_ROWS_DEFAULT:
        $realValue = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => -1]]);
        if ($realValue === false) {
          throw new Exceptions\EnduserNotificationException(
            $this->l->t('Value "%1$s" for set "%2$s" is not in the allowed range.', [$value, $parameter]),
          );
        }
        $this->setUserValue($parameter, $realValue);
        return (new DTO\MessagesResponse([
          $this->l->t('Setting %2$s to %1$s', [$realValue, $parameter]),
        ]))->response();

      case EnumPersonalSettingsKey::DEBUG_MODE:
        if (!is_array($value)) {
          $debugModes = [];
        } else {
          $debugModes = $value;
        }
        $debug = 0;
        foreach ($debugModes as $item) {
          $debug |= $item['value'];
        }
        if ($debug > ConfigConstants::DEBUG_ALL) {
          throw new Exceptions\EnduserNotificationException(
            $this->l->t('Unknown debug modes in request: %s$s', [print_r($debugModes, true)]),
          );
        }
        $this->setUserValue(EnumPersonalSettingsKey::DEBUG_MODE->value, $debug);
        if ($debug & ConfigConstants::DEBUG_CSP) {
          // generate a random magic key for sort-of authentication
          $this->setUserValue(ConfigConstants::CSP_FAILURE_TOKEN_KEY, $this->generateRandomBytes(128));
        } else {
          $this->deleteUserValue(ConfigConstants::CSP_FAILURE_TOKEN_KEY);
        }
        return (new DTO\ValueResponse(
          messages: [
            $this->l->t('Setting %2$s to %1$d', [$debug, $key->value]),
          ],
          value: $debug
        ))->response();

      case EnumPersonalSettingsKey::DEBUG_QUERY_SQL_FILTER:
        $realValue = Util::normalizeSpaces($value);
        $this->setUserValue(EnumPersonalSettingsKey::DEBUG_QUERY_SQL_FILTER, $realValue);
        return (new DTO\ValueResponse(
          messages: [$this->l->t('Setting %2$s to %1$s', [$realValue, $key->value])],
          value: $realValue,
        ))->response();

      case EnumPersonalSettingsKey::WYSIWYG_EDITOR:
        if (!isset(ConfigConstants::WYSIWYG_EDITORS[$value])) {
          throw new Exceptions\EnduserNotificationException(
            $this->l->t('Unknown WYSIWYG-editor: %s$s', [ $value ]),
          );
        }
        $this->setUserValue($parameter, $value);
        return (new DTO\ValueResponse(
          messages: [$this->l->t('Setting %2$s to %1$s', [$value, $key->value])],
          value: $value,
        ))->response();

      case EnumPersonalSettingsKey::ENCRYPTION_KEY:
        // Get data
        if (!is_array($value) || !isset($value[EnumPersonalSettingsKey::ENCRYPTION_KEY->value]) || !isset($value['loginpassword'])) {
          throw new Exceptions\EnduserNotificationException(
            $this->l->t('Invalid request data: "%s".', [ print_r($value, true) ]),
          );
        }
        $password = $value['loginpassword'];
        $encryptionKey = $value[EnumPersonalSettingsKey::ENCRYPTION_KEY->value];

        // Re-validate the user
        if ($this->userManager()->checkPassword($this->userId(), $password) === false) {
          throw new Exceptions\EnduserNotificationException(
            $this->l->t('Invalid password for "%s".', [$this->userId()]),
          );
        }

        // Then check whether the key is correct
        if (!$this->encryptionKeyValid($encryptionKey)) {
          throw new Exceptions\EnduserNotificationException(
            $this->l->t('Invalid encryption key.'),
          );
        }

        // So generate a new key-pair and store the key. This will only
        // change the user's preferences.
        // @todo If we ever should encrypt anything else with the user's
        // SSL key-pair then we would need to be more careful about the
        // key-pair.
        try {
          $this->encryptionService()->initUserKeyPair(true);
          $this->encryptionService()->setUserEncryptionKey($encryptionKey);
          $this->encryptionService()->setAppEncryptionKey($encryptionKey);
        } catch (Throwable $t) {
          throw new Exceptions\EnduserNotificationException(
            message: $this->l->t('Unable to store the app encryption key for user "%s".', $this->userId()),
            previous: $t,
          );
        }
        return self::response($this->l->t('Encryption key stored.'));
      case EnumPersonalSettingsKey::EMAIL_DRAFT_AUTO_SAVE:
        $realValue = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($realValue === false) {
          $realValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
          if ($realValue === true) {
            $realValue = ConfigConstants::DEFAULT_AUTOSAVE_INTERVAL;
          } elseif ($realValue === false) {
            $realValue = 0;
          } else {
            throw new Exceptiosn\EnduserNotificationException(
              $this->l->t('Value "%1$s" for set "%2$s" must be a non-negative integer or false.', [$value, $parameter]),
            );
          }
        }
        $this->setUserValue($parameter, $realValue);
        return (new DTO\MessagesResponse(
          [$this->l->t('Setting %2$s to %1$s', [$realValue, $parameter])],
        ))->response();

      default:
    }
    throw new Exceptions\EnduserNotificationException($this->l->t('Unknown Request'));
  }

  /**
   * Get some stuff.
   *
   * @param string $parameter
   *
   * @return Http\Response
   */
  #[CoreAttributes\NoAdminRequired]
  #[CoreAttributes\FrontpageRoute(
    verb: 'POST',
    url: '/' . self::BASE_PATH . '/' . self::END_POINT_GET . '/{parameter}',
  )]
  public function get(string $parameter):Http\Response
  {
    switch ($parameter) {
      case 'locale-info':
        $scope = $this->request->getParam('scope');
        $localeSymbol = ($scope ?? 'personal') == 'personal' ? $this->getLocale() : $this->appLocale();
        return self::dataResponse([
          'localeSymbol' => $localeSymbol,
          'currencyCode' => $this->currencyCode($localeSymbol),
          'currencySymbol' => $this->currencySymbol($localeSymbol),
          'dateTimeZone' => $this->getDateTimeZone()->getName(),
        ]);
      case 'passwordgenerate':
      case 'generatepassword':
        return self::valueResponse($this->generateRandomBytes(32));
      case ConfigConstants::DOCUMENT_TEMPLATES_FOLDER:
        $sharedFolder = $this->getConfigValue(ConfigConstants::SHARED_FOLDER);
        if (empty($sharedFolder)) {
          return self::grumble($this->l->t('Shared folder is not configured.'));
        }
        $templatesFolder = $this->getConfigValue(ConfigConstants::DOCUMENT_TEMPLATES_FOLDER);
        if (empty($templatesFolder)) {
          return self::grumble($this->l->t('Document template folder is not configured.'));
        }
        $templatesFolder = UserStorage::PATH_SEP
          . $sharedFolder . UserStorage::PATH_SEP;
        return self::dataResponse($templatesFolder);
      default:
        break;
    }
    return self::grumble($this->l->t('Unknown Request: "%s"', $parameter));
  }
}
