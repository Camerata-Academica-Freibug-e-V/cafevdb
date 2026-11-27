<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations;

use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Maintenance\Migrations\Version20261127120000;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Schema\Schema;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/** Test the preflight checks before modifying insurance identities. */
#[CoversClass(Version20261127120000::class)]
class InsuranceBrokerIdentityMigrationTest extends TestCase
{
  /** @return void */
  public function testUnresolvedBrokerStopsUpgrade(): void
  {
    $connection = $this->createStub(Connection::class);
    $connection->method('fetchAllAssociative')->willReturn([
      ['field_id' => 42, 'data' => '{"year":"2025","broker":"Missing"}'],
    ]);
    $connection->method('fetchOne')->willReturn(false);
    $entityManager = $this->createStub(EntityManager::class);
    $entityManager->method('getConnection')->willReturn($connection);
    $migration = new Version20261127120000(
      $entityManager,
      $this->createStub(ContainerInterface::class),
      $this->createStub(IL10N::class),
    );
    $migration->setDryRun(false);

    $this->expectException(UnexpectedValueException::class);
    $this->expectExceptionMessage('Unresolved insurance broker reference in field 42: Missing');
    $migration->preUp(new Schema());
  }

  /** @return void */
  public function testMalformedReceivableStopsUpgrade(): void
  {
    $connection = $this->createMock(Connection::class);
    $connection->method('fetchAllAssociative')->willReturn([
      ['field_id' => 42, 'data' => '{"year":"2025","brokerId":1}'],
    ]);
    $connection->expects($this->never())->method('fetchOne');
    $entityManager = $this->createStub(EntityManager::class);
    $entityManager->method('getConnection')->willReturn($connection);
    $migration = new Version20261127120000(
      $entityManager,
      $this->createStub(ContainerInterface::class),
      $this->createStub(IL10N::class),
    );
    $migration->setDryRun(false);

    $this->expectException(UnexpectedValueException::class);
    $this->expectExceptionMessage('Invalid insurance receivable identity in field 42');
    $migration->preUp(new Schema());
  }
}
