<?php
/**
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use OCA\CAFEVDB\Crypto\AsymmetricKeyService;
use OCA\CAFEVDB\Crypto\CryptoFactoryInterface;
use OCA\CAFEVDB\Crypto\SymmetricCryptorInterface;
use OCA\CAFEVDB\Service\AuthorizationService;
use OCA\CAFEVDB\Service\EncryptionService;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(EncryptionService::class)]
class EncryptionServiceTest extends TestCase
{
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
    );
  }
}
