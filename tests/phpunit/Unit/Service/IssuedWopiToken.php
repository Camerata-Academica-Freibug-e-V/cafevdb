<?php
/**
 * WOPI record fixture for token issuance tests.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Service;

/** Issued token with configurable identity and fixed file and expiry. */
class IssuedWopiToken
{
  public const TOKEN_TYPE_USER = 0;
  public int $type = self::TOKEN_TYPE_USER;
  public string $editor = 'alice';
  public string $fileUser = 'alice';

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
  public function getFileid(): int
  {
    return 42;
  }

  /** @return integer */
  public function getExpiry(): int
  {
    return 2000000000;
  }
}
