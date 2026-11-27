<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Database\Doctrine\ORM\Entities;

use OCA\CAFEVDB\Database\Doctrine\ORM as CAFEVDB;
use OCA\CAFEVDB\PageRenderer\DatabaseTables;
use OCA\CAFEVDB\Wrapped\Carbon\CarbonImmutable;
use OCA\CAFEVDB\Wrapped\Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use OCA\CAFEVDB\Wrapped\Doctrine\ORM\Mapping as ORM;

/** Durable outbox entry for a post-commit file-cache update. */
#[ORM\Table(name: DatabaseTables::DATABASE_STORAGE_CACHE_CHANGES_TABLE)]
#[ORM\Index(name: 'IDX_STORAGE_CACHE_CHANGE_STORAGE', columns: ['storage_id', 'id'])]
#[ORM\Entity]
class DatabaseStorageCacheChange
{
  use CAFEVDB\Traits\AutoIncrementTrait;
  use CAFEVDB\Traits\CreatedAt;

  #[ORM\Column(type: 'datetime_immutable', insertable: false, updatable: false, generated: 'INSERT', options: ['default' => new CurrentTimestamp()])]
  protected ?CarbonImmutable $created = null;

  #[ORM\Column(type: 'string', length: 512, nullable: false)]
  protected string $storageId;

  #[ORM\Column(type: 'text', nullable: false)]
  protected string $path;

  #[ORM\Column(type: 'text', nullable: true)]
  protected ?string $oldPath = null;

  #[ORM\Column(type: 'string', length: 255, options: ['default' => 'unknown'])]
  protected string $createdBy;

  #[ORM\Column(type: 'integer', nullable: true)]
  protected ?int $entryId;

  /**
   * @param string $storageId
   * @param string $path
   * @param null|string $oldPath
   * @param null|int $entryId
   * @param null|string $createdBy
   */
  public function __construct(
    string $storageId,
    string $path,
    ?string $oldPath = null,
    ?int $entryId = null,
    ?string $createdBy = null,
  ) {
    $this->storageId = $storageId;
    $this->path = $path;
    $this->oldPath = $oldPath;
    $this->entryId = $entryId;
    $this->createdBy = $createdBy === null || $createdBy === '' ? 'unknown' : $createdBy;
  }

  /** @return string */
  public function getStorageId(): string
  {
    return $this->storageId;
  }

  /** @return string */
  public function getPath(): string
  {
    return $this->path;
  }

  /** @return null|string */
  public function getOldPath(): ?string
  {
    return $this->oldPath;
  }

  /** @return string */
  public function getCreatedBy(): string
  {
    return $this->createdBy;
  }

  /** @return null|int */
  public function getEntryId(): ?int
  {
    return $this->entryId;
  }
}
