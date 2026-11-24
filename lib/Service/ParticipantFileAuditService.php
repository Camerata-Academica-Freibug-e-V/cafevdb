<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Service;

use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;

/** Inspect file grants and participant links without loading encrypted data or ORM entities. */
class ParticipantFileAuditService
{
  /** @param Connection $connection Orchestra database connection. */
  public function __construct(private Connection $connection)
  {
  }

  /**
   * Include soft-deleted records: the personalized views do not filter them either.
   *
   * @return array{summary: array, findings: array}
   */
  public function audit(): array
  {
    return $this->connection->transactional(fn() => $this->inspect());
  }

  /** @return array */
  private function inspect(): array
  {
    $files = array_column($this->connection->fetchAllAssociative('SELECT id, type FROM Files'), null, 'id');
    $owners = [];
    foreach ($this->connection->fetchAllAssociative('SELECT encrypted_file_id, musician_id FROM EncryptedFileOwners') as $owner) {
      $owners[$owner['encrypted_file_id']][(int)$owner['musician_id']] = true;
    }
    $entries = array_column(
      $this->connection->fetchAllAssociative('SELECT id, parent_id, file_id, type FROM DatabaseStorageDirEntries'),
      null,
      'id',
    );
    $participants = $this->connection->fetchAllAssociative(
      'SELECT p.musician_id, p.project_id, s.root_id
       FROM ProjectParticipants p LEFT JOIN DatabaseStorages s ON s.id = p.database_documents_id',
    );
    $participantRoots = [];
    $rootParticipants = [];
    foreach ($participants as $participant) {
      $key = $participant['musician_id'] . ':' . $participant['project_id'];
      $participantRoots[$key] = $participant['root_id'];
      if ($participant['root_id'] !== null) {
        $rootParticipants[$participant['root_id']][$key] = true;
      }
    }
    $findings = [];
    $references = $this->references();
    $expected = $this->documentContexts($references, $entries, $owners, $participantRoots, $findings);
    $relevantFiles = $owners + $expected;
    foreach (array_keys($relevantFiles) as $fileId) {
      if (($files[$fileId]['type'] ?? null) !== 'encrypted') {
        $findings[] = ['code' => 'invalid-encrypted-file', 'file_id' => (int)$fileId];
      }
      $this->checkOwners((int)$fileId, $owners[$fileId] ?? [], $expected[$fileId] ?? [], $findings);
    }
    $links = $this->participantLinks($entries, $rootParticipants, $relevantFiles, $findings);
    $this->checkLinks($owners, $expected, $links, $participantRoots, $findings);
    usort($findings, static fn(array $left, array $right) => strcmp(
      json_encode($left, JSON_THROW_ON_ERROR),
      json_encode($right, JSON_THROW_ON_ERROR),
    ));
    return [
      'summary' => [
        'owned_files' => count($owners),
        'ownership_grants' => array_sum(array_map('count', $owners)),
        'document_references' => count($references),
        'findings' => count($findings),
      ],
      'findings' => $findings,
    ];
  }

  /**
   * @param array $references
   * @param array $entries
   * @param array $owners
   * @param array $participantRoots
   * @param array $findings
   *
   * @return array
   */
  private function documentContexts(array $references, array $entries, array $owners, array $participantRoots, array &$findings): array
  {
    $expected = [];
    foreach ($references as $reference) {
      $entry = $entries[$reference['entry_id']] ?? null;
      $context = array_merge($reference, ['file_id' => isset($entry['file_id']) ? (int)$entry['file_id'] : null]);
      if ($entry === null || $entry['type'] !== 'file' || $context['file_id'] === null) {
        $findings[] = ['code' => 'broken-document-reference'] + $context;
        continue;
      }
      $fileId = $context['file_id'];
      $key = $reference['musician_id'] . ':' . $reference['project_id'];
      $expected[$fileId][$key] = $context;
      if (!isset($owners[$fileId][$reference['musician_id']])) {
        $findings[] = ['code' => 'missing-ownership'] + $context;
      }
      if (!array_key_exists($key, $participantRoots)) {
        $findings[] = ['code' => 'missing-participant'] + $context;
      }
    }
    return $expected;
  }

