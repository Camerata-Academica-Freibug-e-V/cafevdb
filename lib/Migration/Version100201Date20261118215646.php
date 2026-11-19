<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Migration;

use Closure;
use OC\Authentication\Token\IProvider;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Authentication\Token\IToken;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;
use RuntimeException;
use Throwable;

/** Revoke registered legacy password credentials before removing their registry. */
class Version100201Date20261118215646 extends SimpleMigrationStep
{
  private const BATCH_SIZE = 1000;
  private const LEGACY_JOB = 'OCA\\CAFEVDB\\BackgroundJob\\CleanupWopiCredentials';

  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    private IDBConnection $db,
    private IProvider $tokenProvider,
    private IJobList $jobList,
  ) {
  }
  // phpcs:enable

  /** {@inheritdoc} */
  #[Override]
  public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
  {
    try {
      if (!$schemaClosure()->hasTable(Version100201Date20261114001000::TABLE_NAME)) {
        return;
      }
      $lastId = 0;
      do {
        $query = $this->db->getQueryBuilder();
        $query->select('token_id', 'token_uid')
          ->from(Version100201Date20261114001000::TABLE_NAME)
          ->where($query->expr()->gt('token_id', $query->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
          ->orderBy('token_id')
          ->setMaxResults(self::BATCH_SIZE);
        $result = $query->executeQuery();
        try {
          $entries = $result->fetchAllAssociative();
        } finally {
          $result->closeCursor();
        }
        foreach ($entries as $entry) {
          $lastId = (int)$entry['token_id'];
          $this->revokeCredential($lastId, $entry['token_uid']);
        }
        $hasMore = count($entries) === self::BATCH_SIZE;
      } while ($hasMore);
    } catch (Throwable $exception) {
      // Provider exceptions may contain tokens or passwords; do not chain them.
      throw new RuntimeException('Legacy WOPI credential migration failed: ' . $exception::class);
    }
  }

  /** {@inheritdoc} */
  #[Override]
  public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
  {
    $schema = $schemaClosure();
    if (!$schema->hasTable(Version100201Date20261114001000::TABLE_NAME)) {
      return null;
    }
    $schema->dropTable(Version100201Date20261114001000::TABLE_NAME);
    return $schema;
  }

  /** {@inheritdoc} */
  #[Override]
  public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
  {
    $this->jobList->remove(self::LEGACY_JOB);
  }

  /**
   * @param integer $tokenId Registered Nextcloud authentication token id.
   *
   * @param string $tokenUid Synthetic uid containing the WOPI access token.
   *
   * @return void
   */
  private function revokeCredential(int $tokenId, string $tokenUid): void
  {
    // getTokenById() excludes expired credentials, which must also be revoked.
    foreach ($this->tokenProvider->getTokenByUser($tokenUid) as $token) {
      if ($token->getId() !== $tokenId) {
        continue;
      }
      if ($token->getUID() !== $tokenUid || $token->getType() !== IToken::TEMPORARY_TOKEN
          || $token->getName() !== 'wopi_token_' . $tokenUid) {
        throw new RuntimeException('Registered WOPI credential identity mismatch.');
      }
      try {
        $this->tokenProvider->invalidateTokenById($tokenUid, $tokenId);
      } catch (DoesNotExistException) {
        // Another cleanup already removed the credential.
      }
      return;
    }
  }
}
