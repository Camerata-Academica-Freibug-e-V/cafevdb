<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
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

/*
 * Copyright (c) 2020, 2021, 2024-2026 Claus-Justus Heine <himself@claus-justus-heine.de>
 * Copyright (c) 2011 Jakob Sack <mail@jakobsack.de>
 * Copyright (c) 2012 Bart Visscher <bartv@thisnet.nl>
 * Copyright (c) 2012 Georg Ehrke <ownclouddev at georgswebsite dot de>
 */

namespace OCA\CAFEVDB\Service;

use DateTime;
use DateTimeZone;
use InvalidArgumentException;

use Sabre\VObject\Component\VCalendar;

use OCP\IDateTimeZone;

use OCA\CAFEVDB\Service\DTO\CalendarEventData;

/**
 * Build a VCalendar event from application event data.
 */
class CalendarObjectFactory
{
  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    private IDateTimeZone $dateTimeZone,
  ) {
  }
  // phpcs:enable

  public function create(CalendarEventData $eventData):VCalendar
  {
    $vcalendar = new VCalendar();
    $vcalendar->PRODID = 'ownCloud Calendar';
    $vcalendar->VERSION = '2.0';

    $vevent = $vcalendar->createComponent('VEVENT');
    $vcalendar->add($vevent);

    $now = new DateTime('now');
    $now->setTimeZone(new DateTimeZone('UTC'));
    $vevent->CREATED = $now;

    // $uid = substr(md5(rand().time()), 0, 10);
    $uid = \Sabre\VObject\UUIDUtil::getUUID();

    $vevent->UID = $uid;
    return $this->populateVCalendar($eventData, $vcalendar);
  }

  private function populateVCalendar(CalendarEventData $eventData, VCalendar $vcalendar):VCalendar
  {
    $vevent = $vcalendar->VEVENT;

    $now = new DateTime('now');
    $now->setTimeZone(new DateTimeZone('UTC'));
    $lastModified = $vevent->__get('LAST-MODIFIED');
    if (is_null($lastModified)) {
      $lastModified = $vevent->add('LAST-MODIFIED');
    }
    $lastModified->setValue($now);
    $vevent->DTSTAMP = $now;

    $vevent->SUMMARY = $eventData->summary;

    if ($eventData->allDay) {
      $start = new DateTime($eventData->fromDate);
      $end = new DateTime($eventData->toDate . ' +1 day');

      $vevent->DTSTART = $start;
      $vevent->DTEND = $end;

      $vevent->DTSTART['VALUE'] = 'DATE';
      $vevent->DTEND['VALUE'] = 'DATE';
    } else {
      if ($eventData->fromTime === null || $eventData->toTime === null) {
        throw new InvalidArgumentException('Timed calendar events require start and end times.');
      }
      $timezone = $this->dateTimeZone->getTimeZone();

      $start = new DateTime($eventData->fromDate . ' ' . $eventData->fromTime, $timezone);
      $end = new DateTime($eventData->toDate . ' ' . $eventData->toTime, $timezone);

      $vevent->DTSTART = $start;
      $vevent->DTEND = $end;
    }

    unset($vevent->DURATION);

    if ($eventData->accessClass !== null) {
      $vevent->CLASS = $eventData->accessClass;
    }
    $vevent->LOCATION = $eventData->location;
    $vevent->DESCRIPTION = $eventData->description;
    if (count($eventData->categories) > 0) {
      $vevent->CATEGORIES = $eventData->categories;
    } else {
      unset($vevent->CATEGORIES);
    }

    return $vcalendar;
  }
}
