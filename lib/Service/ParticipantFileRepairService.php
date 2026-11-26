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
   */
  public function __construct(
    private Connection $connection,
    private int $clubMembersProjectId,
    private Closure $destination,
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
            $result += $this->addLink($finding, $dryRun, $destinations);
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
   * @param array $finding
   * @param bool $dryRun
   * @param array $destinations
   *
   * @return array
   */
  private function addLink(array $finding, bool $dryRun, array &$destinations): array
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
    if ($existing && (
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
    if ($dryRun) {
      return ['status' => 'planned'];
    }
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
    $parent = (int)$root;
    foreach ($folders as $component) {
      $id = $this->connection->fetchOne(
        'SELECT id FROM DatabaseStorageDirEntries WHERE parent_id = ? AND name = ?',
        [$parent, $component],
      );
      if (!$id) {
        $this->connection->insert('DatabaseStorageDirEntries', [
          'parent_id' => $parent, 'name' => $component, 'type' => 'folder',
          'created' => $now, 'updated' => $now,
        ]);
        $id = $this->connection->lastInsertId();
      }
      $parent = (int)$id;
    }
    if (!$existing) {
      $this->connection->insert('DatabaseStorageDirEntries', [
        'parent_id' => $parent, 'name' => $source['name'], 'type' => 'file',
        'file_id' => $finding['file_id'], 'created' => $now, 'updated' => $now,
      ]);
    }
    $entryId = $existing ? (int)$existing['id'] : (int)$this->connection->lastInsertId();
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
         WHERE sequence = ? AND musician_id = ? AND project_id = ? AND written_mandate_id = ? AND deleted IS NULL',
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
    while ($parent !== 0) {
      $this->connection->update('DatabaseStorageDirEntries', ['updated' => $now], ['id' => $parent]);
      $parent = (int)$this->connection->fetchOne('SELECT parent_id FROM DatabaseStorageDirEntries WHERE id = ?', [$parent]);
    }
    return ['status' => 'applied', 'participant_entry_id' => $entryId];
  }
}
