<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Service;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Platforms\SQLitePlatform;

/** Explicit metadata backfill; never changes encrypted data or deletes source entries. */
class ParticipantFileRepairService
{
  /**
   * @param Connection $connection
   * @param int $clubMembersProjectId
   * @param Closure $destination Returns the folder components for a business reference.
   * @param Closure $createFileEntry Creates or moves a file entry through the ORM and returns its ID.
   */
  public function __construct(
    private Connection $connection,
    private int $clubMembersProjectId,
    private Closure $destination,
    private Closure $createFileEntry,
  ) {
  }

  /**
   * Lock the audit inputs before classification and retain the snapshot for all
   * references. Re-auditing midway would hide still-disconnected field references
   * after another reference to the same file gained a participant link.
   *
   * @param bool $dryRun
   *
   * @return array
   */
  public function repair(bool $dryRun = false): array
  {
    if ($this->connection->isTransactionActive()) {
      throw new RuntimeException('Participant file repair requires its own transaction.');
    }
    $this->connection->beginTransaction();
    try {
      if (!($this->connection->getDatabasePlatform() instanceof SQLitePlatform)) {
        foreach ([
          'Musicians', 'Projects', 'Files', 'DatabaseStorages', 'ProjectParticipants',
          'DatabaseStorageDirEntries', 'ProjectParticipantFields', 'ProjectParticipantFieldsData',
          'ProjectInstruments', 'instrument_instrument_family', 'InstrumentFamilies',
          'SepaDebitMandates', 'Invoices', 'CompositePayments', 'DonationReceipts', 'EncryptedFileOwners',
        ] as $table) {
          $this->connection->executeQuery('SELECT * FROM ' . $table . ' FOR UPDATE')->fetchAllAssociative();
        }
      }
      $audit = new ParticipantFileAuditService($this->connection, $this->clubMembersProjectId);
      $before = $audit->audit();
      $movableEntries = $this->movableEntries($before['findings'], $audit->documentReferences());
      $results = [];
      $seen = [];
      $destinations = [];
      foreach ($before['findings'] as $finding) {
        if (!$finding['repairable']) {
          continue;
        }
        foreach ($finding['repairsteps'] as $step) {
          if (in_array($step, ['reconcile-encryption-seal', 'add-ownership'], true)) {
            $key = $step . ':' . $finding['file_id'] . ':' . $finding['musician_id'];
          } elseif ($step === 'add-participant-link') {
            $key = implode(':', [
              $step, $finding['source'], $finding['source_id'], $finding['musician_id'],
              $finding['project_id'], $finding['entry_id'], $finding['option_key'] ?? '',
            ]);
          } else {
            throw new RuntimeException('Unsupported participant file repair step: ' . $step);
          }
          if (isset($seen[$key])) {
            continue;
          }
          $seen[$key] = true;
          $result = [
            'step' => $step,
            'file_id' => $finding['file_id'],
            'musician_id' => $finding['musician_id'],
            'project_id' => $finding['project_id'],
          ];
          if ($step === 'reconcile-encryption-seal') {
            $result += ['status' => 'deferred', 'reason' => 'seal-reconciliation-deferred'];
          } elseif ($step === 'add-ownership') {
            $exists = $this->connection->fetchOne(
              'SELECT 1 FROM EncryptedFileOwners WHERE encrypted_file_id = ? AND musician_id = ?',
              [$finding['file_id'], $finding['musician_id']],
            );
            if (!$exists && !$dryRun) {
              $this->connection->insert('EncryptedFileOwners', [
                'encrypted_file_id' => $finding['file_id'],
                'musician_id' => $finding['musician_id'],
              ]);
            }
            $result['status'] = $exists ? 'unchanged' : ($dryRun ? 'planned' : 'applied');
          } else {
            $result += [
              'source' => $finding['source'],
              'source_id' => $finding['source_id'],
              'entry_id' => $finding['entry_id'],
              'option_key' => $finding['option_key'] ?? null,
            ];
            $result += $this->addLink($finding, $dryRun, $destinations, $movableEntries);
          }
          $results[] = $result;
        }
      }
      $remaining = $dryRun ? $before : $audit->audit();
      if ($dryRun) {
        $this->connection->rollBack();
      } else {
        $this->connection->commit();
      }
      return [
        'dry_run' => $dryRun,
        'summary' => array_count_values(array_column($results, 'status')),
        'repairs' => $results,
        'remaining' => $remaining,
      ];
    } catch (Throwable $exception) {
      if ($this->connection->isTransactionActive()) {
        $this->connection->rollBack();
      }
      throw $exception;
    }
  }

