<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Service;

use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;

/** Inspect file grants and participant links without loading encrypted data or ORM entities. */
class ParticipantFileAuditService
{
  /**
   * @param Connection $connection Orchestra database connection.
   * @param int $clubMembersProjectId Configured club-members project ID, or zero if unset.
   */
  public function __construct(private Connection $connection, private int $clubMembersProjectId)
  {
  }

  /**
   * Include soft-deleted records: the personalized views do not filter them either.
   *
   * @param bool $includeFileNames Include potentially identifying file names and paths.
   * @param array<int, string> $mountPaths Mounted paths indexed by database root entry ID.
   * @param bool $includeFieldNames Include potentially identifying field names.
   *
   * @return array{summary: array, findings: array}
   */
  public function audit(bool $includeFileNames = false, array $mountPaths = [], bool $includeFieldNames = false): array
  {
    return $this->connection->transactional(fn() => $this->inspect($includeFileNames, $mountPaths, $includeFieldNames));
  }

  /**
   * @param bool $includeFileNames
   * @param array $mountPaths
   * @param bool $includeFieldNames
   *
   * @return array
   */
  private function inspect(bool $includeFileNames, array $mountPaths, bool $includeFieldNames): array
  {
    $files = array_column($this->connection->fetchAllAssociative(
      'SELECT id, type' . ($includeFileNames ? ', file_name' : '') . ' FROM Files',
    ), null, 'id');
    $owners = [];
    foreach ($this->connection->fetchAllAssociative('SELECT encrypted_file_id, musician_id FROM EncryptedFileOwners') as $owner) {
      $owners[$owner['encrypted_file_id']][(int)$owner['musician_id']] = true;
    }
    $entries = array_column(
      $this->connection->fetchAllAssociative(
        'SELECT id, parent_id, file_id, type' . ($includeFileNames ? ', name' : '') . ' FROM DatabaseStorageDirEntries',
      ),
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
    $clubMandates = [];
    $expected = $this->documentContexts($references, $entries, $owners, $participantRoots, $findings, $clubMandates);
    $relevantFiles = $owners + $expected;
    foreach (array_keys($relevantFiles) as $fileId) {
      if (($files[$fileId]['type'] ?? null) !== 'encrypted') {
        $findings[] = ['code' => 'invalid-encrypted-file', 'file_id' => (int)$fileId];
      }
      $this->checkOwners((int)$fileId, $owners[$fileId] ?? [], $expected[$fileId] ?? [], $findings);
    }
    $links = $this->participantLinks($entries, $rootParticipants, $relevantFiles, $findings);
    $this->checkLinks($owners, $expected, $links, $participantRoots, $findings, $clubMandates);
    $this->annotateRepairs($findings, $files, $entries, $links, $participantRoots, $rootParticipants);
    $this->annotateFields($findings, $references, $entries, $includeFieldNames);
    usort($findings, static fn(array $left, array $right) => strcmp(
      json_encode($left, JSON_THROW_ON_ERROR),
      json_encode($right, JSON_THROW_ON_ERROR),
    ));
    if ($includeFileNames) {
      $filePaths = [];
      foreach ($entries as $entry) {
        if ($entry['type'] === 'file' && $entry['file_id'] !== null && isset($relevantFiles[$entry['file_id']])) {
          $filePaths[$entry['file_id']][$entry['id']] = $this->entryPath((int)$entry['id'], $entries, $mountPaths);
        }
      }
      foreach ($findings as &$finding) {
        $finding['file_name'] = isset($finding['file_id']) ? ($files[$finding['file_id']]['file_name'] ?? null) : null;
        if (array_key_exists('entry_id', $finding)) {
          $finding['entry_name'] = isset($finding['entry_id']) ? ($entries[$finding['entry_id']]['name'] ?? null) : null;
          $finding['entry_path'] = isset($finding['entry_id']) ? $this->entryPath($finding['entry_id'], $entries, $mountPaths) : null;
        } else {
          $finding['file_paths'] = [];
          foreach ($filePaths[$finding['file_id'] ?? 0] ?? [] as $entryId => $path) {
            $finding['file_paths'][] = ['entry_id' => (int)$entryId, 'path' => $path];
          }
        }
      }
      unset($finding);
    }
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
   * @param array $findings
   * @param array $references
   * @param array $entries
   * @param bool $includeFieldNames
   *
   * @return void
   */
  private function annotateFields(array &$findings, array $references, array $entries, bool $includeFieldNames): void
  {
    $fields = array_column($this->connection->fetchAllAssociative(
      'SELECT id, data_type, multiplicity, participant_access' . ($includeFieldNames ? ', name' : '') . ' FROM ProjectParticipantFields',
    ), null, 'id');
    $byFile = [];
    $byEntry = [];
    $byReference = [];
    foreach ($references as $reference) {
      if (!in_array($reference['source'], ['participant-field', 'participant-field-support'], true)
          || !isset($fields[$reference['source_id']])) {
        continue;
      }
      $field = $fields[$reference['source_id']];
      $rawEntryId = $reference['entry_id'];
      $validEntryId = is_scalar($rawEntryId) && ctype_digit((string)$rawEntryId)
        && (string)(int)$rawEntryId === ltrim((string)$rawEntryId, '0') && (int)$rawEntryId > 0;
      $entryId = $validEntryId ? (int)$rawEntryId : null;
      $association = [
        'field_id' => (int)$reference['source_id'],
        'field_type' => $field['data_type'],
        'multiplicity' => $field['multiplicity'],
        'participant_access' => $field['participant_access'],
        'relation' => $reference['source'] === 'participant-field-support' ? 'supporting_document_id' : 'option_value',
        'entry_id' => $entryId,
        'musician_id' => (int)$reference['musician_id'],
        'project_id' => (int)$reference['project_id'],
        'option_key' => $reference['option_key'],
      ];
      if ($includeFieldNames) {
        $association['field_name'] = $field['name'];
      }
      if ($entryId === null) {
        $referenceKey = implode(':', [
          $reference['source'], $reference['source_id'], $reference['musician_id'],
          $reference['project_id'], $reference['option_key'],
        ]);
        $byReference[$referenceKey][] = $association;
        continue;
      }
      $byEntry[$entryId][] = $association;
      $fileId = $entries[$entryId]['file_id'] ?? null;
      if (($entries[$entryId]['type'] ?? null) === 'file' && $fileId !== null) {
        $byFile[$fileId][] = $association;
      }
    }
    foreach ($findings as &$finding) {
      $associations = isset($finding['file_id'])
        ? ($byFile[$finding['file_id']] ?? [])
        : (isset($finding['entry_id']) ? ($byEntry[$finding['entry_id']] ?? []) : []);
      if ($associations === [] && $finding['code'] === 'broken-document-reference'
          && in_array($finding['source'], ['participant-field', 'participant-field-support'], true)) {
        $referenceKey = implode(':', [
          $finding['source'], $finding['source_id'], $finding['musician_id'],
          $finding['project_id'], $finding['option_key'],
        ]);
        $associations = $byReference[$referenceKey] ?? [];
      }
      if ($associations !== []) {
        usort($associations, static fn(array $left, array $right) => strcmp(
          json_encode($left, JSON_THROW_ON_ERROR),
          json_encode($right, JSON_THROW_ON_ERROR),
        ));
        $finding['participant_fields'] = $associations;
      }
    }
    unset($finding);
  }

  /**
   * @param int $entryId
   * @param array $entries
   * @param array $mountPaths
   *
   * @return null|string Full mounted path, or null for an unresolved directory chain or mount.
   */
  private function entryPath(int $entryId, array $entries, array $mountPaths): ?string
  {
    $components = [];
    $visited = [];
    while (isset($entries[$entryId]) && !isset($visited[$entryId])) {
      $entry = $entries[$entryId];
      $visited[$entryId] = true;
      if ($entry['parent_id'] === null) {
        return isset($mountPaths[$entryId]) && $entry['type'] === 'folder'
          ? rtrim($mountPaths[$entryId], '/') . '/' . implode('/', array_reverse($components))
          : null;
      }
      if (!isset($entry['name']) || ($components !== [] && $entry['type'] !== 'folder')) {
        return null;
      }
      $components[] = $entry['name'];
      $entryId = (int)$entry['parent_id'];
    }
    return null;
  }

  /**
   * @param array $references
   * @param array $entries
   * @param array $owners
   * @param array $participantRoots
   * @param array $findings
   * @param array $clubMandates
   *
   * @return array
   */
  private function documentContexts(
    array $references,
    array $entries,
    array $owners,
    array $participantRoots,
    array &$findings,
    array &$clubMandates,
  ): array {
    $expected = [];
    foreach ($references as $reference) {
      $entryId = $reference['entry_id'];
      if (!is_scalar($entryId) || !ctype_digit((string)$entryId)
          || (string)(int)$entryId !== ltrim((string)$entryId, '0') || (int)$entryId <= 0) {
        $reference['entry_id'] = null;
        $findings[] = ['code' => 'broken-document-reference'] + $reference + ['file_id' => null];
        continue;
      }
      $reference['entry_id'] = (int)$entryId;
      $entry = $entries[$reference['entry_id']] ?? null;
      $context = array_merge($reference, ['file_id' => isset($entry['file_id']) ? (int)$entry['file_id'] : null]);
      if ($entry === null || $entry['type'] !== 'file' || $context['file_id'] === null) {
        $findings[] = ['code' => 'broken-document-reference'] + $context;
        continue;
      }
      $fileId = $context['file_id'];
      $key = $reference['musician_id'] . ':' . $reference['project_id'];
      $expected[$fileId][$key] = $context;
      if ($reference['source'] === 'debit-mandate' && $this->clubMembersProjectId > 0
          && (int)$reference['project_id'] === $this->clubMembersProjectId) {
        $clubMandates[$fileId][(int)$reference['musician_id']] = true;
      }
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
   * Identify missing grants justified by supporting documents or debit mandates.
   *
   * @param array $findings
   * @param array $files
   * @param array $entries
   * @param array $links
   * @param array $participantRoots
   * @param array $rootParticipants
   *
   * @return void
   */
  private function annotateRepairs(
    array &$findings,
    array $files,
    array $entries,
    array $links,
    array $participantRoots,
    array $rootParticipants,
  ): void {
    $candidates = [];
    foreach ($this->repairableSupportingDocuments() as $candidate) {
      $entryId = (int)$candidate['entry_id'];
      $fileId = $entries[$entryId]['file_id'] ?? null;
      $key = $candidate['musician_id'] . ':' . $candidate['project_id'];
      $root = $participantRoots[$key] ?? null;
      if ($fileId === null || ($files[$fileId]['type'] ?? null) !== 'encrypted'
          || $root === null || count($rootParticipants[$root] ?? []) !== 1
          || !in_array($entryId, $links[$fileId][$key] ?? [], true)) {
        continue;
      }
      $candidates[$fileId][$key][$entryId][$candidate['field_id'] . ':' . $candidate['option_key']] = true;
    }
    $mandates = $this->repairableMandates($files, $entries, $links, $participantRoots, $rootParticipants);
    foreach ($findings as &$finding) {
      $finding['repairable'] = $this->isRepairableSupportFinding($finding, $candidates)
        || $this->isRepairableMandateFinding($finding, $mandates);
      if ($finding['repairable']) {
        $finding['repairsteps'] = ['add-ownership', 'reconcile-encryption-seal'];
      }
    }
    unset($finding);
  }

  /**
   * @param array $files
   * @param array $entries
   * @param array $links
   * @param array $participantRoots
   * @param array $rootParticipants
   *
   * @return array
   */
  private function repairableMandates(
    array $files,
    array $entries,
    array $links,
    array $participantRoots,
    array $rootParticipants,
  ): array {
    $candidates = [];
    $rows = $this->connection->fetchAllAssociative(
      'SELECT mandate.sequence, mandate.musician_id, mandate.project_id, mandate.written_mandate_id,
              target.project_id AS target_project_id
       FROM SepaDebitMandates mandate
       JOIN Musicians musician ON musician.id = mandate.musician_id AND musician.deleted IS NULL
       JOIN Projects source_project ON source_project.id = mandate.project_id AND source_project.deleted IS NULL
       JOIN ProjectParticipants source_participant ON source_participant.project_id = mandate.project_id
         AND source_participant.musician_id = mandate.musician_id AND source_participant.deleted IS NULL
       JOIN ProjectParticipants target ON target.musician_id = mandate.musician_id AND target.deleted IS NULL
       JOIN Projects target_project ON target_project.id = target.project_id AND target_project.deleted IS NULL
       WHERE mandate.deleted IS NULL AND mandate.written_mandate_id IS NOT NULL
         AND (target.project_id = mandate.project_id OR (? > 0 AND mandate.project_id = ?))',
      [$this->clubMembersProjectId, $this->clubMembersProjectId],
    );
    foreach ($rows as $mandate) {
      $sourceProjectId = (int)$mandate['project_id'];
      $targetProjectId = (int)$mandate['target_project_id'];
      $entryId = (int)$mandate['written_mandate_id'];
      $fileId = $entries[$entryId]['file_id'] ?? null;
      $sourceKey = $mandate['musician_id'] . ':' . $sourceProjectId;
      $targetKey = $mandate['musician_id'] . ':' . $targetProjectId;
      $sourceRoot = $participantRoots[$sourceKey] ?? null;
      $targetRoot = $participantRoots[$targetKey] ?? null;
      if ($fileId === null || ($files[$fileId]['type'] ?? null) !== 'encrypted'
          || $sourceRoot === null || count($rootParticipants[$sourceRoot] ?? []) !== 1
          || $targetRoot === null || count($rootParticipants[$targetRoot] ?? []) !== 1
          || !in_array($entryId, $links[$fileId][$sourceKey] ?? [], true)) {
        continue;
      }
      foreach ($links[$fileId][$targetKey] ?? [] as $targetEntryId) {
        $candidates[$fileId][$targetKey][$targetEntryId][$sourceProjectId . ':' . $mandate['sequence']] = true;
      }
    }
    return $candidates;
  }

  /**
   * @param array $finding
   * @param array $candidates
   *
   * @return bool
   */
  private function isRepairableMandateFinding(array $finding, array $candidates): bool
  {
    if (!in_array($finding['code'], ['missing-ownership', 'non-owner-participant-link'], true)
        || !isset($finding['file_id'], $finding['musician_id'], $finding['project_id'], $finding['entry_id'])) {
      return false;
    }
    $key = $finding['musician_id'] . ':' . $finding['project_id'];
    $matches = $candidates[$finding['file_id']][$key][$finding['entry_id']] ?? [];
    if ($finding['code'] === 'missing-ownership') {
      return $finding['source'] === 'debit-mandate'
        && isset($matches[$finding['project_id'] . ':' . $finding['source_id']]);
    }
    return $matches !== [];
  }

  /** @return array */
  private function repairableSupportingDocuments(): array
  {
    return $this->connection->fetchAllAssociative(
      "SELECT d.field_id, d.musician_id, d.project_id, d.supporting_document_id AS entry_id, HEX(d.option_key) AS option_key
       FROM ProjectParticipantFieldsData d
       JOIN ProjectParticipantFields f ON f.id = d.field_id AND f.project_id = d.project_id
       JOIN ProjectParticipants pp ON pp.musician_id = d.musician_id AND pp.project_id = d.project_id
       JOIN Projects p ON p.id = d.project_id
       JOIN Musicians m ON m.id = d.musician_id
       WHERE d.supporting_document_id IS NOT NULL AND d.deleted IS NULL AND f.deleted IS NULL
         AND pp.deleted IS NULL AND p.deleted IS NULL AND m.deleted IS NULL
         AND f.participant_access IN ('read', 'read-write')
         AND (
           f.participation_context = 'unrestricted'
           OR (f.participation_context = 'participants' AND pp.participation_status <> 'associated'
               AND (
                 NOT EXISTS (
                   SELECT 1 FROM ProjectInstruments pi
                   JOIN instrument_instrument_family iif ON iif.instrument_id = pi.instrument_id
                   JOIN InstrumentFamilies fam ON fam.id = iif.instrument_family_id
                   WHERE pi.project_id = pp.project_id AND pi.musician_id = pp.musician_id
                     AND fam.family = 'not an instrument'
                 )
                 OR EXISTS (
                   SELECT 1 FROM ProjectInstruments pi
                   WHERE pi.project_id = pp.project_id AND pi.musician_id = pp.musician_id
                     AND NOT EXISTS (
                       SELECT 1 FROM instrument_instrument_family iif
                       JOIN InstrumentFamilies fam ON fam.id = iif.instrument_family_id
                       WHERE iif.instrument_id = pi.instrument_id AND fam.family = 'not an instrument'
                     )
                 )
               ))
         )",
    );
  }

  /**
   * @param array $finding
   * @param array $candidates
   *
   * @return bool
   */
  private function isRepairableSupportFinding(array $finding, array $candidates): bool
  {
    if (!in_array($finding['code'], ['missing-ownership', 'non-owner-participant-link'], true)
        || !isset($finding['file_id'], $finding['musician_id'], $finding['project_id'], $finding['entry_id'])) {
      return false;
    }
    $key = $finding['musician_id'] . ':' . $finding['project_id'];
    $matches = $candidates[$finding['file_id']][$key][$finding['entry_id']] ?? [];
    if ($finding['code'] === 'missing-ownership') {
      return $finding['source'] === 'participant-field-support'
        && isset($matches[$finding['source_id'] . ':' . $finding['option_key']]);
    }
    return $matches !== [];
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
      if ($entry['type'] !== 'file' || $fileId === null || !isset($relevantFiles[$fileId])) {
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
   * @param array $clubMandates
   *
   * @return void
   */
  private function checkLinks(
    array $owners,
    array $expected,
    array $links,
    array $participantRoots,
    array &$findings,
    array $clubMandates,
  ): void {
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
        if (isset($owners[$fileId][$musicianId])
            && (isset($expected[$fileId][$key]) || isset($clubMandates[$fileId][$musicianId]))) {
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
