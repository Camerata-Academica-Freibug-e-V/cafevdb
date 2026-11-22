<?php
/**
 * CAFEVDB -- Orchestra management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Ip\IRemoteAddress;
use OCP\Settings\IManager;

use OCA\CAFEVDB\Exceptions\DatabaseCloudConnectorViewException;
use OCA\CAFEVDB\Service\CloudUserConnectorService;
use OCA\CAFEVDB\Service\EncryptionService;
use OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Service\RequestService;
use OCA\UserSQL\Settings\Admin as UserSqlAdminSettings;

#[Attributes\CoversClass(CloudUserConnectorService::class)]
class UserSqlSettingsAuthorizationTest extends TestCase
{
  public static function permissions(): array
  {
    return [
      'administrator' => [true, false, true, true, true],
      'delegated settings' => [false, true, true, true, true],
      'no delegation' => [false, false, true, true, false],
      'admin IP denied' => [false, true, false, true, false],
      'app disabled for user' => [false, true, true, false, false],
    ];
  }

  #[Attributes\RunInSeparateProcess]
  #[Attributes\DataProvider('permissions')]
  public function testSettingsAccess(
    bool $admin,
    bool $delegated,
    bool $ipAllowed,
    bool $appEnabled,
    bool $allowed,
  ): void {
    if (!class_exists(UserSqlAdminSettings::class)) {
      require_once __DIR__ . '/../../fixtures/user-sql-admin-settings.php';
    }
    $settings = (new ReflectionClass(UserSqlAdminSettings::class))->newInstanceWithoutConstructor();
    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('orchestra-user');
    $session = $this->createStub(IUserSession::class);
    $session->method('getUser')->willReturn($user);
    $groups = $this->createStub(IGroupManager::class);
    $groups->method('isAdmin')->willReturn($admin);
    $manager = $this->createStub(IManager::class);
    $manager->method('getAllAllowedAdminSettings')->willReturn($delegated ? [$settings] : []);
    $remote = $this->createStub(IRemoteAddress::class);
    $remote->method('allowsAdminActions')->willReturn($ipAllowed);
    $container = $this->createStub(ContainerInterface::class);
    $container->method('get')->willReturnMap([
      [IUserSession::class, $session],
      [IGroupManager::class, $groups],
      [IManager::class, $manager],
      [IRemoteAddress::class, $remote],
    ]);
    $appManager = $this->createStub(IAppManager::class);
    $appManager->method('isEnabledForUser')->willReturn($appEnabled);
    $encryption = $this->createStub(EncryptionService::class);
    $encryption->method('appBound')->willReturn(false);
    $l10n = $this->createStub(IL10N::class);
    $l10n->method('t')->willReturnArgument(0);
    $service = new CloudUserConnectorService(
      $encryption,
      $appManager,
      $this->createStub(IConfig::class),
      $l10n,
      $this->createStub(RequestService::class),
      'cafevdb',
      $container,
      $this->createStub(LoggerInterface::class),
    );
    if (!$allowed) {
      $this->expectException(DatabaseCloudConnectorViewException::class);
    }
    $service->requireUserSqlSettingsAccess();
    if ($allowed) {
      self::assertTrue(true);
    }
  }
}
