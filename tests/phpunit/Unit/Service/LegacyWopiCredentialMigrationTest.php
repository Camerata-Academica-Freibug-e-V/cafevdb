<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

use OC\Authentication\Token\IProvider;
use OCA\CAFEVDB\Migration\Version100201Date20261118215646;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Authentication\Token\IToken;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Version100201Date20261118215646::class)]
#[AllowMockObjectsWithoutExpectations]
class LegacyWopiCredentialMigrationTest extends TestCase
{
  private IDBConnection $db;
  private IProvider $provider;
  private IJobList $jobs;
  private ISchemaWrapper $schema;
  private IOutput $output;
  private Version100201Date20261118215646 $migration;

  protected function setUp(): void
  {
    $this->db = $this->createMock(IDBConnection::class);
    $this->provider = $this->createMock(IProvider::class);
    $this->jobs = $this->createMock(IJobList::class);
    $this->schema = $this->createMock(ISchemaWrapper::class);
    $this->output = $this->createStub(IOutput::class);
    $this->migration = new Version100201Date20261118215646($this->db, $this->provider, $this->jobs);
  }

  public function testRevokesEveryRegisteredCredentialBeforeDroppingTableAndRemovingJob(): void
  {
    $this->schema->expects($this->atLeastOnce())->method('hasTable')->with('cafevdb_wopi_credentials')->willReturn(true);
    $this->query([
      ['token_id' => '17', 'token_uid' => 'issued-token', 'expires' => 1],
      ['token_id' => '18', 'token_uid' => 'issued-token', 'expires' => PHP_INT_MAX],
    ]);
    $this->provider->expects($this->exactly(2))->method('getTokenByUser')->with('issued-token')
      ->willReturn([$this->token(16), $this->token(17), $this->token(18)]);
    $revoked = [];
    $this->provider->expects($this->exactly(2))->method('invalidateTokenById')
      ->willReturnCallback(function(string $uid, int $id) use (&$revoked) {
        self::assertSame('issued-token', $uid);
        $revoked[] = $id;
      });
    $dropped = false;
    $this->schema->expects($this->once())->method('dropTable')->with('cafevdb_wopi_credentials')
      ->willReturnCallback(function() use (&$dropped, &$revoked) {
        self::assertSame([17, 18], $revoked);
        $dropped = true;
        return $this->schema;
      });
    $this->jobs->expects($this->once())->method('remove')
      ->with('OCA\\CAFEVDB\\BackgroundJob\\CleanupWopiCredentials')
      ->willReturnCallback(function() use (&$dropped) {
        self::assertTrue($dropped);
      });
    $this->runMigration();
  }

  public function testMissingTableStillRemovesPersistedJob(): void
  {
    $this->schema->method('hasTable')->willReturn(false);
    $this->db->expects($this->never())->method('getQueryBuilder');
    $this->provider->expects($this->never())->method('getTokenByUser');
    $this->schema->expects($this->never())->method('dropTable');
    $this->jobs->expects($this->once())->method('remove')
      ->with('OCA\\CAFEVDB\\BackgroundJob\\CleanupWopiCredentials');
    $this->runMigration();
  }

  public function testEmptyRegistryCanBeDropped(): void
  {
    $this->schema->method('hasTable')->willReturn(true);
    $this->query([]);
    $this->provider->expects($this->never())->method('getTokenByUser');
    $this->schema->expects($this->once())->method('dropTable')->willReturnSelf();
    $this->runMigration();
  }

  public function testMissingCredentialsAndConcurrentRemovalDoNotBlockUpgrade(): void
  {
    $this->schema->method('hasTable')->willReturn(true);
    $this->query([
      ['token_id' => 17, 'token_uid' => 'issued-token'],
      ['token_id' => 18, 'token_uid' => 'issued-token'],
    ]);
    $this->provider->method('getTokenByUser')->willReturnOnConsecutiveCalls([], [$this->token(18)]);
    $this->provider->expects($this->once())->method('invalidateTokenById')->with('issued-token', 18)
      ->willThrowException(new DoesNotExistException('already removed'));
    $this->schema->expects($this->once())->method('dropTable')->willReturnSelf();
    $this->runMigration();
  }

  #[DataProvider('mismatchedIdentities')]
  public function testMismatchedIdentityPreservesCredentialAndRegistry(string $uid, string $name, int $type): void
  {
    $this->schema->method('hasTable')->willReturn(true);
    $this->query([['token_id' => 17, 'token_uid' => 'issued-token']]);
    $this->provider->method('getTokenByUser')->willReturn([$this->token(17, $uid, $name, $type)]);
    $this->provider->expects($this->never())->method('invalidateTokenById');
    $this->schema->expects($this->never())->method('dropTable');
    $this->jobs->expects($this->never())->method('remove');
    $this->expectException(RuntimeException::class);
    $this->runMigration();
  }

  public static function mismatchedIdentities(): array
  {
    return [
      'uid' => ['another-user', 'wopi_token_issued-token', IToken::TEMPORARY_TOKEN],
      'name' => ['issued-token', 'ordinary app password', IToken::TEMPORARY_TOKEN],
      'type' => ['issued-token', 'wopi_token_issued-token', IToken::PERMANENT_TOKEN],
    ];
  }

