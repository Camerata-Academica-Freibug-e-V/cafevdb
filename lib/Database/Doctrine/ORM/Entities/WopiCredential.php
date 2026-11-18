<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Database\Doctrine\ORM\Entities;

use OCA\CAFEVDB\Wrapped\Doctrine\ORM\Mapping as ORM;

/**
 * An expiring, token-encrypted app key.
 *
 * @SuppressWarnings(PHPMD.UnusedPrivateField)
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE_NAME)]
#[ORM\Index(name: 'IDX_WOPI_CREDENTIAL_EXPIRY', columns: ['expires'])]
class WopiCredential
{
  public const TABLE_NAME = 'WopiCredentials';

  #[ORM\Id]
  #[ORM\Column(length: 64, options: ['fixed' => true, 'collation' => 'ascii_bin'])]
  private string $tokenHash;

  #[ORM\Column(type: 'text')]
  private string $encryptedKey;

  #[ORM\Column(type: 'bigint')]
  private int $expires;
}
