<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OC\DB\Schema\Table;
use OCA\CAFEVDB\Migration\Version100202Date20261119091520;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version100202Date20261119091520::class)]
class BlogReplyColumnMigrationTest extends TestCase
{
  #[DataProvider('pendingSchemas')]
  public function testDoesNotQueryColumnsThatOnlyExistInTheTargetSchema(bool $liveTableExists): void
  {
    $liveSchema = new Schema();
    if ($liveTableExists) {
      $liveSchema->createTable('test_cafevdb_blog')->addColumn('inreplyto', 'integer');
    }

    $targetTable = (new Schema())->createTable('test_cafevdb_blog');
    $targetTable->addColumn('inreplyto', 'integer');
    $targetTable->addColumn('in_reply_to', 'bigint');
    $targetSchema = $this->createStub(ISchemaWrapper::class);
    $targetSchema->method('hasTable')->willReturn(true);
    $targetSchema->method('getTable')->willReturn(new Table($targetTable));

    $connection = $this->createMock(IDBConnection::class);
    $connection->method('createSchema')->willReturn($liveSchema);
    $connection->expects($this->never())->method('getQueryBuilder');
    $migration = new Version100202Date20261119091520($connection);

    $migration->preSchemaChange($this->createStub(IOutput::class), fn() => $targetSchema, ['tablePrefix' => 'test_']);
    self::assertSame(
      $liveTableExists ? null : $targetSchema,
      $migration->changeSchema($this->createStub(IOutput::class), fn() => $targetSchema, ['tablePrefix' => 'test_']),
    );
    self::assertSame($liveTableExists, $targetTable->hasColumn('inreplyto'));
    self::assertTrue($targetTable->hasColumn('in_reply_to'));
  }

  #[DataProvider('copyStatus')]
  public function testChecksCopiedRowsBeforeChangingTheSchema(bool $hasUncopiedRows): void
  {
    $liveTable = (new Schema())->createTable('test_cafevdb_blog');
    $liveTable->addColumn('inreplyto', 'integer');
    $liveTable->addColumn('in_reply_to', 'integer');
    $liveSchema = $this->createStub(ISchemaWrapper::class);
    $liveSchema->method('hasTable')->willReturn(true);
    $liveSchema->method('getTable')->willReturn(new Table($liveTable));

    $targetTable = (new Schema())->createTable('test_cafevdb_blog');
    $targetTable->addColumn('inreplyto', 'integer');
    $targetTable->addColumn('in_reply_to', 'integer');
    $targetSchema = $this->createStub(ISchemaWrapper::class);
    $targetSchema->method('hasTable')->willReturn(true);
    $targetSchema->method('getTable')->willReturn(new Table($targetTable));

    $result = $this->createMock(IResult::class);
    $result->method('fetchOne')->willReturn($hasUncopiedRows ? '1' : false);
    $result->expects($this->once())->method('closeCursor');

    $query = $this->createMock(IQueryBuilder::class);
    $query->expects($this->once())->method('select')->with('1')->willReturnSelf();
    $query->expects($this->once())->method('from')->with('cafevdb_blog')->willReturnSelf();
    $query->expects($this->once())->method('where')->with('COALESCE(in_reply_to, -1) <> inreplyto')->willReturnSelf();
    $query->expects($this->once())->method('setMaxResults')->with(1)->willReturnSelf();
    $query->expects($this->once())->method('executeQuery')->willReturn($result);

    $connection = $this->createMock(IDBConnection::class);
    $connection->method('createSchema')->willReturn($liveSchema);
    $queryWasBuilt = false;
    $connection->expects($this->once())->method('getQueryBuilder')->willReturnCallback(function () use (&$queryWasBuilt, $query) {
      $queryWasBuilt = true;
      return $query;
    });
    $migration = new Version100202Date20261119091520($connection);
    $output = $this->createStub(IOutput::class);

    $migration->preSchemaChange($output, fn() => $targetSchema, ['tablePrefix' => 'test_']);
    self::assertTrue($queryWasBuilt, 'Copied-row validation must happen before schema changes.');
    self::assertSame(
      $hasUncopiedRows ? null : $targetSchema,
      $migration->changeSchema($output, fn() => $targetSchema, ['tablePrefix' => 'test_']),
    );
    self::assertSame(!$hasUncopiedRows, $targetTable->hasColumn('inreplyto'));
  }

  public static function pendingSchemas(): array
  {
    return [
      'fresh install with no live blog table' => [false],
      'existing blog table with only the legacy column' => [true],
    ];
  }

  public static function copyStatus(): array
  {
    return [
      'retain legacy values that have not been copied' => [true],
      'drop legacy column after successful copy' => [false],
    ];
  }
}