  /**
   * Only move an orphan when every reference is covered by the same destination.
   *
   * @param array $findings
   * @param array $references
   *
   * @return array
   */
  private function movableEntries(array $findings, array $references): array
  {
    $targets = [];
    $covered = [];
    $participantRoots = [];
    foreach ($this->connection->fetchAllAssociative(
      'SELECT p.musician_id, p.project_id, s.root_id FROM ProjectParticipants p
       JOIN DatabaseStorages s ON s.id = p.database_documents_id',
    ) as $participant) {
      $participantRoots[$participant['musician_id'] . ':' . $participant['project_id']] = (int)$participant['root_id'];
    }
    foreach ($findings as $finding) {
      if (!$finding['repairable'] || !in_array('add-participant-link', $finding['repairsteps'], true)) {
        continue;
      }
      $entryId = (int)$finding['entry_id'];
      $key = $this->referenceKey($finding);
      $covered[$entryId][$key] = true;
      $targets[$entryId][json_encode([
        $participantRoots[$finding['musician_id'] . ':' . $finding['project_id']] ?? null,
        ($this->destination)($finding),
      ], JSON_THROW_ON_ERROR)] = true;
    }
    foreach ($references as $reference) {
      $entryId = (int)$reference['entry_id'];
      if (isset($targets[$entryId]) && !isset($covered[$entryId][$this->referenceKey($reference)])) {
        unset($targets[$entryId]);
      }
    }
    $entries = array_column(
      $this->connection->fetchAllAssociative('SELECT id, parent_id, type FROM DatabaseStorageDirEntries'),
      null,
      'id',
    );
    $roots = array_fill_keys($this->connection->fetchFirstColumn('SELECT root_id FROM DatabaseStorages'), true);
    $movable = [];
    foreach ($targets as $entryId => $paths) {
      if (count($paths) !== 1 || !isset($entries[$entryId])) {
        continue;
      }
      $current = $entries[$entryId];
      $visited = [];
      $attached = false;
      while ($current['parent_id'] !== null && !isset($visited[$current['id']])) {
        if (isset($roots[$current['id']])) {
          $attached = true;
          break;
        }
        $visited[$current['id']] = true;
        $parent = $entries[$current['parent_id']] ?? null;
        if ($parent === null || $parent['type'] !== 'folder') {
          break;
        }
        $current = $parent;
      }
      if (!$attached && $current['parent_id'] === null && !isset($roots[$current['id']])) {
        $movable[$entryId] = true;
      }
    }
    return $movable;
  }

  /**
   * @param array $reference
   *
   * @return string
   */
  private function referenceKey(array $reference): string
  {
    return implode(':', [
      $reference['source'], $reference['source_id'], $reference['musician_id'],
      $reference['project_id'], $reference['option_key'] ?? '',
    ]);
  }

