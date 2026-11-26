<?php
/**
 * WOPI record fixture for mount authentication tests.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

/** Configurable WOPI identity and expiry. */
class MountTestWopi
{
  public const TOKEN_TYPE_USER = 0;

  /**
   * @param integer $type
   * @param string $editor
   * @param string $fileUser
   * @param integer $expiry
   */
  public function __construct(
    private int $type = self::TOKEN_TYPE_USER,
    private string $editor = 'alice',
    private string $fileUser = 'alice',
    private int $expiry = 2000000000,
  ) {
  }

  /** @return integer */
  public function getTokenType(): int
  {
    return $this->type;
  }

  /** @return string */
  public function getEditorUid(): string
  {
    return $this->editor;
  }

  /** @return string */
  public function getUserForFileAccess(): string
  {
    return $this->fileUser;
  }

  /** @return integer */
  public function getExpiry(): int
  {
    return $this->expiry;
  }
}
