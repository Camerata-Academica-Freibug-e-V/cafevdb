<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Integration;

use DateTimeImmutable;
use OCA\CAFEVDB\AppInfo\Application;
use OCA\CAFEVDB\Crypto\AsymmetricKeyService;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Listener\ContactsCardEventListener;
use OCA\CAFEVDB\Listener\GroupListener;
use OCA\CAFEVDB\Listener\GroupMembershipListener;
use OCA\CAFEVDB\Service\AuthorizationService;
use OCA\CAFEVDB\Service\MountAuthenticationService;
use OCA\CAFEVDB\Service\ParticipantFileAuditService;
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
use OC\EventDispatcher\EventDispatcher;
use OC\EventDispatcher\ServiceEventListener;
use OCP\App\IAppManager;
use OCP\Authentication\LoginCredentials\IStore as ICredentialsStore;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\IEventDispatcher;
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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcher;

/** @SuppressWarnings(PHPMD.CouplingBetweenObjects) */
#[CoversNothing]
class AuthenticatedDebitMandateMountTest extends TestCase
{
  use \OCA\CAFEVDB\Tests\Unit\Database\Doctrine\EnableDeprecationsTrait;
  use \OCA\CAFEVDB\Tests\Unit\Database\Doctrine\ORM\Entities\EntityGeneratorTrait;
  use \OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations\SetupMigrationTrait;
  use \OCA\CAFEVDB\Tests\Unit\Service\SetupCalendarBackendTrait;

