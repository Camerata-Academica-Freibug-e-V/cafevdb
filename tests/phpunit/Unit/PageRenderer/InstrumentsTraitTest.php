<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\CAFEVDB\Tests\Unit\PageRenderer;

use UnexpectedValueException;

use OCP\IL10N;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;

use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Database\Doctrine\ORM\Repositories\InstrumentsRepository;
use OCA\CAFEVDB\PageRenderer\FieldTraits\InstrumentsTrait;
use OCA\CAFEVDB\Wrapped\Doctrine\ORM\EntityRepository;

/** Test the role lookup shared by the page renderers. */
#[Attributes\CoversTrait(InstrumentsTrait::class)]
#[Attributes\UsesClass(Entities\Instrument::class)]
#[Attributes\UsesClass(Entities\InstrumentFamily::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\ArrayTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\AutoIncrementTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\EntityManagerTrait::class)]
class InstrumentsTraitTest extends TestCase
{
  /** @return void */
  public function testRolesAreIdentifiedIndependentlyOfDisplayLanguage(): void
  {
    $translatedFamily = $this->createStub(Entities\InstrumentFamily::class);
    $translatedFamily->method('getFamily')->willReturn('Translated role family');
    $translatedFamily->method('getUntranslatedFamily')->willReturn(Entities\ProjectInstrument::NOT_AN_INSTRUMENT_FAMILY);

    $originalFamily = $this->createStub(Entities\InstrumentFamily::class);
    $originalFamily->method('getFamily')->willReturn(Entities\ProjectInstrument::NOT_AN_INSTRUMENT_FAMILY);

    $instrumentFamily = $this->createStub(Entities\InstrumentFamily::class);
    $instrumentFamily->method('getFamily')->willReturn('Strings');

    $translatedRole = new Entities\Instrument();
    $translatedRole->setId(1)->setName('Role in another language');
    $translatedRole->getFamilies()->add($translatedFamily);
    $originalRole = new Entities\Instrument();
    $originalRole->setId(2)->setName('Role');
    $originalRole->getFamilies()->add($originalFamily);
    $violin = new Entities\Instrument();
    $violin->setId(3)->setName('Violin');
    $violin->getFamilies()->add($instrumentFamily);

    $repository = $this->createMock(InstrumentsRepository::class);
    $repository->expects($this->once())->method('findAll')->willReturn([$translatedRole, $originalRole, $violin]);
    $renderer = $this->renderer($repository);

    $expected = [1 => 'Role in another language', 2 => 'Role'];
    $this->assertSame($expected, $renderer->getNonInstruments());
    $this->assertSame($expected, $renderer->getNonInstruments());
  }

  /** @return void */
  public function testMissingRolesStillRaiseAnError(): void
  {
    $repository = $this->createStub(InstrumentsRepository::class);
    $repository->method('findAll')->willReturn([]);
    $renderer = $this->renderer($repository);

    $this->expectException(UnexpectedValueException::class);
    $renderer->getNonInstruments();
  }

  /**
   * @param InstrumentsRepository $repository
   *
   * @return object
   */
  private function renderer(InstrumentsRepository $repository): object
  {
    $l10n = $this->createStub(IL10N::class);
    $l10n->method('t')->willReturnCallback(fn(string $text) => $text);

    return new class($repository, $l10n) {
      use InstrumentsTrait {
        getNonInstruments as public;
      }

      /**
       * @param InstrumentsRepository $repository
       * @param IL10N $l
       */
      public function __construct(private InstrumentsRepository $repository, protected IL10N $l)
      {
      }

      /** {@inheritdoc} */
      protected function getDatabaseRepository(?string $entityClassName = null): EntityRepository
      {
        return $this->repository;
      }
    };
  }
}
