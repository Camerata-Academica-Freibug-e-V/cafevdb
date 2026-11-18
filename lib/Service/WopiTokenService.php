<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Service;

use OCA\Richdocuments\Db\WopiMapper;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use RuntimeException;
use SensitiveParameter;

/** Store credentials in the format consumed by WOPI mount authentication. */
class WopiTokenService
{
  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    private IUserSession $userSession,
    private AuthorizationService $authorizationService,
    private EncryptionService $encryptionService,
    private IRootFolder $rootFolder,
    private ContainerInterface $appContainer,
    private WopiCredentialStore $credentialStore,
  ) {
  }
  // phpcs:enable

  /**
   * Provision credentials only for an authorized local user's authenticated mount.
   *
   * @param string $accessToken Token returned by richdocuments, not a request parameter.
   *
   * @return void
   */
  public function provideCredentials(#[SensitiveParameter] string $accessToken): void
  {
    $user = $this->userSession->getUser();
    if ($user === null || !$this->userSession->isLoggedIn()
        || !$this->authorizationService->authorized($user->getUID(), AuthorizationService::PERMISSION_FILESYSTEM)) {
      return;
    }
    $wopi = $this->appContainer->get(WopiMapper::class)->getWopiForToken($accessToken);
    $userId = $user->getUID();
    if ($wopi->getTokenType() !== $wopi::TOKEN_TYPE_USER
        || $wopi->getEditorUid() !== $userId || $wopi->getUserForFileAccess() !== $userId) {
      return;
    }
    $file = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($wopi->getFileid());
    if ($file === null) {
      throw new RuntimeException('WOPI file is unavailable.');
    }
    if (!$file->getMountPoint()->getOption('authenticated', false)) {
      return;
    }
    $appKey = $this->encryptionService->getAppEncryptionKey();
    if ($this->encryptionService->getUserId() !== $userId || $appKey === null || $appKey === '') {
      throw new RuntimeException('Matching app encryption credentials are unavailable.');
    }
    $this->credentialStore->store($accessToken, $userId, $wopi->getExpiry(), $appKey);
  }
}
