<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Service;

use RuntimeException;

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
   * @param null|callable $userStatus Resolve key-presence and login indicators for a cloud user.
   *
   * @return array{summary: array, findings: array}
   */
  public function audit(
    bool $includeFileNames = false,
    array $mountPaths = [],
    bool $includeFieldNames = false,
    ?callable $userStatus = null,
  ): array {
    return $this->connection->transactional(fn() => $this->inspect($includeFileNames, $mountPaths, $includeFieldNames, $userStatus));
  }

  /**
   * @param bool $includeFileNames
   * @param array $mountPaths
   * @param bool $includeFieldNames
   * @param null|callable $userStatus
   *
   * @return array
   */
  private function inspect(bool $includeFileNames, array $mountPaths, bool $includeFieldNames, ?callable $userStatus): array
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
      'SELECT p.musician_id, p.project_id, p.deleted, p.participation_status, s.root_id
       FROM ProjectParticipants p LEFT JOIN DatabaseStorages s ON s.id = p.database_documents_id',
    );
    $participantRoots = [];
    $rootParticipants = [];
    $participantRecords = [];
    foreach ($participants as $participant) {
      $key = $participant['musician_id'] . ':' . $participant['project_id'];
      $participantRoots[$key] = $participant['root_id'];
      $participantRecords[$key] = $participant;
      if ($participant['root_id'] !== null) {
        $rootParticipants[$participant['root_id']][$key] = true;
      }
    }
    $findings = [];
    $references = $this->references();
    $clubMandates = [];
    $documentContexts = [];
    $expected = $this->documentContexts($references, $entries, $owners, $participantRoots, $findings, $clubMandates, $documentContexts);
    $relevantFiles = $owners + $expected;
    foreach (array_keys($relevantFiles) as $fileId) {
      if (($files[$fileId]['type'] ?? null) !== 'encrypted') {
        $findings[] = ['code' => 'invalid-encrypted-file', 'file_id' => (int)$fileId];
      }
      $this->checkOwners((int)$fileId, $owners[$fileId] ?? [], $expected[$fileId] ?? [], $findings);
    }
    $links = $this->participantLinks($entries, $rootParticipants, $relevantFiles, $findings);
    $this->checkLinks($owners, $expected, $documentContexts, $links, $participantRoots, $findings, $clubMandates);
    $this->annotateRepairs($findings, $files, $entries, $owners, $links, $participantRoots, $rootParticipants);
    $this->annotateRepairReasons($findings, $references, $files, $entries, $participantRecords, $rootParticipants, $links);
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
    $report = [
      'summary' => [
        'owned_files' => count($owners),
        'ownership_grants' => array_sum(array_map('count', $owners)),
        'document_references' => count($references),
        'findings' => count($findings),
      ],
      'findings' => $findings,
    ];
    if ($userStatus !== null) {
      $report['encryption_diagnostics'] = $this->encryptionDiagnostics($owners, $files, $findings, $userStatus);
      $report['summary']['encryption_issues'] = count(array_filter(
        $report['encryption_diagnostics'],
        self::encryptionDiagnosticIsIssue(...),
      ));
    }
    return $report;
  }

  /**
   * Whether encryption diagnostics indicate an issue rather than a healthy grant.
   *
   * @param array $diagnostic
   *
   * @return bool
   */
  public static function encryptionDiagnosticIsIssue(array $diagnostic): bool
  {
    return $diagnostic['seal_status'] !== 'sealed'
      || $diagnostic['recipient_present'] !== true
      || $diagnostic['user_exists'] !== true
      || $diagnostic['public_key_present'] !== true
      || $diagnostic['private_key_present'] !== true;
  }

  /**
   * Inspect ownership grants and audit-approved missing grants without decrypting data.
   *
   * @param array $owners
   * @param array $files
   * @param array $findings
   * @param callable $userStatus
   *
   * @return array
   */
  private function encryptionDiagnostics(array $owners, array $files, array $findings, callable $userStatus): array
  {
    $grants = $owners;
    foreach ($findings as $finding) {
      if (($finding['repairable'] ?? false)
          && in_array('add-ownership', $finding['repairsteps'] ?? [], true)
          && isset($finding['file_id'], $finding['musician_id'])) {
        $grants[$finding['file_id']][$finding['musician_id']] = false;
      }
    }
    $musicians = array_column($this->connection->fetchAllAssociative('SELECT id, user_id_slug FROM Musicians'), 'user_id_slug', 'id');
    $statuses = [];
    $diagnostics = [];
    ksort($grants);
    foreach ($grants as $fileId => $fileOwners) {
      if (($files[$fileId]['type'] ?? null) !== 'encrypted') {
        continue;
      }
      $seal = $this->sealRecipients((int)$fileId);
      ksort($fileOwners);
      foreach ($fileOwners as $musicianId => $owned) {
        $userId = $musicians[$musicianId] ?? null;
        if ($userId !== null && $userId !== '') {
          $statuses[$userId] ??= $userStatus($userId);
          $status = $statuses[$userId];
        } else {
          $userId = null;
          $status = ['user_exists' => null, 'logged_in' => null, 'public_key_present' => null, 'private_key_present' => null];
        }
        $diagnostics[] = [
          'file_id' => (int)$fileId,
          'musician_id' => (int)$musicianId,
          'user_id' => $userId,
          'ownership_granted' => $owned,
          'seal_status' => $seal['status'],
          'recipient_present' => $seal['status'] === 'sealed' && $userId !== null
            ? isset($seal['recipients'][$userId]) : null,
        ] + $status;
      }
    }
    return $diagnostics;
  }

  /**
   * Read only the seal header and recipient trailer, not its encrypted payload.
   *
   * @param int $fileId
   *
   * @return array{status: string, recipients: array<string, bool>}
   */
  private function sealRecipients(int $fileId): array
  {
    $header = $this->connection->fetchAssociative(
      'SELECT SUBSTR(data, 1, 9) AS header, LENGTH(data) AS data_length FROM FileData WHERE file_id = ?',
      [$fileId],
    );
    if ($header === false) {
      return ['status' => 'missing-data', 'recipients' => []];
    }
    $prefix = $this->metadataString($header['header']);
    if (!preg_match('/^[0-9]{8}\\|$/D', $prefix)) {
      return ['status' => 'unsealed-or-invalid', 'recipients' => []];
    }
    $length = (int)substr($prefix, 0, 8);
    if ((int)$header['data_length'] < $length + 10) {
      return ['status' => 'invalid-seal', 'recipients' => []];
    }
    $trailer = $this->connection->fetchAssociative(
      'SELECT SUBSTR(data, ?, 1) AS seal_delimiter, SUBSTR(data, ?) AS recipients FROM FileData WHERE file_id = ?',
      [$length + 10, $length + 11, $fileId],
    );
    if ($trailer === false || $this->metadataString($trailer['seal_delimiter']) !== '|') {
      return ['status' => 'invalid-seal', 'recipients' => []];
    }
    $recipients = [];
    foreach (explode(';', $this->metadataString($trailer['recipients'])) as $recipient) {
      $parts = explode(':', $recipient, 2);
      if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return ['status' => 'invalid-seal', 'recipients' => []];
      }
      $recipients[$parts[0]] = true;
    }
    return ['status' => 'sealed', 'recipients' => $recipients];
  }

  /**
   * @param mixed $value
   *
   * @return string
   */
  private function metadataString(mixed $value): string
  {
    if (is_resource($value)) {
      $value = stream_get_contents($value);
    }
    if (!is_string($value)) {
      throw new RuntimeException('Unable to read encrypted file seal metadata.');
    }
    return $value;
  }

  /**
   * @param array $findings
   * @param array $references
   * @param array $files
   * @param array $entries
   * @param array $participants
   * @param array $rootParticipants
   * @param array $links
   *
   * @return void
   */
  private function annotateRepairReasons(
    array &$findings,
    array $references,
    array $files,
    array $entries,
    array $participants,
    array $rootParticipants,
    array $links,
  ): void {
    $musicians = array_column($this->connection->fetchAllAssociative('SELECT id, deleted FROM Musicians'), null, 'id');
    $projects = array_column($this->connection->fetchAllAssociative('SELECT id, deleted FROM Projects'), null, 'id');
    $fields = array_column($this->connection->fetchAllAssociative(
      'SELECT id, project_id, deleted, participant_access, participation_context FROM ProjectParticipantFields',
    ), null, 'id');
    $related = [];
    foreach ($references as $reference) {
      $entryId = $reference['entry_id'];
      $validEntryId = is_scalar($entryId) && ctype_digit((string)$entryId)
        && (string)(int)$entryId === ltrim((string)$entryId, '0') && (int)$entryId > 0;
      $fileId = $validEntryId ? ($entries[(int)$entryId]['file_id'] ?? null) : null;
      if ($fileId !== null && isset($reference['musician_id'], $reference['project_id'])) {
        $related[$fileId][$reference['musician_id'] . ':' . $reference['project_id']][] = $reference;
      }
    }
    foreach ($findings as &$finding) {
      if ($finding['repairable']) {
        continue;
      }
      $reasons = [];
      $musicianId = $finding['musician_id'] ?? null;
      $projectId = $finding['project_id'] ?? null;
      $fileId = $finding['file_id'] ?? null;
      $participant = null;
      $key = null;
      $matchingReferences = [];
      if ($musicianId !== null && ($musicians[$musicianId]['deleted'] ?? null) !== null) {
        $reasons[] = 'musician-deleted';
      }
      if ($projectId !== null && ($projects[$projectId]['deleted'] ?? null) !== null) {
        $reasons[] = 'project-deleted';
      }
      if ($fileId !== null && isset($files[$fileId]) && $files[$fileId]['type'] !== 'encrypted') {
        $reasons[] = 'file-not-encrypted';
      }
      if ($musicianId !== null && $projectId !== null) {
        $key = $musicianId . ':' . $projectId;
        $participant = $participants[$key] ?? null;
        if ($participant !== null) {
          if (($participant['deleted'] ?? null) !== null) {
            $reasons[] = 'participant-deleted';
          }
          $root = $participant['root_id'];
          if ($root === null) {
            $reasons[] = 'participant-storage-missing';
          } elseif (count($rootParticipants[$root] ?? []) > 1) {
            $reasons[] = 'participant-storage-shared';
          }
        } elseif ($finding['code'] === 'missing-participant') {
          $reasons[] = 'participant-missing';
        }
        if ($finding['code'] === 'non-owner-participant-link' && $fileId !== null) {
          $matchingReferences = $related[$fileId][$key] ?? [];
          if ($this->clubMembersProjectId > 0 && (int)$projectId !== $this->clubMembersProjectId) {
            $sourceKey = $musicianId . ':' . $this->clubMembersProjectId;
            $mandateReferences = [];
            foreach ($related[$fileId][$sourceKey] ?? [] as $reference) {
              if ($reference['source'] === 'debit-mandate') {
                $matchingReferences[] = $reference;
                if (($entries[(int)$reference['entry_id']]['type'] ?? null) === 'file') {
                  $mandateReferences[] = $reference;
                }
              }
            }
            if ($mandateReferences !== [] && !array_filter(
              $mandateReferences,
              static fn(array $reference) => in_array((int)$reference['entry_id'], $links[$fileId][$sourceKey] ?? [], true),
            )) {
              $reasons[] = 'club-mandate-source-link-missing';
            }
          }
          if ($matchingReferences !== [] && count(array_filter(
            $matchingReferences,
            static fn(array $reference) => $reference['source'] === 'debit-mandate' || $reference['deleted'] === null,
          )) === 0) {
            $reasons[] = 'document-deleted';
          }
        }
      }
      if (isset($finding['source']) && ($finding['deleted'] ?? null) !== null
          && $finding['source'] !== 'debit-mandate') {
        $reasons[] = 'document-deleted';
      }
      $fieldReference = in_array($finding['source'] ?? null, ['participant-field', 'participant-field-support'], true)
        ? $finding : null;
      if ($fieldReference === null && $finding['code'] === 'non-owner-participant-link'
          && isset($key, $fileId, $finding['entry_id'])) {
        if (count($matchingReferences) === 1
            && in_array($matchingReferences[0]['source'], ['participant-field', 'participant-field-support'], true)
            && (int)$matchingReferences[0]['entry_id'] === $finding['entry_id']) {
          $fieldReference = $matchingReferences[0];
        }
      }
      if ($fieldReference !== null && isset($fields[$fieldReference['source_id']])) {
        $field = $fields[$fieldReference['source_id']];
        if (($field['deleted'] ?? null) !== null) {
          $reasons[] = 'field-deleted';
        }
        if (isset($field['project_id'], $fieldReference['project_id'])
            && (int)$field['project_id'] !== (int)$fieldReference['project_id']) {
          $reasons[] = 'field-project-mismatch';
        }
        if (isset($field['participant_access']) && !in_array($field['participant_access'], ['read', 'read-write'], true)) {
          $reasons[] = 'field-access-not-readable';
        }
        if (isset($field['participation_context'])
            && (!in_array($field['participation_context'], ['unrestricted', 'participants'], true)
            || ($field['participation_context'] === 'participants'
                && ($participant['participation_status'] ?? null) === 'associated'))) {
          $reasons[] = 'field-context-not-eligible';
        }
      }
      if ($finding['code'] === 'broken-directory-tree') {
        $reasons[] = 'directory-tree-broken';
      } elseif ($finding['code'] === 'broken-document-reference') {
        $reasons[] = 'document-reference-broken';
      }
      if ($reasons !== []) {
        $finding['repair_reasons'] = array_values(array_unique($reasons));
      }
    }
    unset($finding);
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
   * @param array $documentContexts
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
    array &$documentContexts,
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
      $documentContexts[$fileId][$key][] = $context;
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

  /**
   * @return array
   *
   * Revocation invalidates the mandate, not the participant's access to its written document.
   */
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
   * Identify missing grants justified by supporting documents, financial documents or debit mandates.
   *
   * @param array $findings
   * @param array $files
   * @param array $entries
   * @param array $owners
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
    array $owners,
    array $links,
    array $participantRoots,
    array $rootParticipants,
  ): void {
    $candidates = [];
    $missingSupportLinks = [];
    $brokenFiles = [];
    foreach ($findings as $finding) {
      if ($finding['code'] === 'broken-directory-tree') {
        $brokenFiles[$finding['file_id']] = true;
      }
    }
    foreach ($this->repairableSupportingDocuments() as $candidate) {
      $entryId = (int)$candidate['entry_id'];
      $fileId = $entries[$entryId]['file_id'] ?? null;
      $key = $candidate['musician_id'] . ':' . $candidate['project_id'];
      $root = $participantRoots[$key] ?? null;
      if (($entries[$entryId]['type'] ?? null) !== 'file' || $fileId === null
          || ($files[$fileId]['type'] ?? null) !== 'encrypted'
          || $root === null || count($rootParticipants[$root] ?? []) !== 1) {
        continue;
      }
      $referenceKey = $candidate['field_id'] . ':' . $candidate['option_key'];
      if (in_array($entryId, $links[$fileId][$key] ?? [], true)) {
        $candidates[$fileId][$key][$entryId][$referenceKey] = true;
      } elseif (!isset($brokenFiles[$fileId])
          && ($entries[$root]['type'] ?? null) === 'folder' && $entries[$root]['parent_id'] === null) {
        $missingSupportLinks[$fileId][$key][$entryId][$referenceKey] = true;
      }
    }
    $mandates = $this->repairableMandates($files, $entries, $links, $participantRoots, $rootParticipants);
    $financialDocuments = $this->repairableFinancialDocuments($files, $entries, $links, $participantRoots, $rootParticipants);
    $fieldLinks = [];
    foreach ($this->connection->fetchAllAssociative(
      "SELECT d.field_id, d.musician_id, d.project_id, d.option_value AS entry_id, HEX(d.option_key) AS option_key
       FROM ProjectParticipantFieldsData d
       JOIN ProjectParticipantFields f ON f.id = d.field_id AND f.project_id = d.project_id
       JOIN ProjectParticipants pp ON pp.musician_id = d.musician_id AND pp.project_id = d.project_id
       JOIN Projects p ON p.id = d.project_id
       JOIN Musicians m ON m.id = d.musician_id
       WHERE f.data_type = 'db-file' AND d.deleted IS NULL AND f.deleted IS NULL
         AND pp.deleted IS NULL AND p.deleted IS NULL AND m.deleted IS NULL",
    ) as $candidate) {
      $entryId = $candidate['entry_id'];
      if (!is_scalar($entryId) || !ctype_digit((string)$entryId)
          || (string)(int)$entryId !== ltrim((string)$entryId, '0') || (int)$entryId <= 0) {
        continue;
      }
      $entryId = (int)$entryId;
      $fileId = $entries[$entryId]['file_id'] ?? null;
      $key = $candidate['musician_id'] . ':' . $candidate['project_id'];
      $root = $participantRoots[$key] ?? null;
      if (($entries[$entryId]['type'] ?? null) !== 'file' || $fileId === null
          || ($files[$fileId]['type'] ?? null) !== 'encrypted' || isset($brokenFiles[$fileId])
          || $root === null || count($rootParticipants[$root] ?? []) !== 1
          || ($entries[$root]['type'] ?? null) !== 'folder' || $entries[$root]['parent_id'] !== null
          || in_array($entryId, $links[$fileId][$key] ?? [], true)) {
        continue;
      }
      $fieldLinks[$fileId][$key][$entryId][$candidate['field_id'] . ':' . $candidate['option_key']] = true;
    }
    foreach ($findings as &$finding) {
      $missingDocumentLink = $this->isRepairableMissingDocumentLink($finding, $financialDocuments, $brokenFiles);
      $missingFieldLink = $finding['code'] === 'missing-participant-link'
        && ($finding['source'] ?? null) === 'participant-field'
        && isset($fieldLinks[$finding['file_id']][$finding['musician_id'] . ':' . $finding['project_id']]
          [$finding['entry_id']][$finding['source_id'] . ':' . $finding['option_key']]);
      $missingSupportLink = in_array($finding['code'], ['missing-ownership', 'missing-participant-link'], true)
        && ($finding['source'] ?? null) === 'participant-field-support'
        && isset($missingSupportLinks[$finding['file_id']][$finding['musician_id'] . ':' . $finding['project_id']]
          [$finding['entry_id']][$finding['source_id'] . ':' . $finding['option_key']]);
      $finding['repairable'] = $this->isRepairableSupportFinding($finding, $candidates)
        || $this->isRepairableMandateFinding($finding, $mandates)
        || $this->isRepairableFinancialFinding($finding, $financialDocuments)
        || $missingDocumentLink || $missingFieldLink || $missingSupportLink;
      if ($finding['repairable']) {
        if ($missingFieldLink) {
          $finding['repairsteps'] = ['add-participant-link'];
        } elseif ($missingDocumentLink || $missingSupportLink) {
          $finding['repairsteps'] = ['add-participant-link'];
          if (!isset($owners[$finding['file_id']][$finding['musician_id']])) {
            $finding['repairsteps'][] = 'add-ownership';
            $finding['repairsteps'][] = 'reconcile-encryption-seal';
          }
        } else {
          $finding['repairsteps'] = ['add-ownership', 'reconcile-encryption-seal'];
        }
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
  private function repairableFinancialDocuments(
    array $files,
    array $entries,
    array $links,
    array $participantRoots,
    array $rootParticipants,
  ): array {
    $rows = $this->connection->fetchAllAssociative(
      "SELECT documents.source, documents.source_id, documents.musician_id, documents.project_id, documents.entry_id
       FROM (
         SELECT 'invoice' AS source, id AS source_id, debitor_id AS musician_id, project_id, written_invoice_id AS entry_id
         FROM Invoices WHERE deleted IS NULL AND written_invoice_id IS NOT NULL
         UNION ALL
         SELECT 'composite-payment', id, musician_id, project_id, supporting_document_id
         FROM CompositePayments WHERE supporting_document_id IS NOT NULL
         UNION ALL
         SELECT 'donation-receipt', receipt.id, payment.musician_id, payment.project_id, receipt.supporting_document_id
         FROM DonationReceipts receipt JOIN CompositePayments payment ON payment.id = receipt.donation_id
         WHERE receipt.deleted IS NULL AND receipt.supporting_document_id IS NOT NULL
         UNION ALL
         SELECT 'debit-mandate', sequence, musician_id, project_id, written_mandate_id
         FROM SepaDebitMandates WHERE written_mandate_id IS NOT NULL
       ) documents
       JOIN Musicians musician ON musician.id = documents.musician_id AND musician.deleted IS NULL
       JOIN Projects project ON project.id = documents.project_id AND project.deleted IS NULL
       JOIN ProjectParticipants participant ON participant.musician_id = documents.musician_id
         AND participant.project_id = documents.project_id AND participant.deleted IS NULL",
    );
    $candidates = [];
    foreach ($rows as $document) {
      $entryId = (int)$document['entry_id'];
      $fileId = $entries[$entryId]['file_id'] ?? null;
      $key = $document['musician_id'] . ':' . $document['project_id'];
      $root = $participantRoots[$key] ?? null;
      if (($entries[$entryId]['type'] ?? null) !== 'file' || $fileId === null
          || ($files[$fileId]['type'] ?? null) !== 'encrypted'
          || $root === null || count($rootParticipants[$root] ?? []) !== 1) {
        continue;
      }
      $referenceKey = $document['source'] . ':' . $document['source_id'] . ':' . $entryId;
      if (empty($links[$fileId][$key])) {
        $misplacedMandate = $document['source'] === 'debit-mandate' && array_filter(
          $links[$fileId] ?? [],
          static fn(array $entryIds) => in_array($entryId, $entryIds, true),
        ) !== [];
        if (in_array($document['source'], ['invoice', 'composite-payment', 'donation-receipt', 'debit-mandate'], true)
            && !$misplacedMandate
            && ($entries[$root]['type'] ?? null) === 'folder'
            && $entries[$root]['parent_id'] === null) {
          $candidates[$fileId][$key]['missing_links'][$referenceKey] = true;
        }
        continue;
      }
      if ($document['source'] === 'debit-mandate') {
        continue;
      }
      $candidates[$fileId][$key]['references'][$referenceKey] = true;
      foreach ($links[$fileId][$key] as $participantEntryId) {
        $candidates[$fileId][$key]['links'][$participantEntryId] = true;
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
  private function isRepairableFinancialFinding(array $finding, array $candidates): bool
  {
    if (!in_array($finding['code'], ['missing-ownership', 'non-owner-participant-link'], true)
        || !isset($finding['file_id'], $finding['musician_id'], $finding['project_id'], $finding['entry_id'])) {
      return false;
    }
    $key = $finding['musician_id'] . ':' . $finding['project_id'];
    $matches = $candidates[$finding['file_id']][$key] ?? [];
    if ($finding['code'] === 'missing-ownership') {
      $referenceKey = $finding['source'] . ':' . $finding['source_id'] . ':' . $finding['entry_id'];
      return isset($matches['references'][$referenceKey]);
    }
    return isset($matches['links'][$finding['entry_id']]);
  }

  /**
   * @param array $finding
   * @param array $candidates
   * @param array $brokenFiles
   *
   * @return bool
   */
  private function isRepairableMissingDocumentLink(array $finding, array $candidates, array $brokenFiles): bool
  {
    if (!in_array($finding['code'], ['missing-ownership', 'missing-participant-link'], true)
        || !in_array($finding['source'] ?? null, ['invoice', 'composite-payment', 'donation-receipt', 'debit-mandate'], true)
        || !isset($finding['file_id'], $finding['musician_id'], $finding['project_id'], $finding['entry_id'])
        || isset($brokenFiles[$finding['file_id']])) {
      return false;
    }
    $key = $finding['musician_id'] . ':' . $finding['project_id'];
    $referenceKey = $finding['source'] . ':' . $finding['source_id'] . ':' . $finding['entry_id'];
    return isset($candidates[$finding['file_id']][$key]['missing_links'][$referenceKey]);
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
       WHERE mandate.written_mandate_id IS NOT NULL
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
   * @param array $documentContexts
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
    array $documentContexts,
    array $links,
    array $participantRoots,
    array &$findings,
    array $clubMandates,
  ): void {
    foreach ($documentContexts as $fileId => $contexts) {
      foreach ($contexts as $key => $references) {
        foreach ($references as $context) {
          $fieldReference = in_array($context['source'], ['participant-field', 'participant-field-support'], true);
          if (!isset($links[$fileId][$key])
              || ($fieldReference && !in_array($context['entry_id'], $links[$fileId][$key], true))) {
            $findings[] = [
              'code' => 'missing-participant-link',
              'root_id' => isset($participantRoots[$key]) ? (int)$participantRoots[$key] : null,
            ] + $context;
          }
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
