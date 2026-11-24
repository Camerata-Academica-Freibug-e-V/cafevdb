<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Integration;

use DateTimeImmutable;
use OCA\CAFEVDB\AppInfo\Application;
use OCA\CAFEVDB\Crypto\AsymmetricKeyService;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Service\AuthorizationService;
use OCA\CAFEVDB\Service\MountAuthenticationService;
use OCA\CAFEVDB\Service\WopiCredentialStore;
use OCA\CAFEVDB\Service\WopiRequestService;
use OCA\CAFEVDB\Settings\ConfigConstants;
use OCA\CAFEVDB\Storage\Database\Factory;
use OCA\CAFEVDB\Storage\Database\MountProvider;
use OCA\CAFEVDB\Storage\Database\ProjectParticipantsStorage;
use OCA\CAFEVDB\Tests\MockProvider;
use OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Service\GroupFoldersService;
use OCA\GroupFolders\Folder\FolderManager;
use OCA\GroupFolders\Mount\FolderStorageManager;
use OC\Files\Filesystem;
use OC\Files\Mount\MountPoint;
use OCP\App\IAppManager;
use OCP\Authentication\LoginCredentials\IStore as ICredentialsStore;
use OCP\Files\Config\IMountProviderCollection;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Server;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuthenticatedMountMockProvider extends MockProvider
{
  private ?AsymmetricKeyService $keyService = null;

  public function useKeyService(AsymmetricKeyService $keyService): void
  {
    $this->keyService = $keyService;
  }

  public function getAsymmetricKeyService(): AsymmetricKeyService
  {
    return $this->keyService ?? parent::getAsymmetricKeyService();
  }
}

#[CoversNothing]
class AuthenticatedDebitMandateMountTest extends TestCase
{
  use \OCA\CAFEVDB\Tests\Unit\Database\Doctrine\EnableDeprecationsTrait;
  use \OCA\CAFEVDB\Tests\Unit\Database\Doctrine\ORM\Entities\EntityGeneratorTrait;
  use \OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations\SetupMigrationTrait;
  use \OCA\CAFEVDB\Tests\Unit\Service\SetupCalendarBackendTrait;

