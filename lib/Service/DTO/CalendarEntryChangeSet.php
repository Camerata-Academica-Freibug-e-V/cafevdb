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

/**
 * Partial calendar update. Null leaves a property unchanged; empty strings,
 * alarm 0 or [], and related [] explicitly clear the respective properties.
 *
 * Consume properties directly: toArray() is serialization, not a lossless map.
 */
abstract class CalendarEntryChangeSet extends \OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\DTO\AbstractDTO
{
  /** {@inheritdoc} */
  public function __construct(
    public readonly ?string $summary = null,
    public readonly ?string $description = null,
    public readonly int|array|null $alarm = null,
    public readonly ?array $related = null,
  ) {
  }
}
