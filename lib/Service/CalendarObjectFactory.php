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

use Sabre\VObject\Component\VCalendar;

use OCP\IL10N;
use OCP\IDateTimeZone;

/**
 * Build a VCalendar event from application event data.
 */
class CalendarObjectFactory
{
  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    private IL10N $l,
    private IDateTimeZone $dateTimeZone,
  ) {
  }
  // phpcs:enable

  private function getWeeklyOptions():array
  {
    return array(
      'MO' => (string)$this->l->t('Monday'),
      'TU' => (string)$this->l->t('Tuesday'),
      'WE' => (string)$this->l->t('Wednesday'),
      'TH' => (string)$this->l->t('Thursday'),
      'FR' => (string)$this->l->t('Friday'),
      'SA' => (string)$this->l->t('Saturday'),
      'SU' => (string)$this->l->t('Sunday')
    );
  }

  private function getByMonthOptions():array
  {
    return array(
      '1'  => (string)$this->l->t('January'),
      '2'  => (string)$this->l->t('February'),
      '3'  => (string)$this->l->t('March'),
      '4'  => (string)$this->l->t('April'),
      '5'  => (string)$this->l->t('May'),
      '6'  => (string)$this->l->t('June'),
      '7'  => (string)$this->l->t('July'),
      '8'  => (string)$this->l->t('August'),
      '9'  => (string)$this->l->t('September'),
      '10' => (string)$this->l->t('October'),
      '11' => (string)$this->l->t('November'),
      '12' => (string)$this->l->t('December')
    );
  }

  /** @param array<string, mixed> $request */
  public function createFromRequest(array $request):VCalendar
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
    return $this->populateVCalendarFromRequest($request, $vcalendar);
  }

  /**
   * @param array<string, mixed> $request
   */
  private function populateVCalendarFromRequest(array $request, VCalendar $vcalendar):VCalendar
  {
    $accessclass = $request['accessclass'] ?? null;
    $summary = $request['summary'];
    $location = $request['location'] ?? null;
    $categories = explode(',', $request['categories'] ?? []);
    $allday = $request['allDay'] ?? false;
    $from = $request['from'];
    $to  = $request['to'];
    if (!$allday) {
      $fromtime = $request['fromtime'];
      $totime = $request['totime'];
    }
    $vevent = $vcalendar->VEVENT;
    // $this->logInfo(get_class($vcalendar));
    $description = $request['description'] ?? null;
    $repeat = $request['repeat'] ?? 'doesnotrepeat';
    if ($repeat != 'doesnotrepeat') {
      $rrule = '';
      $interval = $request['interval'] ?? null;
      $end = $request['end'] ?? null;
      $byoccurrences = $request['byoccurrences'] ?? null;
      switch ($repeat) {
        case 'daily':
          $rrule .= 'FREQ=DAILY';
          break;
        case 'weekly':
          $rrule .= 'FREQ=WEEKLY';
          if (isset($request['weeklyoptions'])) {
            $byday = '';
            $daystrings = array_flip($this->getWeeklyOptions());
            foreach ($request['weeklyoptions'] as $days) {
              if ($byday == '') {
                $byday .= $daystrings[$days];
              } else {
                $byday .= ',' .$daystrings[$days];
              }
            }
            $rrule .= ';BYDAY=' . $byday;
          }
          break;
        case 'weekday':
          $rrule .= 'FREQ=WEEKLY';
          $rrule .= ';BYDAY=MO,TU,WE,TH,FR';
          break;
        case 'biweekly':
          $rrule .= 'FREQ=WEEKLY';
          $interval = $interval * 2;
          break;
        case 'monthly':
          $rrule .= 'FREQ=MONTHLY';
          if (($request['advanced_month_select'] ?? null) == 'monthday') {
            break;
          } elseif ($request['advanced_month_select'] == 'weekday') {
            if ($request['weekofmonthoptions'] == 'auto') {
              list($_day, $_month, $_year) = explode('-', $from);
              $weekofmonth = floor($_day/7);
            } else {
              $weekofmonth = $request['weekofmonthoptions'];
            }
            $days = array_flip($this->getWeeklyOptions());
            $byday = '';
            foreach ($request['weeklyoptions'] as $day) {
              if ($byday == '') {
                $byday .= $weekofmonth . $days[$day];
              } else {
                $byday .= ',' . $weekofmonth . $days[$day];
              }
            }
            if ($byday == '') {
              $byday = 'MO,TU,WE,TH,FR,SA,SU';
            }
            $rrule .= ';BYDAY=' . $byday;
          }
          break;
        case 'yearly':
          $rrule .= 'FREQ=YEARLY';
          switch ($request['advanced_year_select'] ?? null) {
            case 'bydate':
              list($_day, $_month, $_year) = explode('-', $from);
              $bymonth = date('n', mktime(0, 0, 0, $_month, $_day, $_year));
              $bymonthday = date('j', mktime(0, 0, 0, $_month, $_day, $_year));
              $rrule .= ';BYDAY=MO,TU,WE,TH,FR,SA,SU;BYMONTH=' . $bymonth . ';BYMONTHDAY=' . $bymonthday;
              break;
            case 'byyearday':
              list($_day, $_month, $_year) = explode('-', $from);
              $byyearday = date('z', mktime(0, 0, 0, $_month, $_day, $_year)) + 1;
              if (isset($request['byyearday'])) {
                foreach ($request['byyearday'] as $yearday) {
                  $byyearday .= ',' . $yearday;
                }
              }
              $rrule .= ';BYYEARDAY=' . $byyearday;
              break;
            case 'byweekno':
              list($_day, $_month, $_year) = explode('-', $from);
              $rrule .= ';BYDAY=' . strtoupper(substr(date('l', mktime(0, 0, 0, $_month, $_day, $_year)), 0, 2));
              $byweekno = '';
              foreach ($request['byweekno'] as $weekno) {
                if ($byweekno == '') {
                  $byweekno = $weekno;
                } else {
                  $byweekno .= ',' . $weekno;
                }
              }
              $rrule .= ';BYWEEKNO=' . $byweekno;
              break;
            case 'bydaymonth':
              if (isset($request['weeklyoptions'])) {
                $days = array_flip($this->getWeeklyOptions());
                $byday = '';
                foreach ($request['weeklyoptions'] as $day) {
                  if ($byday == '') {
                    $byday .= $days[$day];
                  } else {
                    $byday .= ',' . $days[$day];
                  }
                }
                $rrule .= ';BYDAY=' . $byday;
              }
              if (isset($request['bymonth'])) {
                $monthes = array_flip($this->getByMonthOptions());
                $bymonth = '';
                foreach ($request['bymonth'] as $month) {
                  if ($bymonth == '') {
                    $bymonth .= $monthes[$month];
                  } else {
                    $bymonth .= ',' . $monthes[$month];
                  }
                }
                $rrule .= ';BYMONTH=' . $bymonth;

              }
              if (isset($request['bymonthday'])) {
                $bymonthday = '';
                foreach ($request['bymonthday'] as $monthday) {
                  if ($bymonthday == '') {
                    $bymonthday .= $monthday;
                  } else {
                    $bymonthday .= ',' . $monthday;
                  }
                }
                $rrule .= ';BYMONTHDAY=' . $bymonthday;
              }
          }
          break;
        default:
          break;
      }
      if ($interval != '') {
        $rrule .= ';INTERVAL=' . $interval;
      }
      if ($end == 'count') {
        $rrule .= ';COUNT=' . $byoccurrences;
      }
      if ($end == 'date') {
        list($bydate_day, $bydate_month, $bydate_year) = explode('-', $request['bydate']);
        $rrule .= ';UNTIL=' . $bydate_year . $bydate_month . $bydate_day;
      }
      $vevent->RRULE = $rrule;
    }

    $now = new DateTime('now');
    $now->setTimeZone(new DateTimeZone('UTC'));
    $lastModified = $vevent->__get('LAST-MODIFIED');
    if (is_null($lastModified)) {
      $lastModified = $vevent->add('LAST-MODIFIED');
    }
    $lastModified->setValue($now);
    $vevent->DTSTAMP = $now;

    $vevent->SUMMARY = $summary;

    if ($allday) {
      $start = new DateTime($from);
      $end = new DateTime($to.' +1 day');

      $vevent->DTSTART = $start;
      $vevent->DTEND = $end;

      $vevent->DTSTART['VALUE'] = 'DATE';
      $vevent->DTEND['VALUE'] = 'DATE';
    } else {
      //$timezone = OC_Calendar_App::getTimezone();
      //$timezone = new DateTimeZone($timezone);
      $timezone = $this->dateTimeZone->getTimeZone();

      $start = new DateTime($from.' '.$fromtime, $timezone);
      $end = new DateTime($to.' '.$totime, $timezone);

      $vevent->DTSTART = $start;
      $vevent->DTEND = $end;
    }

    unset($vevent->DURATION);

    if ($accessclass !== null) {
      $vevent->CLASS = $accessclass;
    }
    $vevent->LOCATION = $location;
    $vevent->DESCRIPTION = $description;
    if (count($categories) > 0) {
      $vevent->CATEGORIES = $categories;
    } else {
      unset($vevent->CATEGORIES);
    }

    return $vcalendar;
  }
}
