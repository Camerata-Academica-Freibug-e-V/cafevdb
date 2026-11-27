<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\Database\Doctrine\ORM\Entities;

use LogicException;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;

use OCA\CAFEVDB\Database\Doctrine\ORM\Entities\InsuranceBroker;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities\InsuranceRate;

/** Test the insurance broker's short-name invariant. */
#[Attributes\CoversClass(InsuranceBroker::class)]
#[Attributes\UsesClass(InsuranceRate::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\ArrayTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\FactoryTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\DateTimeTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\DateTimeTrait::class)]
class InsuranceBrokerTest extends TestCase
{
  /** @return void */
  public function testShortNameCanChangeWhenUnused(): void
  {
    $broker = new InsuranceBroker();
    $broker->setShortName('first')->setShortName('second');
    $rate = new InsuranceRate();
    $broker->getInsuranceRates()->add($rate);
    $this->assertSame($broker, $broker->setShortName('second'));
    $broker->getInsuranceRates()->removeElement($rate);
    $broker->setShortName('third');
    $this->assertSame('third', $broker->getShortName());
  }

  /** @return void */
  public function testShortNameCannotChangeWhenUsed(): void
  {
    $broker = new InsuranceBroker()->setShortName('first');
    $broker->getInsuranceRates()->add(new InsuranceRate());

    try {
      $broker->setShortName('second');
      $this->fail('Changing an in-use short name should fail.');
    } catch (LogicException) {
      $this->assertSame('first', $broker->getShortName());
    }
  }
}
