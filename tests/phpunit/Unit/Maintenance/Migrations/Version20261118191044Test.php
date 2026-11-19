<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations;

use OCA\CAFEVDB\Common\ConsoleLogger;
use OCA\CAFEVDB\Database\Doctrine\Migrations\AbstractMigration;
use OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\DoctrineMigrationsListener;
use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Maintenance\Migrations\Version20261118191044;
use OCA\CAFEVDB\Service\CloudUserConnectorService;
use OCA\CAFEVDB\Service\EncryptionService;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Schema\Schema;
use OCA\CAFEVDB\Wrapped\Doctrine\Migrations\Event\MigrationsVersionEventArgs;
use OCA\CAFEVDB\Wrapped\Doctrine\Migrations\Metadata\MigrationPlan;
use OCA\CAFEVDB\Wrapped\Doctrine\Migrations\MigratorConfiguration;
use OCA\CAFEVDB\Wrapped\Doctrine\Migrations\Version\Version;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[CoversClass(Version20261118191044::class)]
#[UsesClass(AbstractMigration::class)]
#[UsesClass(DoctrineMigrationsListener::class)]
class Version20261118191044Test extends TestCase
{
  public function testDryRunDoesNotCreateOrRemoveConnectorObjects(): void
  {
    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->never())->method('get');
    $migration = $this->migration($container);

    $this->startMigration($migration, 'up', dryRun: true);
    $migration->postUp(new Schema());
    $this->startMigration($migration, 'down', dryRun: true);
    $migration->preDown(new Schema());
  }

  #[DataProvider('connectorSettings')]
  public function testProvisioningFollowsExistingConnectorSettings(string $cloudUsers, string $personalized, ?string $database): void
  {
    $config = $this->createStub(EncryptionService::class);
    $config->method('getConfigValue')->willReturnMap([
      ['importClubMembersAsCloudUsers', 'off', false, $cloudUsers],
      ['musicianPersonalizedViews', 'off', false, $personalized],
      ['cloudUserViewsDatabase', null, false, $database],
    ]);

    $connector = $this->createMock(CloudUserConnectorService::class);
    if ($cloudUsers === 'on' || $personalized === 'on') {
      $connector->expects($this->once())->method('updateWopiCredentialsView')->with($database);
    } else {
      $connector->expects($this->never())->method('updateWopiCredentialsView');
    }

    $container = $this->createStub(ContainerInterface::class);
    $container->method('get')->willReturnMap([
      [EncryptionService::class, $config],
      [CloudUserConnectorService::class, $connector],
    ]);
    $migration = $this->migration($container);

    $this->startMigration($migration, 'up', dryRun: false);
    $migration->postUp(new Schema());
  }

  public static function connectorSettings(): array
  {
    return [
      'cloud-user views in connector database' => ['on', 'off', 'connector'],
      'personalized views in default database' => ['off', 'on', null],
      'neither enabled' => ['off', 'off', 'connector'],
    ];
  }

  public function testRollbackRemovesViewFromTheRecordedDatabase(): void
  {
    $config = $this->createStub(IConfig::class);
    $config->method('getAppValue')->willReturnMap([
      ['cafevdb', 'wopiConnectorDatabase', '', 'previous_connector'],
    ]);
    $connector = $this->createMock(CloudUserConnectorService::class);
    $connector->expects($this->once())->method('removeWopiCredentialsView')->with('previous_connector');
    $container = $this->createStub(ContainerInterface::class);
    $container->method('get')->willReturnMap([
      [IConfig::class, $config],
      ['AppName', 'cafevdb'],
      [CloudUserConnectorService::class, $connector],
    ]);
    $migration = $this->migration($container);

    $this->startMigration($migration, 'down', dryRun: false);
    $migration->preDown(new Schema());
  }

  private function migration(ContainerInterface $container): Version20261118191044
  {
    return new Version20261118191044(
      $this->createStub(EntityManager::class),
      $container,
      $this->createStub(IL10N::class),
    );
  }

  private function startMigration(Version20261118191044 $migration, string $direction, bool $dryRun): void
  {
    $listener = new DoctrineMigrationsListener($this->createStub(ConsoleLogger::class), false);
    $listener->onMigrationsVersionExecuting(new MigrationsVersionEventArgs(
      $this->createStub(Connection::class),
      new MigrationPlan(new Version($migration::class), $migration, $direction),
      new MigratorConfiguration()->setDryRun($dryRun),
    ));
  }
}
