<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Service;

use OCA\Richdocuments\Db\WopiMapper;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/** Establish the credentials needed by the database mounts. */
class MountAuthenticationService
{
  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    private IUserSession $userSession,
    private IRequest $request,
    private EncryptionService $encryptionService,
    private WopiCredentialStore $credentialStore,
    private ContainerInterface $appContainer,
    private LoggerInterface $logger,
    private WopiRequestService $wopiRequestService,
  ) {
  }
  // phpcs:enable

  /**
   * Authenticate a mount user using token-encrypted app credentials if needed.
   *
   * The caller must first check the user's filesystem permission.
   *
   * @param IUser $user Mount user.
   *
   * @return boolean Whether the required credentials are available.
   */
  public function authenticate(IUser $user): bool
  {
    if (!$this->wopiRequestService->isWopiRoute()) {
      return $this->userSession->isLoggedIn()
        && $this->userSession->getUser()?->getUID() === $user->getUID();
    }

    try {
      if (!$this->wopiRequestService->isValidatedWopiRequest()) {
        $this->logger->debug('Cannot authenticate mounts before WOPI request validation.');
        return false;
      }

      $accessToken = $this->request->getParam('access_token');
      if (!is_string($accessToken) || $accessToken === '') {
        return false;
      }
      $wopi = $this->appContainer->get(WopiMapper::class)->getWopiForToken($accessToken);
      $userId = $user->getUID();
      if ($wopi->getTokenType() !== $wopi::TOKEN_TYPE_USER
          || $wopi->getEditorUid() !== $userId
          || $wopi->getUserForFileAccess() !== $userId
          || $wopi->getExpiry() <= time()) {
        return false;
      }

      $activeUserId = $this->userSession->getUser()?->getUID();
      if ($activeUserId !== null && $activeUserId !== $userId) {
        return false;
      }
      $boundUserId = $this->encryptionService->getUserId();
      if ($boundUserId !== null && $boundUserId !== $userId) {
        return false;
      }
      if ($this->userSession->isLoggedIn() && $boundUserId === $userId) {
        return true;
      }

      $appKey = $this->credentialStore->recover($accessToken, $userId, $wopi->getExpiry());
      if ($appKey === null) {
        $this->logger->warning('No registered app credentials for WOPI mount.');
        return false;
      }
      if (!$this->userSession->isLoggedIn()) {
        $this->userSession->setUser($user);
      }
      $this->encryptionService->bindWithAppKey($userId, $appKey);
      return $this->encryptionService->getUserId() === $userId;
    } catch (Throwable $exception) {
      // Exceptions from credential storage may contain credential material.
      $this->logger->warning('Unable to authenticate WOPI mounts: ' . $exception::class);
      return false;
    }
  }
}
