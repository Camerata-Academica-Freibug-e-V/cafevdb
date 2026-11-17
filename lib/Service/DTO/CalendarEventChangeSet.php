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

namespace OCA\CAFEVDB\Service\DTO;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Partial VEVENT update. All-day end dates are inclusive here and converted to
 * exclusive DTEND values when written. AllDay controls supplied date values.
 */
class CalendarEventChangeSet extends CalendarEntryChangeSet
{
  public readonly ?DateTimeImmutable $start;
  public readonly ?DateTimeImmutable $end;

  /** {@inheritdoc} */
  public function __construct(
    ?string $summary = null,
    ?string $description = null,
    int|array|null $alarm = null,
    ?array $related = null,
    ?DateTimeInterface $start = null,
    ?DateTimeInterface $end = null,
    public readonly ?bool $allDay = null,
    public readonly ?string $classification = null,
  ) {
    if ($classification !== null && !in_array($classification, ['PUBLIC', 'PRIVATE', 'CONFIDENTIAL'], true)) {
      throw new InvalidArgumentException('Unsupported calendar event classification: ' . $classification);
    }
    parent::__construct(
      summary: $summary,
      description: $description,
      alarm: $alarm,
      related: $related,
    );
    $this->start = $start === null ? null : DateTimeImmutable::createFromInterface($start);
    $this->end = $end === null ? null : DateTimeImmutable::createFromInterface($end);
  }
}
