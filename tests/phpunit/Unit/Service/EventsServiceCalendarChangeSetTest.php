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

use Closure;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IUserSession;

use OCA\CAFEVDB\Common\TimeFactory;
use OCA\CAFEVDB\Common\UndoableRunQueue;
use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Service\CalendarObjectFactory;
use OCA\CAFEVDB\Service\CalDavService;
use OCA\CAFEVDB\Service\ConfigService;
use OCA\CAFEVDB\Service\DTO\CalendarEntryChangeSet;
use OCA\CAFEVDB\Service\DTO\CalendarEventChangeSet;
use OCA\CAFEVDB\Service\DTO\CalendarTaskChangeSet;
use OCA\CAFEVDB\Service\EventsService;
use OCA\CAFEVDB\Service\Finance\FinanceService;
use OCA\CAFEVDB\Service\Finance\SepaBulkTransactionService;
use OCA\CAFEVDB\Service\Finance\SepaTransliterator;
use OCA\CAFEVDB\Service\ProjectService;
use OCA\CAFEVDB\Service\VCalendarService;

/** Calendar updates use typed, lossless changes rather than serialized DTOs. */
#[Attributes\CoversClass(EventsService::class)]
#[Attributes\CoversClass(SepaBulkTransactionService::class)]
#[Attributes\UsesClass(CalendarEntryChangeSet::class)]
#[Attributes\UsesClass(CalendarEventChangeSet::class)]
#[Attributes\UsesClass(CalendarTaskChangeSet::class)]
#[Attributes\UsesClass(VCalendarService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\AbstractUndoable::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\GenericUndoable::class)]
#[Attributes\UsesClass(UndoableRunQueue::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\Util::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\DTO\AbstractDTO::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\ConfigTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\EntityManagerTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\DateTimeTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\LoggerTrait::class)]
class EventsServiceCalendarChangeSetTest extends TestCase
{
  private EventsService $eventsService;
  private CalDavService $calDavService;
  private ConfigService $configService;
  private EntityManager $entityManager;
  private TimeFactory $timeFactory;
  private IL10N $l;
  private ?VCalendar $writtenCalendar = null;

  /** {@inheritdoc} */
  protected function setUp(): void
  {
    $this->l = $this->createStub(IL10N::class);
    $this->configService = $this->createStub(ConfigService::class);
    $this->configService->method('getL10n')->willReturn($this->l);
    $this->configService->method('getDateTimeZone')->willReturn(new DateTimeZone('Europe/Berlin'));
    $this->configService->method('logger')->willReturn(new NullLogger);
    $this->entityManager = $this->createStub(EntityManager::class);
    $this->calDavService = $this->createMock(CalDavService::class);
    $this->timeFactory = $this->createStub(TimeFactory::class);
    $this->timeFactory->method('getDateTimeImmutable')->willReturn(new DateTimeImmutable('2026-01-02T03:04:05Z'));
    $this->eventsService = new EventsService(
      userSession: $this->createStub(IUserSession::class),
      configService: $this->configService,
      entityManager: $this->entityManager,
      calDavService: $this->calDavService,
      dateTimeFormatter: $this->createStub(IDateTimeFormatter::class),
      projectService: $this->createStub(ProjectService::class),
      timeFactory: $this->timeFactory,
      vCalendarService: new VCalendarService(
        $this->configService,
        $this->createStub(CalendarObjectFactory::class),
      ),
    );
  }

  /**
   * @param string $type Calendar component type.
   *
   * @return array Calendar object including an alarm and a relation.
   */
  private function calendarObject(string $type = 'VEVENT'): array
  {
    $calendar = new VCalendar;
    $component = $calendar->add($type, [
      'UID' => 'entry-uid',
      'DTSTART' => new DateTimeImmutable('2025-11-01T10:00:00Z'),
      'DTEND' => new DateTimeImmutable('2025-11-01T11:00:00Z'),
      'SUMMARY' => 'Original summary',
      'DESCRIPTION' => 'Original description',
      'RELATED-TO' => 'previous-uid',
    ]);
    $component->add('VALARM', [
      'ACTION' => 'DISPLAY',
      'TRIGGER' => '-PT15M',
      'DESCRIPTION' => 'Original alarm',
    ]);
    return ['calendarid' => 7, 'uri' => 'entry.ics', 'calendardata' => $calendar];
  }

  /** @return void */
  private function expectCalendarWrite(): void
  {
    $this->calDavService->expects($this->once())->method('updateCalendarObject')
      ->with(7, 'entry.ics', $this->isInstanceOf(VCalendar::class))
      ->willReturnCallback(function(int $calendarId, string $uri, VCalendar $calendar): void {
        $this->writtenCalendar = Reader::read($calendar->serialize());
      });
  }

