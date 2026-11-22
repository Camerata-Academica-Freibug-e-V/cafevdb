<?php
/**
 * CAFEVDB -- Orchestra management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute as CoreAttributes;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use OCA\CAFEVDB\Attributes\NoGroupMemberRequired;
use OCA\CAFEVDB\Controller\CloudGroupsController;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Settings\ConfigConstants;

#[CoversClass(CloudGroupsController::class)]
final class CloudGroupsControllerTest extends TestCase
{
  private function group(string $id, string $displayName): IGroup
  {
    $group = $this->createStub(IGroup::class);
    $group->method('getGID')->willReturn($id);
    $group->method('getDisplayName')->willReturn($displayName);
    $group->method('count')->willReturn(3);
    $group->method('countDisabled')->willReturn(1);
    $group->method('canAddUser')->willReturn(true);
    $group->method('canRemoveUser')->willReturn(false);
    $group->method('getBackendNames')->willReturn(['Database', 'LDAP']);
    return $group;
  }

  private function controller(IGroupManager $groups, ConfigService $config, ?IUser $user): CloudGroupsController
  {
    $session = $this->createStub(IUserSession::class);
    $session->method('getUser')->willReturn($user);
    $cloudConfig = $this->createStub(IConfig::class);
    $cloudConfig->method('getAppValue')
      ->with('cafevdb', ConfigConstants::USER_GROUP_KEY)
      ->willReturn('orchestra');
    return new CloudGroupsController('cafevdb', $this->createStub(IRequest::class), $groups, $session, $cloudConfig, $config);
  }

  private function user(): IUser
  {
    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('alice');
    return $user;
  }

  public function testRoutePermitsSubadminsButRequiresExplicitAuthorization(): void
  {
    $method = new ReflectionMethod(CloudGroupsController::class, 'getDetails');
    self::assertNotEmpty($method->getAttributes(CoreAttributes\NoAdminRequired::class));
    self::assertNotEmpty($method->getAttributes(NoGroupMemberRequired::class));
    self::assertSame(
      ['verb' => 'GET', 'url' => '/settings/cloud-groups'],
      $method->getAttributes(CoreAttributes\FrontpageRoute::class)[0]->getArguments(),
    );

    $groups = $this->createMock(IGroupManager::class);
    $groups->method('isAdmin')->with('alice')->willReturn(false);
    $groups->expects(self::never())->method('search');
    $groups->expects(self::never())->method('get');
    $config = $this->createStub(ConfigService::class);
    $config->method('isSubAdminOfGroup')->willReturn(false);
    self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($groups, $config, $this->user())->getDetails()->getStatus());
    self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($groups, $config, null)->getDetails()->getStatus());
  }

  public function testAdminSearchReturnsBackendDetails(): void
  {
    $groups = $this->createMock(IGroupManager::class);
    $groups->method('isAdmin')->with('alice')->willReturn(true);
    $groups->expects(self::once())->method('search')->with('other & team', 10, 20)
      ->willReturn([$this->group('other', 'Other team')]);
    $config = $this->createMock(ConfigService::class);
    $config->expects(self::never())->method('isSubAdminOfGroup');
    $response = $this->controller($groups, $config, $this->user())->getDetails('other & team', 10, 20);
    self::assertSame(['groups' => [[
      'id' => 'other',
      'displayname' => 'Other team',
      'usercount' => 3,
      'disabled' => 1,
      'canAdd' => true,
      'canRemove' => false,
      'backends' => ['Database', 'LDAP'],
    ]]], $response->getData());
  }

  public function testSubadminSearchIsRestrictedAndPagedAfterFiltering(): void
  {
    $known = [
      'orchestra' => $this->group('orchestra', 'Orchestra'),
      'orchestra-frontend' => $this->group('orchestra-frontend', 'Team front'),
      'orchestra-finance' => $this->group('orchestra-finance', 'Team finance'),
    ];
    $groups = $this->createMock(IGroupManager::class);
    $groups->method('isAdmin')->willReturn(false);
    $groups->expects(self::never())->method('search');
    $groups->method('get')->willReturnCallback(fn(string $gid) => $known[$gid] ?? null);
    $config = $this->createStub(ConfigService::class);
    $config->method('isSubAdminOfGroup')->willReturn(true);
    $controller = $this->controller($groups, $config, $this->user());
    $result = $controller->getDetails('TEAM', 1, 1)->getData()['groups'];
    self::assertCount(1, $result);
    self::assertSame('orchestra-finance', $result[0]['id']);
    self::assertSame(['Database', 'LDAP'], $result[0]['backends']);
    self::assertSame([], $controller->getDetails('other')->getData()['groups']);
    self::assertSame(Http::STATUS_BAD_REQUEST, $controller->getDetails('', 0)->getStatus());
    self::assertSame(Http::STATUS_BAD_REQUEST, $controller->getDetails('', 10, -1)->getStatus());
  }
}