  public function testFailedRevocationIsSanitizedAndCanBeRetriedAfterPartialProgress(): void
  {
    $this->schema->method('hasTable')->willReturn(true);
    $this->query([
      ['token_id' => 17, 'token_uid' => 'issued-token'],
      ['token_id' => 18, 'token_uid' => 'issued-token'],
    ]);
    $this->provider->method('getTokenByUser')->willReturnOnConsecutiveCalls(
      [$this->token(17)],
      [$this->token(18)],
      [],
      [$this->token(18)],
    );
    $attempts = [];
    $this->provider->expects($this->exactly(3))->method('invalidateTokenById')
      ->willReturnCallback(function(string $uid, int $id) use (&$attempts) {
        $attempts[] = $id;
        if (count($attempts) === 2) {
          throw new RuntimeException('secret-password');
        }
      });
    $this->schema->expects($this->once())->method('dropTable')
      ->willReturnCallback(function() use (&$attempts) {
        self::assertSame([17, 18, 18], $attempts);
        return $this->schema;
      });
    $this->jobs->expects($this->once())->method('remove');
    try {
      $this->runMigration();
      self::fail('Revocation failure was swallowed.');
    } catch (RuntimeException $exception) {
      self::assertSame('Legacy WOPI credential migration failed: ' . RuntimeException::class, $exception->getMessage());
      self::assertNull($exception->getPrevious());
    }
    $this->runMigration();
  }

  public function testProviderLookupFailureDoesNotDropRegistryOrLeakCredentials(): void
  {
    $this->schema->method('hasTable')->willReturn(true);
    $this->query([['token_id' => 17, 'token_uid' => 'issued-token']]);
    $this->provider->method('getTokenByUser')->willThrowException(new RuntimeException('secret-token'));
    $this->provider->expects($this->never())->method('invalidateTokenById');
    $this->schema->expects($this->never())->method('dropTable');
    $this->jobs->expects($this->never())->method('remove');
    try {
      $this->runMigration();
      self::fail('Provider failure was swallowed.');
    } catch (RuntimeException $exception) {
      self::assertSame('Legacy WOPI credential migration failed: ' . RuntimeException::class, $exception->getMessage());
      self::assertNull($exception->getPrevious());
    }
  }

  public function testScansBeyondOneBatchWithoutOffsetOrExpiryFiltering(): void
  {
    $this->schema->method('hasTable')->willReturn(true);
    $entries = array_map(fn(int $id) => ['token_id' => $id, 'token_uid' => 'issued-token'], range(1, 1000));
    $query = $this->query($entries, [['token_id' => 1001, 'token_uid' => 'issued-token']]);
    $lastIds = [];
    $query->method('createNamedParameter')->willReturnCallback(function(int $lastId, $type) use (&$lastIds) {
      self::assertSame(IQueryBuilder::PARAM_INT, $type);
      $lastIds[] = $lastId;
      return ':lastId';
    });
    $this->provider->expects($this->exactly(1001))->method('getTokenByUser')->willReturn([]);
    $this->provider->expects($this->never())->method('invalidateTokenById');
    $this->runMigration();
    self::assertSame([0, 1000], $lastIds);
  }

  private function query(array ...$batches): IQueryBuilder
  {
    $query = $this->createMock(IQueryBuilder::class);
    $query->expects($this->atLeastOnce())->method('select')->with('token_id', 'token_uid')->willReturnSelf();
    $query->expects($this->atLeastOnce())->method('from')->with('cafevdb_wopi_credentials')->willReturnSelf();
    $query->method('where')->willReturnSelf();
    $query->expects($this->atLeastOnce())->method('orderBy')->with('token_id')->willReturnSelf();
    $query->expects($this->atLeastOnce())->method('setMaxResults')->with(1000)->willReturnSelf();
    $query->expects($this->never())->method('setFirstResult');
    $query->expects($this->never())->method('andWhere');
    $query->method('expr')->willReturn($this->createStub(IExpressionBuilder::class));
    $results = [];
    foreach ($batches as $entries) {
      $result = $this->createMock(IResult::class);
      $result->method('fetchAllAssociative')->willReturn($entries);
      $result->expects($this->atLeastOnce())->method('closeCursor');
      $results[] = $result;
    }
    $query->method('executeQuery')->willReturn(...$results);
    $this->db->method('getQueryBuilder')->willReturn($query);
    return $query;
  }

  private function token(
    int $id,
    string $uid = 'issued-token',
    string $name = 'wopi_token_issued-token',
    int $type = IToken::TEMPORARY_TOKEN,
  ): IToken {
    $token = $this->createStub(IToken::class);
    $token->method('getId')->willReturn($id);
    $token->method('getUID')->willReturn($uid);
    $token->method('getName')->willReturn($name);
    $token->method('getType')->willReturn($type);
    return $token;
  }

  private function runMigration(): void
  {
    $closure = fn() => $this->schema;
    $this->migration->preSchemaChange($this->output, $closure, []);
    $this->migration->changeSchema($this->output, $closure, []);
    $this->migration->postSchemaChange($this->output, $closure, []);
  }
}