  /** @return void */
  public function testAllDayDatesAreWrittenWithoutMutatingSharedInput(): void
  {
    $date = new DateTime('2025-11-05T13:14:15+01:00');
    $changeSet = new CalendarEventChangeSet(start: $date, end: $date, allDay: true);
    $this->expectCalendarWrite();

    $this->eventsService->updateCalendarEvent($this->calendarObject(), $changeSet);

    $event = $this->writtenCalendar->VEVENT;
    $this->assertSame('20251105', (string)$event->DTSTART);
    $this->assertSame('20251106', (string)$event->DTEND);
    $this->assertSame('DATE', (string)$event->DTSTART['VALUE']);
    $this->assertSame('DATE', (string)$event->DTEND['VALUE']);
    $this->assertSame('2025-11-05T13:14:15+01:00', $date->format(DateTimeInterface::ATOM));
    $this->assertSame('13:14:15', $changeSet->end->format('H:i:s'));
  }

  /** @return void */
  public function testTimedUpdateOverridesAllDayWithoutChangingOmittedEnd(): void
  {
    $object = $this->calendarObject();
    $object['calendardata']->VEVENT->DTSTART['VALUE'] = 'DATE';
    $originalEnd = (string)$object['calendardata']->VEVENT->DTEND;
    $start = new DateTimeImmutable('2025-11-01T10:14:15+01:00');
    $this->expectCalendarWrite();

    $this->eventsService->updateCalendarEntry($object, new CalendarEventChangeSet(start: $start, allDay: false));

    $event = $this->writtenCalendar->VEVENT;
    $this->assertSame($start->getTimestamp(), $event->DTSTART->getDateTime()->getTimestamp());
    $this->assertSame('DATE-TIME', (string)$event->DTSTART['VALUE']);
    $this->assertSame($originalEnd, (string)$event->DTEND);
    $this->assertSame('Original description', (string)$event->DESCRIPTION);
    $this->assertCount(1, $event->VALARM);
    $this->assertSame('previous-uid', (string)$event->{'RELATED-TO'});
  }

  /** @return array<string, array{string, class-string<CalendarEntryChangeSet>}> */
  public static function componentTypes(): array
  {
    return [
      'event' => ['VEVENT', CalendarEventChangeSet::class],
      'task' => ['VTODO', CalendarTaskChangeSet::class],
    ];
  }

  /**
   * @param string $type Calendar component type.
   *
   * @param string $dtoClass Matching change-set class.
   *
   * @return void
   */
  #[Attributes\DataProvider('componentTypes')]
  public function testExplicitEmptyValuesClearSharedProperties(string $type, string $dtoClass): void
  {
    $this->expectCalendarWrite();
    $this->eventsService->updateCalendarEntry($this->calendarObject($type), new $dtoClass(
      summary: '',
      description: '',
      alarm: 0,
      related: [],
    ));

    $component = $this->writtenCalendar->{$type};
    $this->assertSame('', (string)$component->SUMMARY);
    $this->assertSame('', (string)$component->DESCRIPTION);
    $this->assertFalse(isset($component->VALARM));
    $this->assertFalse(isset($component->{'RELATED-TO'}));
    $this->assertSame('20260102T030405Z', (string)$component->{'LAST-MODIFIED'});
    $this->assertSame('20260102T030405Z', (string)$component->DTSTAMP);
  }

  /**
   * @param string $type Calendar component type.
   *
   * @param string $dtoClass Matching change-set class.
   *
   * @return void
   */
  #[Attributes\DataProvider('componentTypes')]
  public function testSharedChangesPreserveOmittedFields(string $type, string $dtoClass): void
  {
    $this->expectCalendarWrite();
    $this->eventsService->updateCalendarEntry($this->calendarObject($type), new $dtoClass(
      alarm: [-3600],
      related: ['SIBLING' => ['new-uid']],
    ));

    $component = $this->writtenCalendar->{$type};
    $this->assertSame('Original summary', (string)$component->SUMMARY);
    $this->assertSame('Original description', (string)$component->DESCRIPTION);
    $this->assertCount(1, $component->VALARM);
    $this->assertSame('-PT1H', (string)$component->VALARM->TRIGGER);
    $this->assertSame('Original summary', (string)$component->VALARM->DESCRIPTION);
    $this->assertSame('new-uid', (string)$component->{'RELATED-TO'});
    $this->assertSame('SIBLING', (string)$component->{'RELATED-TO'}['RELTYPE']);
  }

