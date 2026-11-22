<?php
/**
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use OCA\CAFEVDB\Crypto\AsymmetricKeyService;
use OCA\CAFEVDB\Crypto\AsymmetricCryptorInterface;
use OCA\CAFEVDB\Crypto\CryptoFactoryInterface;
use OCA\CAFEVDB\Crypto\SymmetricCryptorInterface;
use OCA\CAFEVDB\Events\EncryptionServiceBound;
use OCA\CAFEVDB\Exceptions\EncryptionKeyException;
use OCA\CAFEVDB\Service\AuthorizationService;
use OCA\CAFEVDB\Service\EncryptionService;
use OCA\CAFEVDB\Service\WopiRequestService;
use OCA\CAFEVDB\Settings\ConfigConstants;
use OCP\Authentication\Exceptions\CredentialsUnavailableException;
use OCP\Authentication\LoginCredentials\IStore as ICredentialsStore;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\IHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

#[CoversClass(EncryptionService::class)]
#[UsesClass(EncryptionServiceBound::class)]
class EncryptionServiceTest extends TestCase
{
  public function testConnectorDescriptorPublishesEffectiveDatabaseWithoutSecrets(): void
  {
    $values = ['cloudUserViewsDatabase' => '', ConfigConstants::APP_DB_NAME => 'orchestra', 'musicianPersonalizedViews' => 'on'];
    $config = $this->createStub(IConfig::class);
    $config->method('getAppValue')->willReturn('{"user":"connector","accountHost":"%","password":"not-exported"}');
    $published = [];
    $config->method('setAppValue')->willReturnCallback(
      static function(string $app, string $key, string $value) use (&$published): void {
        $published[] = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
      },
    );
    $service = $this->getMockBuilder(EncryptionService::class)
      ->disableOriginalConstructor()->onlyMethods(['getConfigValue'])->getMock();
    $service->expects($this->atLeastOnce())->method('getConfigValue')->willReturnCallback(
      static function(string $key) use (&$values) {
        return $values[$key];
      },
    );
    (new ReflectionProperty(EncryptionService::class, 'cloudConfig'))->setValue($service, $config);
    (new ReflectionProperty(EncryptionService::class, 'appName'))->setValue($service, 'cafevdb');
    $service->publishConnectorDescriptor();
    self::assertSame('orchestra', $published[0]['database']);
    self::assertSame(['user' => 'connector', 'accountHost' => '%'], $published[0]['account']);
    self::assertTrue($published[0]['personalizedViewsEnabled']);
    $values['cloudUserViewsDatabase'] = 'views';
    $service->publishConnectorDescriptor();
    self::assertSame('views', $published[1]['database']);
    self::assertNotSame($published[0]['revision'], $published[1]['revision']);
    $service->publishConnectorDescriptor();
    self::assertSame($published[1]['revision'], $published[2]['revision']);
  }

  public function testDatabaseSettingWriteRefreshesConnectorDescriptor(): void
  {
    $config = $this->createStub(IConfig::class);
    $config->method('getAppValue')->willReturnArgument(2);
    $cryptor = $this->createStub(SymmetricCryptorInterface::class);
    $cryptor->method('getEncryptionKey')->willReturn('');
    $service = $this->getMockBuilder(EncryptionService::class)
      ->disableOriginalConstructor()->onlyMethods(['publishConnectorDescriptor'])->getMock();
    $service->expects($this->once())->method('publishConnectorDescriptor');
    (new ReflectionProperty(EncryptionService::class, 'cloudConfig'))->setValue($service, $config);
    (new ReflectionProperty(EncryptionService::class, 'appName'))->setValue($service, 'cafevdb');
    (new ReflectionProperty(EncryptionService::class, 'appCryptor'))->setValue($service, $cryptor);
    self::assertTrue($service->setConfigValue(ConfigConstants::APP_DB_NAME, 'updated'));
  }

  public function testConnectorMetadataRemainsUnencryptedDuringKeyRotation(): void
  {
    $config = $this->createMock(IConfig::class);
    $config->method('getAppValue')->willReturnArgument(2);
    $config->expects($this->once())->method('setAppValue')
      ->with('cafevdb', ConfigConstants::CONNECTOR_DESCRIPTOR_KEY, '{"version":1}');
    $cryptor = $this->createMock(SymmetricCryptorInterface::class);
    $cryptor->expects($this->never())->method('encrypt');
    $service = $this->getMockBuilder(EncryptionService::class)
      ->disableOriginalConstructor()->onlyMethods(['publishConnectorDescriptor'])->getMock();
    $service->expects($this->never())->method('publishConnectorDescriptor');
    (new ReflectionProperty(EncryptionService::class, 'cloudConfig'))->setValue($service, $config);
    (new ReflectionProperty(EncryptionService::class, 'appName'))->setValue($service, 'cafevdb');
    (new ReflectionProperty(EncryptionService::class, 'appCryptor'))->setValue($service, $cryptor);
    self::assertTrue($service->setConfigValue(ConfigConstants::CONNECTOR_DESCRIPTOR_KEY, '{"version":1}', ignoreLock: true));
  }

  public function testWopiBindingUnlocksOnlyAppCredentials(): void
  {
    $credentials = $this->createMock(ICredentialsStore::class);
    $credentials->expects($this->never())->method('getLoginCredentials');
    $wopiRequest = $this->createStub(WopiRequestService::class);
    $wopiRequest->method('isWopiRoute')->willReturn(true);
    $config = $this->createStub(IConfig::class);
    $config->method('getAppValue')->willReturnCallback(static fn($app, $key, $default = '') => match ($key) {
      ConfigConstants::APP_ENCRYPTION_KEY_HASH_KEY => 'stored-hash',
      ConfigConstants::USER_GROUP_KEY => 'orchestra',
      default => $default,
    });
    $hasher = $this->createMock(IHasher::class);
    $hasher->expects($this->once())->method('verify')->with('app-key', 'stored-hash')->willReturn(true);
    $appCryptor = $this->createStub(AsymmetricCryptorInterface::class);
    $appCryptor->method('canEncrypt')->willReturn(true);
    $appCryptor->method('canDecrypt')->willReturn(true);
    $asymmetric = $this->createMock(AsymmetricKeyService::class);
    $asymmetric->expects($this->once())->method('getUnlockedCryptor')->with('@orchestra', 'app-key')->willReturn($appCryptor);
    $asymmetric->expects($this->never())->method('initEncryptionKeyPair');
    $asymmetric->expects($this->never())->method('getSharedPrivateValue');
    $symmetric = $this->createMock(SymmetricCryptorInterface::class);
    $symmetric->expects($this->once())->method('setEncryptionKey')->with('app-key');
    $symmetric->method('getEncryptionKey')->willReturn('app-key');
    $factory = $this->createStub(CryptoFactoryInterface::class);
    $factory->method('getSymmetricCryptor')->willReturn($symmetric);
    $events = $this->createMock(IEventDispatcher::class);
    $events->expects($this->once())->method('dispatchTyped')->with(self::callback(
      static fn($event) => $event instanceof EncryptionServiceBound && $event->getUserId() === 'alice',
    ));
    $encryption = new EncryptionService(
      'cafevdb',
      $asymmetric,
      $config,
      $events,
      $hasher,
      $this->createStub(LoggerInterface::class),
      $this->createStub(IUserConfig::class),
      $this->createStub(AuthorizationService::class),
      $this->createStub(IUserSession::class),
      $factory,
      $credentials,
      $wopiRequest,
    );

    $encryption->bindWithAppKey('alice', 'app-key');
    self::assertTrue($encryption->appBound());
    self::assertFalse($encryption->bound());
    self::assertSame('alice', $encryption->getUserId());
    self::assertSame($appCryptor, $encryption->getAppAsymmetricCryptor());
    $this->expectException(EncryptionKeyException::class);
    $encryption->getSharedPrivateValue('rowAccessToken');
  }

  public function testUnavailableLoginCredentialsAreLoggedAtDebugLevel(): void
  {
    $user = $this->createStub(IUser::class);
    $user->method('getUID')->willReturn('alice');
    $userSession = $this->createStub(IUserSession::class);
    $userSession->method('getUser')->willReturn($user);

    $authorization = $this->createStub(AuthorizationService::class);
    $authorization->method('getUserPermissions')->willReturn(AuthorizationService::PERMISSION_FILESYSTEM);

    $credentialsStore = $this->createMock(ICredentialsStore::class);
    $credentialsStore->expects($this->once())->method('getLoginCredentials')
      ->willThrowException(new CredentialsUnavailableException());

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('log')->with(
      ILogger::DEBUG,
      self::callback(fn(string $message): bool => str_ends_with(
        $message,
        'Login credentials unavailable for "alice".',
      )),
    );

    $cryptoFactory = $this->createStub(CryptoFactoryInterface::class);
    $cryptoFactory->method('getSymmetricCryptor')->willReturn($this->createStub(SymmetricCryptorInterface::class));

    new EncryptionService(
      'cafevdb',
      $this->createStub(AsymmetricKeyService::class),
      $this->createStub(IConfig::class),
      $this->createStub(IEventDispatcher::class),
      $this->createStub(IHasher::class),
      $logger,
      $this->createStub(IUserConfig::class),
      $authorization,
      $userSession,
      $cryptoFactory,
      $credentialsStore,
      $this->createStub(WopiRequestService::class),
    );
  }
}
