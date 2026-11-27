<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author GitHub Copilot <223556219+Copilot@users.noreply.github.com>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Storage\Database;

use OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DatabaseStorageFolder;

/** Read-only inspection of recovered directory trees. */
class LostAndFoundStorage extends Storage
{
  public const SHORT_ID = 'lost+found/';
  public const FOLDER_NAME = 'lost+found';

  /** {@inheritdoc} */
  public function __construct($params)
  {
    parent::__construct($params);
    $this->getRootFolder();
  }

  /** {@inheritdoc} */
  public function getShortId():string
  {
    return self::SHORT_ID;
  }

  /** @return DatabaseStorageFolder */
  public function getCollectionRoot():DatabaseStorageFolder
  {
    $this->rootFolder = null;
    $this->storageEntity = null;
    return $this->getRootFolder(create: true);
  }
}