  /**
   * @param string $type Calendar component type.
   *
   * @param string $dtoClass Matching change-set class.
   *
   * @return void
   */
  #[Attributes\DataProvider('componentTypes')]
  public function testMismatchedChangesAreRejectedBeforeWriting(string $type, string $dtoClass): void
  {
    $this->calDavService->expects($this->never())->method('updateCalendarObject');
    $changeSet = $type == 'VEVENT' ? new CalendarTaskChangeSet : new CalendarEventChangeSet;
    $this->expectException(InvalidArgumentException::class);
    $this->eventsService->updateCalendarEntry($this->calendarObject($type), $changeSet);
  }

  /** @return void */
  public function testWriteBackWithoutChangeSetPreservesCalendar(): void
  {
    $object = $this->calendarObject();
    $original = $object['calendardata']->serialize();
    $this->expectCalendarWrite();

    $this->eventsService->updateCalendarEntry($object);

    $this->assertSame($original, $this->writtenCalendar->serialize());
  }

  /** @return void */
  public function testSubmissionDataSuppliesFinanceSpecificDefaults(): void
  {
    $this->calDavService->expects($this->never())->method('updateCalendarObject');
    $deadline = new DateTimeImmutable('2025-11-05');
    $financeService = $this->createStub(FinanceService::class);
    $financeService->method('targetDeadline')->willReturn($deadline);
    $transaction = $this->createStub(Entities\SepaBankTransfer::class);
    $transaction->method('getDueDate')->willReturn(new DateTimeImmutable('2025-11-06'));
    $transaction->method('getProject')->willReturn($this->createStub(Entities\Project::class));
    $service = new SepaBulkTransactionService(
      configService: $this->configService,
      eventsService: $this->eventsService,
      financeService: $financeService,
      dateTimeFormatter: $this->createStub(IDateTimeFormatter::class),
      sepaTransliterator: $this->createStub(SepaTransliterator::class),
      timeFactory: $this->timeFactory,
      appContainer: $this->createStub(ContainerInterface::class),
      entityManager: $this->entityManager,
      l: $this->l,
      logger: new NullLogger,
    );

    $changeSet = $service->createSubmissionEventData($transaction);

    $this->assertEquals($deadline, $changeSet->start);
    $this->assertEquals($deadline, $changeSet->end);
    $this->assertTrue($changeSet->allDay);
    $this->assertNotEmpty($changeSet->alarm);
  }

  /** @return array<string, array{bool}> */
  public static function submissionStates(): array
  {
    return ['submitted' => [false], 'reset' => [true]];
  }

  /**
   * @param bool $reset Whether to reset the submitted state.
   *
   * @return void
   */
  #[Attributes\DataProvider('submissionStates')]
  public function testDeferredSubmissionUpdatePreservesDateObjects(bool $reset): void
  {
    $date = new DateTimeImmutable('2025-11-05');
    $changeSet = new CalendarEventChangeSet(
      summary: 'Submission status',
      start: $date,
      end: $date,
      allDay: true,
      alarm: 0,
    );
    $container = $this->createStub(ContainerInterface::class);
    $queue = new UndoableRunQueue($container, new NullLogger, $this->l);
    $entityManager = $this->createMock(EntityManager::class);
    $entityManager->expects($this->once())->method('registerPreCommitAction')
      ->willReturnCallback(function(Closure $action, Closure $undo) use ($queue): UndoableRunQueue {
        return $queue->register($action, $undo);
      });
    $transaction = $this->createStub(Entities\SepaBankTransfer::class);
    $transaction->method('getSubmissionTaskUri')->willReturn('task.ics');
    $transaction->method('getSubmissionEventUri')->willReturn('entry.ics');
    $financeService = $this->createStub(FinanceService::class);
    $financeService->method('findFinanceCalendarEntry')->willReturnMap([
      ['task.ics', null],
      ['entry.ics', $this->calendarObject()],
    ]);
    $service = $this->getMockBuilder(SepaBulkTransactionService::class)
      ->setConstructorArgs([
        $this->configService,
        $this->eventsService,
        $financeService,
        $this->createStub(IDateTimeFormatter::class),
        $this->createStub(SepaTransliterator::class),
        $this->timeFactory,
        $container,
        $entityManager,
        $this->l,
        new NullLogger,
      ])
      ->onlyMethods(['createSubmissionEventData'])
      ->getMock();
    $service->expects($this->once())->method('createSubmissionEventData')->willReturn($changeSet);
    $this->expectCalendarWrite();

    $service->markBulkTransactionSubmitted($transaction, $reset ? null : $date);
    $this->assertNull($this->writtenCalendar);
    $queue->executeActions();

    $this->assertSame('20251105', (string)$this->writtenCalendar->VEVENT->DTSTART);
    $this->assertSame('20251106', (string)$this->writtenCalendar->VEVENT->DTEND);
    $this->assertSame('Submission status', (string)$this->writtenCalendar->VEVENT->SUMMARY);
    $this->assertFalse(isset($this->writtenCalendar->VEVENT->VALARM));
  }
}
