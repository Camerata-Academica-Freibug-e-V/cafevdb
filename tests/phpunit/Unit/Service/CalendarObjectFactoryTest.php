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

namespace OCA\CAFEVDB\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes;

use OCP\IDateTimeZone;

use OCA\CAFEVDB\Service\CalendarObjectFactory;
use OCA\CAFEVDB\Service\DTO\CalendarEventData;

/** Calendar Object Factory Test. */
#[Attributes\CoversClass(CalendarObjectFactory::class)]
#[Attributes\UsesClass(CalendarEventData::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\DTO\AbstractDTO::class)]
class CalendarObjectFactoryTest extends TestCase
{
  /** @return void */
  public function testCreatesNonRecurringAllDayEventFromTypedData():void
  {
    $factory = new CalendarObjectFactory($this->createStub(IDateTimeZone::class));
    $calendar = $factory->create(new CalendarEventData(
      summary: 'Planning',
      fromDate: '15-03-2026',
      toDate: '16-03-2026',
      allDay: true,
      categories: ['Concert', 'Project'],
    ));

    $event = $calendar->VEVENT;
    $this->assertSame('Planning', (string)$event->SUMMARY);
    $this->assertSame('Concert,Project', (string)$event->CATEGORIES);
    $this->assertFalse(isset($event->RRULE));
    $this->assertSame('17-03-2026', $event->DTEND->getDateTime()->format('d-m-Y'));
  }
}