  /** @return void */
  #[RunInSeparateProcess]
  #[PreserveGlobalState(false)]
  public function testAuthenticatedFinanceMountThroughFilesystem(): void
  {
    self::enableDeprecations();
    Server::get(IAppManager::class)->loadApp('groupfolders');
    $users = Server::get(IUserManager::class);
    $groups = Server::get(IGroupManager::class);
    $session = Server::get(IUserSession::class);
    $credentials = Server::get(ICredentialsStore::class);
    $userConfig = Server::get(IUserConfig::class);
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
    $folderName = 'CAFEVDBIntegration-' . $uid;
    $groupBase = $uid . '-group';
    $groupId = $groupBase . AuthorizationService::FINANCE_GROUP_SUFFIX;
    self::assertNull($groups->get($groupId));
    $dispatcher = Server::get(IEventDispatcher::class);
    $suspendedListeners = $this->suspendFixtureListeners($dispatcher);
    $group = null;
    $user = null;
    $folderId = null;
    $mockProvider = null;
    $migrationsApplied = false;
    $providerRegistry = null;
    $previousProvider = null;
    try {
      $group = $groups->createGroup($groupId);
      self::assertInstanceOf(IGroup::class, $group);
      $user = $users->createUser($uid, $password);
      self::assertInstanceOf(IUser::class, $user);
      $group->addUser($user);
      self::assertTrue($session->login($uid, $password));
      self::assertTrue($session->isLoggedIn());
      self::assertSame($uid, $session->getUser()->getUID());
      self::assertSame($uid, $credentials->getLoginCredentials()->getUID());

      $folderId = $folderManager->createFolder($folderName);
      $folderManager->addApplicableGroup($folderId, $groupId);
      $folderInfo = $groupFolders->getFolderForUser($folderName, $user);
      self::assertNotNull($folderInfo);
      self::assertNotSame(0, $folderInfo->permissions & \OCP\Constants::PERMISSION_READ);
      Filesystem::tearDown();
      Filesystem::initMountPoints($user);
      self::assertSame(
        '/' . $uid . '/files/' . $folderName . '/',
        $mounts->find('/' . $uid . '/files/' . $folderName . '/')->getMountPoint(),
      );
      $userFolder = $root->getUserFolder($uid);
      $shared = $userFolder->get($folderName);
      self::assertInstanceOf(Folder::class, $shared);
      $shared->newFolder('Finance');
      $shared->newFolder('Projects');
      Filesystem::tearDown();

      $this->mockProvider = $mockProvider = AuthenticatedMountMockProvider::create($this);
      $this->mockProvider->registerClassInstance(IUserSession::class, $session, global: true);
      $this->mockProvider->registerClassInstance(ICredentialsStore::class, $credentials, global: true);
      $this->mockProvider->registerClassInstance(GroupFoldersService::class, $groupFolders);
      $this->mockProvider->getCloudConfig()->setAppValue(
        Application::getAppName(),
        ConfigConstants::USER_GROUP_KEY,
        $groupBase,
      );
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
      $migrationsApplied = true;
      $this->generateProjectParticipant(persist: true, now: new DateTimeImmutable('2099-01-01'), delete: false);
      $entityManager = $this->getEntityManager();
      self::assertSame($uid, $entityManager->getUserId());

      $config = $this->mockProvider->getConfigService();
      foreach ([
        ConfigConstants::SHARED_FOLDER => $folderName,
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
      $logEntries = $entityManager->getRepository(Entities\LogEntry::class);
      self::assertInstanceOf(\DateTimeInterface::class, $logEntries->modificationTime());
      self::assertNull($logEntries->modificationTime('nonexistent-entity'));
      $clubProjectId = (int)$config->getConfigValue(ConfigConstants::CLUB_MEMBER_PROJECT_ID_KEY, 0);
      $audit = (new ParticipantFileAuditService($entityManager->getConnection(), $clubProjectId))->audit();
      self::assertGreaterThanOrEqual(1, $audit['summary']['document_references']);
      self::assertSame([], array_values(array_filter(
        $audit['findings'],
        static fn(array $finding) => ($finding['file_id'] ?? null) === $file->getId(),
      )));

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
      $providerRegistry = new ReflectionProperty($providers, 'providers');
      $previousProvider = $providerRegistry->getValue($providers)[MountProvider::class] ?? null;
      $providers->registerProvider($provider);
      Filesystem::initMountPoints($user);

      $mountName = $config->getAppL10n()->t('debit-mandates');
      $mountPath = '/' . $uid . '/files/' . $folderName . '/Finance/' . $mountName . '/';
      $mount = $mounts->find($mountPath);
      self::assertInstanceOf(MountPoint::class, $mount);
      self::assertSame($mountPath, $mount->getMountPoint());
      self::assertSame(MountProvider::class, $mount->getMountProvider());
      self::assertSame(MountProvider::MOUNT_TYPE, $mount->getMountType());
      self::assertTrue($mount->getOption('readonly', false));
      self::assertTrue($mount->getOption('authenticated', false));
      self::assertFalse($mount->getOption('enable_sharing', true));

      $path = $folderName . '/Finance/' . $mountName . '/'
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
      try {
        Filesystem::tearDown();
        if ($mockProvider !== null) {
          $mockProvider->registerClassInstance(IUserConfig::class, $userConfig, global: true);
        }
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
      } finally {
        try {
          if ($providerRegistry !== null) {
            $registeredProviders = $providerRegistry->getValue($providers);
            if ($previousProvider === null) {
              unset($registeredProviders[MountProvider::class]);
            } else {
              $registeredProviders[MountProvider::class] = $previousProvider;
            }
            $providerRegistry->setValue($providers, $registeredProviders);
          }
        } finally {
          try {
            if ($migrationsApplied) {
              $this->unapplyMigrations();
            }
          } finally {
            foreach ($suspendedListeners as [$event, $listener, $priority]) {
              $dispatcher->addListener($event, $listener, $priority);
            }
          }
        }

      }
    }
  }

  /**
   * Keep fixture lifecycle events in core and DAV without invoking ambient app services.
   *
   * @param IEventDispatcher $dispatcher
   *
   * @return array
   */
  private function suspendFixtureListeners(IEventDispatcher $dispatcher): array
  {
    self::assertInstanceOf(EventDispatcher::class, $dispatcher);
    $symfony = new ReflectionProperty(EventDispatcher::class, 'dispatcher')->getValue($dispatcher);
    self::assertInstanceOf(SymfonyEventDispatcher::class, $symfony);
    $listenerClass = new ReflectionProperty(ServiceEventListener::class, 'class');
    $suspended = [];
    foreach ([ContactsCardEventListener::class, GroupListener::class, GroupMembershipListener::class] as $class) {
      $count = 0;
      foreach ($class::EVENT as $event) {
        foreach ($symfony->getListeners($event) as $listener) {
          if ($listener instanceof ServiceEventListener && $listenerClass->getValue($listener) === $class) {
            $suspended[] = [$event, $listener, $symfony->getListenerPriority($event, $listener)];
            ++$count;
          }
        }
      }
      self::assertSame(count($class::EVENT), $count, 'Expected fixture event listeners to be registered for ' . $class);
    }
    foreach ($suspended as [$event, $listener]) {
      $dispatcher->removeListener($event, $listener);
    }
    return $suspended;
  }
}