  /**
   * @param array $finding
   * @param bool $dryRun
   * @param array $destinations
   * @param array $movableEntries
   *
   * @return array
   */
  private function addLink(array $finding, bool $dryRun, array &$destinations, array $movableEntries): array
  {
    $source = $this->connection->fetchAssociative(
      'SELECT name, file_id FROM DatabaseStorageDirEntries WHERE id = ?',
      [$finding['entry_id']],
    );
    if (!$source || (int)$source['file_id'] !== (int)$finding['file_id']) {
      throw new RuntimeException('The source file entry changed during participant repair.');
    }
    $root = $this->connection->fetchOne(
      'SELECT s.root_id FROM ProjectParticipants p JOIN DatabaseStorages s ON s.id = p.database_documents_id
       WHERE p.musician_id = ? AND p.project_id = ?',
      [$finding['musician_id'], $finding['project_id']],
    );
    if (!$root) {
      throw new RuntimeException('The participant storage disappeared during repair.');
    }
    $components = ($this->destination)($finding);
    $components[] = $source['name'];
    foreach ($components as $component) {
      if (!is_string($component) || $component === '' || in_array($component, ['.', '..'], true)
          || preg_match('/[\/\\\\\x00-\x1f]/', $component) || mb_strlen($component) > 256) {
        return ['status' => 'blocked', 'reason' => 'invalid-destination-name'];
      }
    }
    $pathKey = $root . ':' . implode('/', $components);
    $referenceKey = implode(':', [
      $finding['source'], $finding['source_id'], $finding['musician_id'],
      $finding['project_id'], $finding['option_key'] ?? '',
    ]);
    if (isset($destinations[$pathKey])) {
      $previous = $destinations[$pathKey];
      if ((int)$previous['file_id'] !== (int)$finding['file_id']) {
        return ['status' => 'blocked', 'reason' => 'destination-name-conflict'];
      }
      if (in_array($finding['source'], ['participant-field-support', 'debit-mandate'], true)
          && isset($previous[$finding['source']]) && $previous[$finding['source']] !== $referenceKey) {
        return ['status' => 'blocked', 'reason' => 'destination-reference-conflict'];
      }
    }
    $parent = (int)$root;
    $missing = false;
    $folders = [];
    foreach (array_slice($components, 0, -1) as $component) {
      $folders[] = $component;
      if ($missing) {
        continue;
      }
      $entry = $this->connection->fetchAssociative(
        'SELECT id, type, file_id FROM DatabaseStorageDirEntries WHERE parent_id = ? AND name = ?',
        [$parent, $component],
      );
      if ($entry && $entry['type'] !== 'folder') {
        return ['status' => 'blocked', 'reason' => 'destination-name-conflict'];
      }
      if (!$entry) {
        $missing = true;
      } else {
        $parent = (int)$entry['id'];
      }
    }
    $existing = $missing ? false : $this->connection->fetchAssociative(
      'SELECT id, type, file_id FROM DatabaseStorageDirEntries WHERE parent_id = ? AND name = ?',
      [$parent, $source['name']],
    );
    if ($existing && ($existing['type'] !== 'file' || (int)$existing['file_id'] !== (int)$finding['file_id'])) {
      return ['status' => 'blocked', 'reason' => 'destination-name-conflict'];
    }
    if ($existing && (int)$existing['id'] !== (int)$finding['entry_id'] && (
      ($finding['source'] === 'participant-field-support' && $this->connection->fetchOne(
        'SELECT 1 FROM ProjectParticipantFieldsData WHERE supporting_document_id = ?',
        [$existing['id']],
      ))
      || ($finding['source'] === 'debit-mandate' && $this->connection->fetchOne(
        'SELECT 1 FROM SepaDebitMandates WHERE written_mandate_id = ?',
        [$existing['id']],
      ))
    )) {
      return ['status' => 'blocked', 'reason' => 'destination-reference-conflict'];
    }
    $destinations[$pathKey]['file_id'] = $finding['file_id'];
    $destinations[$pathKey][$finding['source']] = $referenceKey;
    $move = !$existing && isset($movableEntries[(int)$finding['entry_id']]);
    if ($dryRun) {
      return ['status' => 'planned', 'action' => $move ? 'move' : 'link'];
    }
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
    $entryId = $existing
      ? (int)$existing['id']
      : ($this->createFileEntry)(
        (int)$root,
        $folders,
        $source['name'],
        (int)$finding['file_id'],
        $move ? (int)$finding['entry_id'] : null,
      );
    if ($entryId <= 0) {
      throw new RuntimeException('The ORM did not return the repaired participant file entry ID.');
    }
    if ($entryId === (int)$finding['entry_id']) {
      return ['status' => 'applied', 'action' => $move ? 'move' : 'link', 'participant_entry_id' => $entryId];
    }
    if (in_array($finding['source'], ['participant-field', 'participant-field-support'], true)) {
      $column = $finding['source'] === 'participant-field' ? 'option_value' : 'supporting_document_id';
      $updated = $this->connection->executeStatement(
        'UPDATE ProjectParticipantFieldsData SET ' . $column . ' = ?, updated = ?
         WHERE field_id = ? AND musician_id = ? AND project_id = ? AND HEX(option_key) = ?
           AND ' . $column . ' = ? AND deleted IS NULL',
        [
          $entryId, $now, $finding['source_id'], $finding['musician_id'],
          $finding['project_id'], strtoupper($finding['option_key']), $finding['entry_id'],
        ],
      );
      if ($updated !== 1) {
        throw new RuntimeException('The participant field reference changed during repair.');
      }
    } elseif ($finding['source'] === 'debit-mandate') {
      $updated = $this->connection->executeStatement(
        'UPDATE SepaDebitMandates SET written_mandate_id = ?
         WHERE sequence = ? AND musician_id = ? AND project_id = ? AND written_mandate_id = ?',
        [$entryId, $finding['source_id'], $finding['musician_id'], $finding['project_id'], $finding['entry_id']],
      );
      if ($updated !== 1) {
        throw new RuntimeException('The debit mandate reference changed during repair.');
      }
    } elseif ($finding['source'] === 'composite-payment') {
      $updated = $this->connection->executeStatement(
        'UPDATE CompositePayments SET supporting_document_id = ?
         WHERE id = ? AND musician_id = ? AND project_id = ? AND supporting_document_id = ?',
        [$entryId, $finding['source_id'], $finding['musician_id'], $finding['project_id'], $finding['entry_id']],
      );
      if ($updated !== 1) {
        throw new RuntimeException('The composite payment reference changed during repair.');
      }
    }
    return ['status' => 'applied', 'action' => 'link', 'participant_entry_id' => $entryId];
  }
}