  public function testAuthenticatedFinanceMountThroughFilesystem(): void
  {
    self::enableDeprecations();
    Server::get(IAppManager::class)->loadApp('groupfolders');
    $users = Server::get(IUserManager::class);
    $groups = Server::get(IGroupManager::class);
    $session = Server::get(IUserSession::class);
    $credentials = Server::get(ICredentialsStore::class);
    $request = Server::get(IRequest::class);
    $root = Server::get(IRootFolder::class);
    $mounts = Server::get(IMountManager::class);
    $providers = Server::get(IMountProviderCollection::class);
    $mountCache = $providers->getMountCache();
    $connection = Server::get(IDBConnection::class);
    $logger = Server::get(LoggerInterface::class);
    $folderManager = Server::get(FolderManager::class);
    $folderStorageManager = Server::get(FolderStorageManager::class);
    $l10n = Server::get(IL10NFactory::class)->get(Application::getAppName());
    $groupFolders = new GroupFoldersService($logger, $root, $folderManager, $folderStorageManager, $l10n);

    $uid = 'cafevdb-mount-' . bin2hex(random_bytes(6));
    $password = 'integration-test-password';
    $groupId = MockProvider::USER_GROUP_VALUE . AuthorizationService::FINANCE_GROUP_SUFFIX;
    self::assertNull($groups->get($groupId));
    $group = $groups->createGroup($groupId);
    $user = null;
    $folderId = null;
    try {
      self::assertInstanceOf(IGroup::class, $group);
      $user = $users->createUser($uid, $password);
      self::assertInstanceOf(IUser::class, $user);
      $group->addUser($user);
      self::assertTrue($session->login($uid, $password));
      self::assertTrue($session->isLoggedIn());
      self::assertSame($uid, $session->getUser()->getUID());
      self::assertSame($uid, $credentials->getLoginCredentials()->getUID());

      $folderId = $folderManager->createFolder('CAFEVDBIntegration');
      $folderManager->addApplicableGroup($folderId, $groupId);
      $folderInfo = $groupFolders->getFolderForUser('CAFEVDBIntegration', $user);
      self::assertNotNull($folderInfo);
      self::assertNotSame(0, $folderInfo->permissions & \OCP\Constants::PERMISSION_READ);
      Filesystem::tearDown();
      Filesystem::initMountPoints($user);
      self::assertSame(
        '/' . $uid . '/files/CAFEVDBIntegration/',
        $mounts->find('/' . $uid . '/files/CAFEVDBIntegration/')->getMountPoint(),
      );
      $userFolder = $root->getUserFolder($uid);
      $shared = $userFolder->get('CAFEVDBIntegration');
      self::assertInstanceOf(Folder::class, $shared);
      $shared->newFolder('Finance');
      $shared->newFolder('Projects');
      Filesystem::tearDown();

      $this->mockProvider = $mockProvider = AuthenticatedMountMockProvider::create($this);
      $this->mockProvider->registerClassInstance(IUserSession::class, $session, global: true);
      $this->mockProvider->registerClassInstance(ICredentialsStore::class, $credentials, global: true);
      $this->mockProvider->registerClassInstance(GroupFoldersService::class, $groupFolders);
      $authorization = new AuthorizationService(
        $uid,
        $groups,
        $groups->getSubAdmin(),
        $users,
        Application::getAppName(),
        $this->mockProvider->getCloudConfig(),
        $this->mockProvider->getLoggerInterface(),
      );
      $this->mockProvider->registerClassInstance(AuthorizationService::class, $authorization);
      self::assertTrue($authorization->authorized($uid, AuthorizationService::PERMISSION_FINANCE));
      $appContainer = $this->mockProvider->getAppContainer();
      $keyService = $appContainer->get(AsymmetricKeyService::class);
      $mockProvider->useKeyService($keyService);
      $this->generateCalendarBackend();
      $this->applyMigrations('latest');
      $this->generateProjectParticipant(persist: true, now: new DateTimeImmutable('2099-01-01'), delete: false);
      $entityManager = $this->getEntityManager();
      self::assertSame($uid, $entityManager->getUserId());

      $config = $this->mockProvider->getConfigService();
      foreach ([
        ConfigConstants::SHARED_FOLDER => 'CAFEVDBIntegration',
        ConfigConstants::PROJECTS_FOLDER => 'Projects',
        ConfigConstants::FINANCE_FOLDER => 'Finance',
        ConfigConstants::TRANSACTIONS_FOLDER => 'Transactions',
        ConfigConstants::BALANCES_FOLDER => 'Balances',
      ] as $key => $value) {
        self::assertTrue($config->setConfigValue($key, $value));
      }

      $participantStorage = new ProjectParticipantsStorage([
        'configService' => $config,
        'participant' => $this->participant,
      ]);
      $mandate = new Entities\SepaDebitMandate()
        ->setMusician($this->musician)
        ->setProject($this->project)
        ->setSepaBankAccount($this->musician->getSepaBankAccounts()->first())
        ->setMandateReference('MOUNT-1')
        ->setSequence(1)
        ->setNonRecurring(false)
        ->setMandateDate('2099-01-01');
      $file = new Entities\EncryptedFile('mandate', 'signed mandate', 'application/octet-stream', $this->musician);
      $entityManager->beginTransaction();
      $entityManager->persist($file);
      $entityManager->persist($mandate);
      $link = $participantStorage->addDebitMandate($mandate, $file, flush: false);
      $mandate->setWrittenMandate($link);
      $entityManager->flush();
      $entityManager->commit();
      $entityManager->clear();

      $authentication = new MountAuthenticationService(
        $session,
        $request,
        $this->mockProvider->getEncryptionService(),
        $appContainer->get(WopiCredentialStore::class),
        $appContainer,
        $logger,
        new WopiRequestService($request, $appContainer),
      );
      self::assertTrue($authentication->authenticate($user));
      $provider = new MountProvider(
        $config,
        $authorization,
        new Factory($config),
        $mountCache,
        $connection,
        $session,
        $authentication,
      );
      $providers->registerProvider($provider);
      Filesystem::initMountPoints($user);

      $mountName = $config->getAppL10n()->t('debit-mandates');
      $mountPath = '/' . $uid . '/files/CAFEVDBIntegration/Finance/' . $mountName . '/';
      $mount = $mounts->find($mountPath);
      self::assertInstanceOf(MountPoint::class, $mount);
      self::assertSame($mountPath, $mount->getMountPoint());
      self::assertSame(MountProvider::class, $mount->getMountProvider());
      self::assertSame(MountProvider::MOUNT_TYPE, $mount->getMountType());
      self::assertTrue($mount->getOption('readonly', false));
      self::assertTrue($mount->getOption('authenticated', false));
      self::assertFalse($mount->getOption('enable_sharing', true));

      $path = 'CAFEVDBIntegration/Finance/' . $mountName . '/'
        . $this->musician->getUserIdSlug() . '/' . $this->project->getName() . '/MOUNT-1';
      $mountedFile = $root->getUserFolder($uid)->get($path);
      self::assertInstanceOf(File::class, $mountedFile);
      self::assertSame('signed mandate', $mountedFile->getContent());
      try {
        $mountedFile->putContent('changed');
        self::fail('The finance mount must reject writes.');
      } catch (NotPermittedException $exception) {
        self::assertSame('signed mandate', $mountedFile->getContent());
      }
    } finally {
      Filesystem::tearDown();
      if ($folderId !== null) {
        $folderManager->removeFolder($folderId);
      }
      if ($user instanceof IUser) {
        $user->delete();
      }
      if ($group instanceof IGroup) {
        $group->delete();
      }
      $session->logout();
      Entities\DatabaseStorageFile::clearOrphans();
    }
  }
}
