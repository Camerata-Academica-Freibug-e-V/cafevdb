<?php
/**
 * CAFEVDB -- Orchestra management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute as CoreAttributes;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

use OCA\CAFEVDB\Attributes;
use OCA\CAFEVDB\Service\AuthorizationService;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Settings\ConfigConstants;

/** Group details for the app's administration settings. */
class CloudGroupsController extends Controller
{
  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    IRequest $request,
    private IGroupManager $groupManager,
    private IUserSession $userSession,
    private IConfig $cloudConfig,
    private ConfigService $configService,
  ) {
    parent::__construct($appName, $request);
  }
  // phpcs:enable

  /**
   * @param string $search Group ID or display name to search for.
   * @param int $limit Maximum number of groups.
   * @param int $offset Number of matching groups to skip.
   * @return DataResponse
   */
  #[CoreAttributes\NoAdminRequired]
  #[Attributes\NoGroupMemberRequired]
  #[CoreAttributes\FrontpageRoute(verb: 'GET', url: '/settings/cloud-groups')]
  public function getDetails(string $search = '', int $limit = 10, int $offset = 0): DataResponse
  {
    $user = $this->userSession->getUser();
    if ($user === null) {
      return new DataResponse([], Http::STATUS_FORBIDDEN);
    }
    $isAdmin = $this->groupManager->isAdmin($user->getUID());
    if (!$isAdmin && !$this->configService->isSubAdminOfGroup()) {
      return new DataResponse([], Http::STATUS_FORBIDDEN);
    }
    if ($limit < 1 || $offset < 0) {
      return new DataResponse([], Http::STATUS_BAD_REQUEST);
    }

    if ($isAdmin) {
      $groups = $this->groupManager->search($search, $limit, $offset);
    } else {
      $orchestraGroupId = $this->cloudConfig->getAppValue($this->appName, ConfigConstants::USER_GROUP_KEY);
      $groups = [];
      if ($orchestraGroupId !== '') {
        foreach (AuthorizationService::GROUP_SUFFIX_LIST as $suffix) {
          $group = $this->groupManager->get($orchestraGroupId . $suffix);
          if ($group !== null && ($search === ''
              || mb_stripos($group->getGID(), $search) !== false
              || mb_stripos($group->getDisplayName(), $search) !== false)) {
            $groups[] = $group;
          }
        }
      }
      $groups = array_slice($groups, $offset, $limit);
    }

    return new DataResponse(['groups' => array_map(
      static fn(IGroup $group) => [
        'id' => $group->getGID(),
        'displayname' => $group->getDisplayName(),
        'usercount' => $group->count(),
        'disabled' => $group->countDisabled(),
        'canAdd' => $group->canAddUser(),
        'canRemove' => $group->canRemoveUser(),
        'backends' => array_values($group->getBackendNames()),
      ],
      $groups,
    )]);
  }
}
