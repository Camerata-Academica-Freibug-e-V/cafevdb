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

namespace OCA\CAFEVDB\Tests\Unit\Service\DTO;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;

use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;

use OCA\CAFEVDB\Service\DTO\CalendarEntryChangeSet;
use OCA\CAFEVDB\Service\DTO\CalendarEventChangeSet;

/** Verify the distinction between calendar values and serialized dates. */
#[Attributes\CoversClass(CalendarEntryChangeSet::class)]
#[Attributes\CoversClass(CalendarEventChangeSet::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\DTO\AbstractDTO::class)]
class CalendarEventChangeSetTest extends TestCase
{
  /** @return void */
  public function testDatesAreSnapshotsButRemainSerializable(): void
  {
    $start = new DateTime('2025-11-05T13:14:15+01:00');
    $changeSet = new CalendarEventChangeSet(summary: 'Test event', start: $start, end: $start);
    $start->modify('+1 day');

    $this->assertInstanceOf(DateTimeImmutable::class, $changeSet->start);
    $this->assertSame('2025-11-05T13:14:15+01:00', $changeSet->start->format(DateTime::ATOM));
    $this->assertEquals($changeSet->start, $changeSet->end);
    $data = $changeSet->toArray();
    $this->assertSame('2025-11-05T13:14:15+01:00', $data['start']);
    $this->assertSame($data, json_decode(json_encode($changeSet, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    $this->assertSame('Test event', $data['summary']);
  }

  /** @return void */
  public function testStartOnlyUpdateDoesNotSupplyEndOrAllDay(): void
  {
    $changeSet = new CalendarEventChangeSet(start: new DateTimeImmutable('2025-11-05'));
    $this->assertNull($changeSet->end);
    $this->assertNull($changeSet->allDay);
    $this->assertNull($changeSet->alarm);
  }

  /** @return void */
  public function testUnknownClassificationIsRejected(): void
  {
    $this->expectException(InvalidArgumentException::class);
    new CalendarEventChangeSet(classification: 'UNKNOWN');
  }
}
