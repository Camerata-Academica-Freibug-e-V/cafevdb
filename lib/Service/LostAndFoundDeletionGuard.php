<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author GitHub Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Service;

use RuntimeException;

use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Connection;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Platforms\SQLitePlatform;
use OCA\CAFEVDB\Storage\Database\LostAndFoundStorage;

/** Identify references that make deletion of recovered entries unsafe. */
class LostAndFoundDeletionGuard
{
  private const REFERENCES = [
    'DatabaseStorages' => ['root_id'],
    'CompositePayments' => ['supporting_document_id', 'balance_documents_folder_id'],
    'DonationReceipts' => ['supporting_document_id'],
    'InvoiceItems' => ['balance_documents_folder_id'],
    'Invoices' => ['balance_documents_folder_id', 'written_invoice_id'],
    'ProjectPayments' => ['balance_documents_folder_id'],
    'ProjectParticipantFieldsData' => ['supporting_document_id'],
    'SepaBulkTransactionData' => ['database_storage_file_id'],
    'SepaBulkTransactionBalancingData' => ['database_storage_file_id'],
    'SepaDebitMandates' => ['written_mandate_id'],
    'TaxExemptionNotices' => ['written_notice_id'],
  ];

  /** @param Connection $connection */
  public function __construct(private Connection $connection)
  {
  }

  /**
   * Lock the relevant metadata and check the whole subtree in the caller's transaction.
   *
   * @param integer $entryId
   *
   * @return array{entries: array, blockers: array}
   */
  public function inspect(int $entryId): array
  {
    if (!$this->connection->isTransactionActive()) {
      throw new RuntimeException('Lost+found deletion requires a transaction.');
    }
    if (!($this->connection->getDatabasePlatform() instanceof SQLitePlatform)) {
      $tables = array_unique(array_merge(
        ['Files', 'DatabaseStorages', 'DatabaseStorageDirEntries'],
        array_keys(self::REFERENCES),
        ['ProjectParticipantFields'],
      ));
      foreach ($tables as $table) {
        $this->connection->fetchAllAssociative('SELECT * FROM ' . $table . ' FOR UPDATE');
      }
    }

    $rows = $this->connection->fetchAllAssociative('SELECT id, parent_id, file_id, type FROM DatabaseStorageDirEntries');
    $entries = array_column($rows, null, 'id');
    if (!isset($entries[$entryId])) {
      throw new RuntimeException('The recovered entry no longer exists.');
    }
    $rootId = $this->connection->fetchOne(
      'SELECT root_id FROM DatabaseStorages WHERE storage_id = ?',
      [LostAndFoundStorage::SHORT_ID],
    );
    $current = $entries[$entryId];
    $visited = [];
    while ($current['parent_id'] !== null && !isset($visited[(int)$current['id']])) {
      $visited[(int)$current['id']] = true;
      $current = $entries[(int)$current['parent_id']] ?? null;
      if ($current === null || $current['type'] !== 'folder') {
        throw new RuntimeException('The recovered entry has an invalid parent.');
      }
    }
    if ($rootId === false || $current['parent_id'] !== null
        || (int)$current['id'] !== (int)$rootId || $entryId === (int)$rootId) {
      throw new RuntimeException('The entry is not inside the lost+found storage.');
    }
    $children = [];
    foreach ($rows as $row) {
      if ($row['parent_id'] !== null) {
        $children[(int)$row['parent_id']][] = (int)$row['id'];
      }
    }
    $pending = [$entryId];
    $selected = [];
    $blockers = [];
    $ancestorIds = array_values(array_filter(
      array_keys($visited),
      static fn(int $id): bool => $id !== $entryId,
    ));
    foreach (array_chunk($ancestorIds, 500) as $ancestorIds) {
      $ancestorPlaceholders = implode(', ', array_fill(0, count($ancestorIds), '?'));
      foreach ($this->connection->fetchFirstColumn(
        'SELECT root_id FROM DatabaseStorages WHERE root_id IN (' . $ancestorPlaceholders . ')',
        $ancestorIds,
      ) as $id) {
        $blockers[] = ['entry_id' => (int)$id, 'reference' => 'DatabaseStorages.root_id'];
      }
    }
    while ($pending !== []) {
      $id = array_pop($pending);
      if (isset($selected[$id])) {
        throw new RuntimeException('The recovered directory tree contains a cycle.');
      }
      $entry = $entries[$id];
      $selected[$id] = $entry;
      if (!in_array($entry['type'], ['file', 'folder'], true)
          || ($entry['type'] === 'file' && ($entry['file_id'] === null || !empty($children[$id])))
          || ($entry['type'] === 'folder' && $entry['file_id'] !== null)) {
        $blockers[] = ['entry_id' => $id, 'reference' => 'invalid-directory-entry'];
      }
      array_push($pending, ...($children[$id] ?? []));
    }
    foreach (array_chunk(array_keys($selected), 500) as $ids) {
      $placeholders = implode(', ', array_fill(0, count($ids), '?'));
      foreach (self::REFERENCES as $table => $columns) {
        foreach ($columns as $column) {
          foreach ($this->connection->fetchFirstColumn(
            'SELECT ' . $column . ' FROM ' . $table . ' WHERE ' . $column . ' IN (' . $placeholders . ')',
            $ids,
          ) as $id) {
            $blockers[] = ['entry_id' => (int)$id, 'reference' => $table . '.' . $column];
          }
        }
      }
    }
    foreach ($this->connection->fetchFirstColumn(
      "SELECT d.option_value FROM ProjectParticipantFieldsData d
       JOIN ProjectParticipantFields f ON f.id = d.field_id WHERE f.data_type = 'db-file'",
    ) as $value) {
      if (is_scalar($value) && ctype_digit((string)$value)
          && (int)$value > 0 && isset($selected[(int)$value])) {
        $blockers[] = ['entry_id' => (int)$value, 'reference' => 'ProjectParticipantFieldsData.option_value'];
      }
    }
    foreach ($selected as $id => $entry) {
      if ($entry['type'] !== 'file' || $entry['file_id'] === null) {
        continue;
      }
      $file = $this->connection->fetchOne('SELECT type FROM Files WHERE id = ?', [$entry['file_id']]);
      if ($file !== 'encrypted') {
        $blockers[] = ['entry_id' => $id, 'reference' => 'invalid-encrypted-file'];
      }
    }
    return ['entries' => array_values($selected), 'blockers' => $blockers];
  }
}