  /** @return array */
  private function references(): array
  {
    return $this->connection->fetchAllAssociative(
      "SELECT 'debit-mandate' AS source, sequence AS source_id, musician_id, project_id,
              written_mandate_id AS entry_id, deleted, NULL AS option_key
       FROM SepaDebitMandates WHERE written_mandate_id IS NOT NULL
       UNION ALL
       SELECT 'invoice', id, debitor_id, project_id, written_invoice_id, deleted, NULL
       FROM Invoices WHERE written_invoice_id IS NOT NULL
       UNION ALL
       SELECT 'donation-receipt', r.id, p.musician_id, p.project_id, r.supporting_document_id, r.deleted, NULL
       FROM DonationReceipts r LEFT JOIN CompositePayments p ON p.id = r.donation_id
       WHERE r.supporting_document_id IS NOT NULL
       UNION ALL
       SELECT 'composite-payment', id, musician_id, project_id, supporting_document_id, NULL, NULL
       FROM CompositePayments WHERE supporting_document_id IS NOT NULL
       UNION ALL
       SELECT 'participant-field', d.field_id, d.musician_id, d.project_id, d.option_value, d.deleted, HEX(d.option_key)
       FROM ProjectParticipantFieldsData d JOIN ProjectParticipantFields f ON f.id = d.field_id
       WHERE f.data_type = 'db-file' AND d.option_value IS NOT NULL AND d.option_value <> ''
       UNION ALL
       SELECT 'participant-field-support', d.field_id, d.musician_id, d.project_id,
              d.supporting_document_id, d.deleted, HEX(d.option_key)
       FROM ProjectParticipantFieldsData d WHERE d.supporting_document_id IS NOT NULL",
    );
  }

  /**
   * @param int $fileId
   * @param array $owners
   * @param array $expected
   * @param array $findings
   *
   * @return void
   */
  private function checkOwners(int $fileId, array $owners, array $expected, array &$findings): void
  {
    foreach (array_keys($owners) as $musicianId) {
      $matches = array_filter($expected, static fn(array $reference) => (int)$reference['musician_id'] === $musicianId);
      if ($matches === []) {
        $findings[] = [
          'code' => $expected === [] ? 'unrecognized-owned-file' : 'unexpected-owner',
          'file_id' => $fileId,
          'musician_id' => $musicianId,
        ];
      }
    }
  }

  /**
   * @param array $entries
   * @param array $rootParticipants
   * @param array $relevantFiles
   * @param array $findings
   *
   * @return array
   */
  private function participantLinks(array $entries, array $rootParticipants, array $relevantFiles, array &$findings): array
  {
    $links = [];
    foreach ($entries as $entry) {
      $fileId = $entry['file_id'];
      if ($entry['type'] !== 'file' || !isset($relevantFiles[$fileId])) {
        continue;
      }
      $current = $entry;
      $visited = [];
      while ($current['parent_id'] !== null && !isset($visited[$current['id']])) {
        $visited[$current['id']] = true;
        $parent = $entries[$current['parent_id']] ?? null;
        if ($parent === null || $parent['type'] !== 'folder') {
          break;
        }
        $current = $parent;
      }
      if ($current['parent_id'] !== null) {
        $findings[] = ['code' => 'broken-directory-tree', 'file_id' => (int)$fileId, 'entry_id' => (int)$entry['id']];
        continue;
      }
      foreach (array_keys($rootParticipants[$current['id']] ?? []) as $key) {
        $links[$fileId][$key][] = (int)$entry['id'];
      }
    }
    return $links;
  }

  /**
   * @param array $owners
   * @param array $expected
   * @param array $links
   * @param array $participantRoots
   * @param array $findings
   *
   * @return void
   */
  private function checkLinks(array $owners, array $expected, array $links, array $participantRoots, array &$findings): void
  {
    foreach ($expected as $fileId => $contexts) {
      foreach ($contexts as $key => $context) {
        if (!isset($links[$fileId][$key])) {
          $findings[] = [
            'code' => 'missing-participant-link',
            'root_id' => isset($participantRoots[$key]) ? (int)$participantRoots[$key] : null,
          ] + $context;
        }
      }
    }
    foreach ($links as $fileId => $participants) {
      foreach ($participants as $key => $entryIds) {
        [$musicianId, $projectId] = array_map('intval', explode(':', $key));
        if (isset($owners[$fileId][$musicianId]) && isset($expected[$fileId][$key])) {
          continue;
        }
        foreach ($entryIds as $entryId) {
          $findings[] = [
            'code' => isset($owners[$fileId][$musicianId]) ? 'unrelated-participant-link' : 'non-owner-participant-link',
            'file_id' => (int)$fileId,
            'musician_id' => $musicianId,
            'project_id' => $projectId,
            'entry_id' => $entryId,
          ];
        }
      }
    }
  }
}
