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
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Platforms\SQLitePlatform;

/** List orphan trees and explicitly prune only complete folder-only trees. */
class OrphanTreeService
{
  /** @param Connection $connection */
  public function __construct(private Connection $connection)
  {
  }

  /**
   * @param bool $includeNames
   * @param bool $pruneEmpty
   *
   * @return array
   */
  public function inspect(bool $includeNames = false, bool $pruneEmpty = false): array
  {
    if (!$pruneEmpty) {
      return $this->listing($includeNames);
    }
    if ($this->connection->isTransactionActive()) {
      throw new RuntimeException('Orphan tree pruning requires its own transaction.');
    }
    return $this->connection->transactional(function() use ($includeNames): array {
      if (!($this->connection->getDatabasePlatform() instanceof SQLitePlatform)) {
        foreach (['DatabaseStorages', 'DatabaseStorageDirEntries'] as $table) {
          $this->connection->fetchAllAssociative('SELECT * FROM ' . $table . ' FOR UPDATE');
        }
      }
      $before = $this->listing($includeNames);
      $pruned = [];
      $count = 0;
      foreach ($before['trees'] as $tree) {
        $entries = array_column($tree['entries'], null, 'id');
        foreach ($entries as $entry) {
          if ($entry['type'] !== 'folder' || $entry['file_id'] !== null) {
            continue 2;
          }
          foreach ($this->connection->fetchAllAssociative(
            'SELECT id FROM DatabaseStorageDirEntries WHERE parent_id = ?',
            [$entry['id']],
          ) as $child) {
            // A registered subtree was excluded from the listing; keep its ancestors.
            if (!isset($entries[(int)$child['id']])) {
              continue 3;
            }
          }
        }
        while ($entries !== []) {
          foreach ($entries as $id => $entry) {
            foreach ($entries as $child) {
              if ($child['parent_id'] === $id) {
                continue 2;
              }
            }
            if ($this->connection->delete('DatabaseStorageDirEntries', ['id' => $id]) !== 1) {
              throw new RuntimeException('An orphan folder could not be deleted.');
            }
            ++$count;
            unset($entries[$id]);
          }
        }
        $pruned[] = $tree['root_id'];
      }
      $report = $this->listing($includeNames);
      $report['summary'] += ['pruned_trees' => count($pruned), 'pruned_entries' => $count];
      $report['pruned_root_ids'] = $pruned;
      return $report;
    });
  }

  /**
   * @param bool $includeNames
   *
   * @return array
   */
  private function listing(bool $includeNames): array
  {
    $nameColumn = $includeNames ? ', d.name' : '';
    $entries = $this->connection->fetchAllAssociative(
      'WITH RECURSIVE orphan_entries AS (
         SELECT d.id AS root_id, d.id, d.parent_id, d.file_id, d.type' . $nameColumn . '
         FROM DatabaseStorageDirEntries d
         WHERE d.parent_id IS NULL
           AND NOT EXISTS (SELECT 1 FROM DatabaseStorages s WHERE s.root_id = d.id)
         UNION ALL
         SELECT tree.root_id, d.id, d.parent_id, d.file_id, d.type' . $nameColumn . '
         FROM DatabaseStorageDirEntries d JOIN orphan_entries tree ON d.parent_id = tree.id
         WHERE NOT EXISTS (SELECT 1 FROM DatabaseStorages s WHERE s.root_id = d.id)
       )
       SELECT * FROM orphan_entries ORDER BY root_id, id',
    );
    $trees = [];
    $byId = array_column($entries, null, 'id');
    foreach ($entries as $entry) {
      foreach (['root_id', 'id', 'parent_id', 'file_id'] as $column) {
        $entry[$column] = $entry[$column] === null ? null : (int)$entry[$column];
      }
      $rootId = $entry['root_id'];
      unset($entry['root_id']);
      if ($includeNames) {
        $components = [];
        $parent = $entry['parent_id'];
        while ($parent !== null) {
          array_unshift($components, $byId[$parent]['name']);
          $parent = $byId[$parent]['parent_id'];
        }
        $entry['parent_path'] = '/' . implode('/', array_filter($components, static fn(string $name) => $name !== ''));
      }
      $trees[$rootId] ??= ['root_id' => $rootId, 'entries' => []];
      $trees[$rootId]['entries'][] = $entry;
    }
    if ($includeNames) {
      foreach ($trees as &$tree) {
        usort($tree['entries'], static fn(array $a, array $b) =>
          strcmp($a['parent_path'], $b['parent_path'])
          ?: strcmp($a['name'], $b['name'])
          ?: $a['id'] <=> $b['id']);
      }
      unset($tree);
      uasort($trees, static fn(array $a, array $b) =>
        strcmp($a['entries'][0]['name'], $b['entries'][0]['name']) ?: $a['root_id'] <=> $b['root_id']);
    }
    return ['summary' => ['trees' => count($trees), 'entries' => count($entries)], 'trees' => array_values($trees)];
  }
}
