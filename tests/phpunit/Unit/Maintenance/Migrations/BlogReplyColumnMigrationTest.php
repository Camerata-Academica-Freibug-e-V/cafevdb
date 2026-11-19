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
use OCP\DB\ISchemaWrapper;
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

    self::assertSame(
      $liveTableExists ? null : $targetSchema,
      $migration->changeSchema($this->createStub(IOutput::class), fn() => $targetSchema, ['tablePrefix' => 'test_']),
    );
    self::assertSame($liveTableExists, $targetTable->hasColumn('inreplyto'));
    self::assertTrue($targetTable->hasColumn('in_reply_to'));
  }

  public static function pendingSchemas(): array
  {
    return [
      'fresh install with no live blog table' => [false],
      'existing blog table with only the legacy column' => [true],
    ];
  }
}
