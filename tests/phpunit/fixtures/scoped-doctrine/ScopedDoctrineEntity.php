<?php
/**
 * Entity fixture for the scoped Doctrine smoke check.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\CAFEVDB\Tests\ScopedDoctrine;

use OCA\CAFEVDB\Wrapped\Doctrine\ORM\Mapping as ORM;

/** Scoped Doctrine Entity. */
#[ORM\Entity]
#[ORM\Table(name: 'scoped_doctrine_smoke')]
class ScopedDoctrineEntity
{
  /** @SuppressWarnings(PHPMD.UnusedPrivateField) */
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column(type: 'integer')]
  private ?int $id = null;

  #[ORM\Column(type: 'string', length: 255)]
  private string $name;

  /** @param string $name */
  public function __construct(string $name)
  {
    $this->name = $name;
  }

  /** @return string */
  public function getName():string
  {
    return $this->name;
  }
}
