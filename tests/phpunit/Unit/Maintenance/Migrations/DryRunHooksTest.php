<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations;

use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Maintenance\Migrations\Version19700101000002;
use OCA\CAFEVDB\Maintenance\Migrations\Version20260131090857;
use OCA\CAFEVDB\Maintenance\Migrations\Version20260207000624;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Schema\Schema;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[CoversClass(Version19700101000002::class)]
#[CoversClass(Version20260131090857::class)]
#[CoversClass(Version20260207000624::class)]
class DryRunHooksTest extends TestCase
{
  public function testRegistrationEventHookReturnsBeforeResolvingServicesOrChangingFilters(): void
  {
    $entityManager = $this->createMock(EntityManager::class);
    $entityManager->expects($this->never())->method('setFilterEnabled');
    $entityManager->expects($this->never())->method('getRepository');

    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->never())->method('get');

    $migration = new Version20260131090857($entityManager, $container, $this->createStub(IL10N::class));
    $migration->setDryRun(true);
    $migration->preUp(new Schema());
  }

  public function testInstrumentSeedHookReturnsBeforeChangingLocaleOrPersisting(): void
  {
    $entityManager = $this->createMock(EntityManager::class);
    $entityManager->expects($this->never())->method('setTranslatableL10n');
    $entityManager->expects($this->never())->method('persist');
    $entityManager->expects($this->never())->method('flush');

    $migration = new Version19700101000002(
      $entityManager,
      $this->createStub(ContainerInterface::class),
      $this->createStub(IL10N::class),
    );
    $migration->setDryRun(true);
    $migration->preUp(new Schema());
  }

  public function testTestOnlyRollbackDeleteIsSkippedDuringDryRun(): void
  {
    $entityManager = $this->createMock(EntityManager::class);
    $entityManager->expects($this->never())->method('createQueryBuilder');

    $migration = new Version20260207000624(
      $entityManager,
      $this->createStub(ContainerInterface::class),
      $this->createStub(IL10N::class),
    );
    $migration->setDryRun(true);
    $migration->postDown(new Schema());
  }
}
