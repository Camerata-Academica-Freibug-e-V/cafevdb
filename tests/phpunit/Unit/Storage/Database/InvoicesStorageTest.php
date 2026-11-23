<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine <himself@claus-justus-heine.de>
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

namespace OCA\CAFEVDB\Tests\Unit;

use DateTimeImmutable;
use ReflectionMethod;
use RuntimeException;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\MockObject\MockObject;

use OCA\CAFEVDB\Common\TimeFactory;
use OCA\CAFEVDB\Controller\DocumentStorageUploadController;
use OCA\CAFEVDB\Controller\EnumAddDocumentConflictAction;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Exceptions\DatabaseEntityExistsException;
use OCA\CAFEVDB\Storage\Database as DatabaseStorage;
use OCA\CAFEVDB\Tests\MockProvider;

/** Test aspects of the invoices storage. */
#[Attributes\CoversClass(DatabaseStorage\InvoicesStorage::class)]
#[Attributes\CoversClass(DatabaseStorage\DonationReceiptsStorage::class)]
#[Attributes\CoversClass(DatabaseStorage\DebitMandatesStorage::class)]
#[Attributes\CoversMethod(DocumentStorageUploadController::class, 'addDocument')]
#[Attributes\CoversMethod(DatabaseStorage\ProjectParticipantsStorage::class, 'synchronizeFinancialDocument')]
#[Attributes\UsesClass(DocumentStorageUploadController::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\CompositePayment::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DatabaseStorage::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DatabaseStorageDirEntry::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DatabaseStorageFile::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DatabaseStorageFolder::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\DonationReceipt::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\EncryptedFile::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\EncryptedFileData::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\File::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\FileData::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\SepaDebitMandate::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Storage\Database\ProjectParticipantsStorage::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Storage\Database\DatabaseStorageNodeNameTrait::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\AbstractUndoable::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\ConsoleLogger::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\GenericUndoable::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\PHPMailer::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\TimeFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\Transliterator::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\UndoableRunQueue::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\Util::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Common\Uuid::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\HaliteCryptoFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\HaliteSymmetricStreamCryptor::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\SealCryptor::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\SealService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Connection::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\DBAL\Logging\CloudLogger::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\DeprecationLogger::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\Util::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\Migrations\AbstractMigration::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\Migrations\AbstractTransactionalMigration::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\Migrations\DependencyFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\Instrument::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\InstrumentFamily::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\Invoice::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\LogEntry::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\Musician::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\MusicianEmailAddress::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\Project::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\ProjectParticipant::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\SepaBankAccount::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\DoctrineMigrationsListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\GedmoLoggableListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\GedmoSluggableListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\GedmoTranslatableListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\Sluggable\HashHandler::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\Sluggable\AssociationSlugHandler::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\Sluggable\InvoiceNumberHandler::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\Sluggable\LoginNameSlugHandler::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\Transformable\Encryption::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Mapping\ClassMetadataDecorator::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Mapping\ReservedWordQuoteStrategy::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Repositories\EntityRepository::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Repositories\ProjectsRepository::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Repositories\RepositoryFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\EntityManager::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Events\EncryptionServiceBound::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Events\EntityManagerBoundEvent::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Events\EntityManagerClosedEvent::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Listener\DatabaseStorageFileEntityListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Events\MusicianEmailEvent::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\CalendarObjectFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\DTO\CalendarEventData::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Listener\ContactsCardEventListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Listener\MusicianEmailAddressEntityListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Listener\MusicianEntityListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Listener\ProjectEntityListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Listener\ProjectParticipantEntityListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version19700101000001::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version19700101000002::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version19700101000003::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260108084800::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260108115432::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260130130553::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260131090857::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260206193722::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260207000624::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260819094146::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260819094422::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20260819105948::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20261009150233::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20261031220000::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20261101120000::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20261118191044::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\WopiCredentialsViewService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\CalDavService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ConfigService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ContactsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\DoctrineMigrationsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\EmailAddressService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\EncryptionService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\EmailTemplateDefaultsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\EventsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\InstrumentationService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\L10N\AppL10N::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\L10N\BiDirectionalL10N::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\L10N\L10NFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\MailingListsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\MusicianService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ProjectParticipantFieldsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ProjectService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\Registration::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ToolTipsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\VCalendarService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\AppInfo\AbstractApplication::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Common\RationalNumber::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\AbstractDecimalRationalType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\ArrayType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\DecimalRationalMonetaryType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\UuidType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\ORM\AbstractEntityManager::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\ORM\EnumOrderByOptions::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\ArrayTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\CreatedAt::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\DateTimeTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\EncryptionContextTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\FactoryTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\SoftDeleteableEntity::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\TranslatableTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\UnusedTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\UpdatedAt::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\UuidTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\ORM\FindLikeTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\BackedEnumTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\AppConfigTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\UserPreferencesTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\CamelCaseToDashesTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\TranslatableEnumTrait::class)]
class InvoicesStorageTest extends TestCase
{
  use \OCA\CAFEVDB\Tests\Unit\Database\Doctrine\EnableDeprecationsTrait;
  use \OCA\CAFEVDB\Tests\Unit\Database\Doctrine\ORM\Entities\EntityGeneratorTrait;
  use \OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations\SetupMigrationTrait;
  use \OCA\CAFEVDB\Tests\Unit\Service\SetupCalendarBackendTrait;

  private DatabaseStorage\InvoicesStorage $storage;

  private static bool $migrationsApplied = false;

  private static int $projectId;

  private static int $musicianId;

  private DateTimeImmutable $now;

  /** {@inheritdoc} */
  public function setup(): void
  {
    self::enableDeprecations();

    $this->generateCalendarBackend();

    $this->mockProvider = $this->mockProvider ?? MockProvider::create($this);

    $this->now = DateTimeImmutable::createFromFormat('Y-m-d h:i:s', '2099-01-01 12:00:00');
    /** @var TimeFactory $timeFactory */
    $timeFactory = $this->getMockBuilder(TimeFactory::class)->getMock();
    $timeFactory->method('now')->willReturnCallback(fn() => $this->now);
    $timeFactory->expects($this->never())->method('withTimeZone');
    $this->mockProvider->registerClassInstance(TimeFactory::class, $timeFactory, global: true);

    if (!self::$migrationsApplied) {
      $this->applyMigrations('latest');
      $this->generateProjectParticipant(persist: true, now: $this->now, delete: false);
      self::$projectId = $this->project->getId();
      self::$musicianId = $this->musician->getId();

      self::$migrationsApplied = true;
    }

    $this->storage = new DatabaseStorage\InvoicesStorage([
      'configService' => $this->mockProvider->getConfigService(),
    ]);
  }

  /**
   * This is quas a setupBeforeClass() but we need some mocked / stubbed
   * classes for the entity-manager.
   *
   * @return void
   */
  public function testApplyMigrations(): void
  {
  }

  /**
   * @param bool $invoice
   *
   * @return void
   */
  #[Attributes\Depends('testApplyMigrations')]
  #[Attributes\DataProvider('financialDocumentTypes')]
  public function testParticipantFinancialDocumentLinks(bool $invoice): void
  {
    $entityManager = $this->getEntityManager();
    $participant = $entityManager->find(Entities\ProjectParticipant::class, [
      'project' => self::$projectId,
      'musician' => self::$musicianId,
    ]);
    $storage = new DatabaseStorage\ProjectParticipantsStorage([
      'configService' => $this->mockProvider->getConfigService(),
      'participant' => $participant,
    ]);
    $entity = $invoice ? new Entities\Invoice() : new Entities\DonationReceipt();
    $first = new Entities\EncryptedFile('first.pdf', 'first', 'application/pdf', $participant->getMusician());
    $second = new Entities\EncryptedFile('second.pdf', 'second', 'application/pdf', $participant->getMusician());
    $document = new Entities\DatabaseStorageFile()->setName('first.pdf')->setFile($first);

    $entityManager->beginTransaction();
    try {
      $entityManager->persist($first);
      $entityManager->persist($second);
      $storage->synchronizeFinancialDocument($entity, $document);
      $entityManager->flush();

      $root = $participant->getDatabaseDocuments()->getRoot();
      $folderName = $this->mockProvider->getConfigService()->getAppL10n()->t($invoice ? 'Invoices' : 'DonationReceipts');
      $folder = $root->getFolderByName($folderName);
      self::assertNotNull($folder);
      $participantLink = $folder->getFileByName('first.pdf');
      self::assertNotNull($participantLink);
      $entityManager->refresh($participantLink);
      $entityManager->refresh($first->getFileData());
      self::assertSame($first, $participantLink->getFile());
      self::assertNotSame($document, $participantLink);
      self::assertTrue($first->getOwners()->contains($participant->getMusician()));
      self::assertSame('first', $first->getFileData()->getData());

      $document->setFile($second);
      $storage->synchronizeFinancialDocument($entity, $document, $first, 'first.pdf');
      $entityManager->flush();
      self::assertSame($second, $folder->getFileByName('first.pdf')->getFile());

      $document->setName('second.pdf');
      $storage->synchronizeFinancialDocument($entity, $document, $second, 'first.pdf');
      $entityManager->flush();
      self::assertNull($folder->getFileByName('first.pdf'));
      self::assertSame($second, $folder->getFileByName('second.pdf')->getFile());

      $other = new Entities\EncryptedFile('other.pdf', 'other', 'application/pdf', $participant->getMusician());
      $entityManager->persist($other);
      $conflict = new Entities\DatabaseStorageFile()->setName('second.pdf')->setFile($other);
      try {
        $storage->synchronizeFinancialDocument($entity, $conflict);
        self::fail('A conflicting participant document must not be overwritten.');
      } catch (\UnexpectedValueException) {
        self::assertSame($second, $folder->getFileByName('second.pdf')->getFile());
      }

      $storage->synchronizeFinancialDocument($entity, null, $second, 'second.pdf');
      $entityManager->flush();
      self::assertNull($folder->getFileByName('second.pdf'));
      self::assertSame($second, $document->getFile());

      $unowned = new Entities\EncryptedFile('unowned.pdf', 'unowned', 'application/pdf');
      $entityManager->persist($unowned);
      $document->setName('unowned.pdf')->setFile($unowned);
      $storage->synchronizeFinancialDocument($entity, $document);
      $entityManager->flush();
      $unownedLink = $folder->getFileByName('unowned.pdf');
      self::assertNotNull($unownedLink);
      $entityManager->refresh($unownedLink);
      self::assertSame($unowned, $unownedLink->getFile());
      self::assertFalse($unowned->getOwners()->contains($participant->getMusician()));
    } finally {
      $entityManager->rollBack();
      $entityManager->clear();
      Entities\DatabaseStorageFile::clearOrphans();
    }
  }

  /** @return iterable<string, array{bool}> */
  public static function financialDocumentTypes(): iterable
  {
    yield 'invoice' => [true];
    yield 'donation receipt' => [false];
  }

  /**
   * @param bool $invoice
   *
   * @return void
   */
  #[Attributes\Depends('testApplyMigrations')]
  #[Attributes\DataProvider('financialDocumentTypes')]
  public function testDedicatedAndParticipantLinks(bool $invoice): void
  {
    $entityManager = $this->getEntityManager();
    $participant = $entityManager->find(Entities\ProjectParticipant::class, [
      'project' => self::$projectId,
      'musician' => self::$musicianId,
    ]);
    $participantStorage = new DatabaseStorage\ProjectParticipantsStorage([
      'configService' => $this->mockProvider->getConfigService(),
      'participant' => $participant,
    ]);
    if ($invoice) {
      $entity = new Entities\Invoice()
        ->setDebitor($participant->getMusician())
        ->setProject($participant->getProject())
        ->setProjectParticipant($participant)
        ->setInvoiceNumber('2099-001')
        ->setInvoiceDate('2099-01-01');
      $participant->getProject()->getInvoices()->removeElement($entity);
      $financeStorage = $this->storage;
    } else {
      $donation = new Entities\CompositePayment()
        ->setProjectParticipant($participant)
        ->setDateOfReceipt('2099-01-01');
      $participant->getProject()->getCompositePayments()->removeElement($donation);
      $participant->getMusician()->getPayments()->removeElement($donation);
      $entity = new Entities\DonationReceipt()->setId(123)->setDonation($donation);
      $financeStorage = new DatabaseStorage\DonationReceiptsStorage([
        'configService' => $this->mockProvider->getConfigService(),
      ]);
    }

    $first = new Entities\EncryptedFile('first.pdf', 'first', 'application/pdf', $participant->getMusician());
    $second = new Entities\EncryptedFile(
      $invoice ? 'second.txt' : 'second.pdf',
      'second',
      $invoice ? 'text/plain' : 'application/pdf',
      $participant->getMusician(),
    );
    $entityManager->beginTransaction();
    try {
      $entityManager->persist($first);
      $entityManager->persist($second);
      $document = $financeStorage->addDocument($entity, $first, flush: false);
      $participantStorage->synchronizeFinancialDocument($entity, $document);
      $entityManager->flush();

      $folderName = $this->mockProvider->getConfigService()->getAppL10n()->t($invoice ? 'Invoices' : 'DonationReceipts');
      $folder = $participant->getDatabaseDocuments()->getRoot()->getFolderByName($folderName);
      $name = $document->getName();
      $participantLink = $folder->getFileByName($name);
      self::assertNotNull($participantLink);
      self::assertNotSame($document, $participantLink);
      $entityManager->refresh($document);
      $entityManager->refresh($participantLink);
      self::assertSame($first, $document->getFile());
      self::assertSame($first, $participantLink->getFile());

      if ($invoice) {
        $controller = new DocumentStorageUploadController(
          appName: $this->mockProvider->appName,
          request: $this->createStub(\OCP\IRequest::class),
          storageFactory: $this->createStub(DatabaseStorage\Factory::class),
          configService: $this->mockProvider->getConfigService(),
          entityManager: $entityManager,
        );
        $oldFolder = $document->getParent();
        $document = new ReflectionMethod($controller, 'addDocument')->invoke(
          $controller,
          $document,
          $second,
          $entity,
          $financeStorage,
          EnumAddDocumentConflictAction::REPLACE,
        );
        self::assertNull($oldFolder->getFileByName($name));
      } else {
        $document->setFile($second);
      }
      $participantStorage->synchronizeFinancialDocument($entity, $document, $first, $name);
      $entityManager->flush();
      $entityManager->refresh($document);
      $participantLink = $folder->getFileByName($document->getName());
      self::assertNotNull($participantLink);
      $entityManager->refresh($participantLink);
      self::assertSame($second, $document->getFile());
      self::assertSame($second, $participantLink->getFile());
      self::assertTrue($second->getOwners()->contains($participant->getMusician()));

      $participantStorage->synchronizeFinancialDocument($entity, null, $second, $document->getName());
      $entityManager->flush();
      self::assertNull($folder->getFileByName($document->getName()));
      $entityManager->refresh($document);
      self::assertSame($second, $document->getFile());
    } finally {
      $entityManager->rollBack();
      $entityManager->clear();
      Entities\DatabaseStorageFile::clearOrphans();
    }
  }

  /** @return void */
  #[Attributes\Depends('testApplyMigrations')]
  public function testDebitMandateRootInitializationFromLockedStorageRow(): void
  {
    $entityManager = $this->getEntityManager();
    $storage = new DatabaseStorage\DebitMandatesStorage([
      'configService' => $this->mockProvider->getConfigService(),
    ]);
    $entityManager->beginTransaction();
    try {
      $connection = $entityManager->getConnection();
      $connection->executeStatement(
        'INSERT INTO DatabaseStorages (storage_id, root_id) VALUES (?, NULL)',
        [$storage->getShortId()],
      );
      $storage->synchronize();
      $row = $connection->fetchAssociative(
        'SELECT id, root_id FROM DatabaseStorages WHERE storage_id = ?',
        [$storage->getShortId()],
      );
      self::assertNotFalse($row);
      self::assertNotNull($row['root_id']);
      $storage->synchronize();
      self::assertSame(
        (int)$row['root_id'],
        (int)$connection->fetchOne('SELECT root_id FROM DatabaseStorages WHERE id = ?', [$row['id']]),
      );
    } finally {
      $entityManager->rollBack();
      $entityManager->clear();
      Entities\DatabaseStorageFile::clearOrphans();
    }
    self::assertFalse(
      $entityManager->getConnection()->fetchOne(
        'SELECT id FROM DatabaseStorages WHERE storage_id = ?',
        [$storage->getShortId()],
      ),
    );
  }

  /** @return void */
  #[Attributes\Depends('testApplyMigrations')]
  public function testDebitMandateFirstAccessWaitsForConcurrentRootCreation(): void
  {
    $entityManager = $this->getEntityManager();
    $storage = new DatabaseStorage\DebitMandatesStorage([
      'configService' => $this->mockProvider->getConfigService(),
    ]);
    $connection = $entityManager->getConnection();
    self::assertFalse($connection->fetchOne(
      'SELECT id FROM DatabaseStorages WHERE storage_id = ?',
      [$storage->getShortId()],
    ));

    $worker = <<<'PHP'
      $params = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
      $dsn = 'mysql:dbname=' . $params['dbname'];
      if (!empty($params['unix_socket'])) {
        $dsn .= ';unix_socket=' . $params['unix_socket'];
      } else {
        $dsn .= ';host=' . $params['host'];
        if (!empty($params['port'])) {
          $dsn .= ';port=' . $params['port'];
        }
      }
      $db = new PDO($dsn, $params['user'], $params['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
      $db->beginTransaction();
      $db->exec("INSERT INTO DatabaseStorageDirEntries (name, type) VALUES ('', 'folder')");
      $root = $db->lastInsertId();
      $statement = $db->prepare('INSERT INTO DatabaseStorages (storage_id, root_id) VALUES (?, ?)');
      $statement->execute([$params['storage_id'], $root]);
      fwrite(STDOUT, $root . "\n");
      fflush(STDOUT);
      if (trim(fgets(STDIN)) !== 'commit') {
        throw new RuntimeException('Unexpected worker command.');
      }
      usleep(1500000);
      $db->commit();
      PHP;
    $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-r', $worker], [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ], $pipes);
    self::assertIsResource($process);
    $rootId = null;
    $transactionStarted = false;
    try {
      $params = array_intersect_key($connection->getParams(), array_flip(['dbname', 'host', 'port', 'unix_socket', 'user', 'password']));
      $params['storage_id'] = $storage->getShortId();
      fwrite($pipes[0], json_encode($params, JSON_THROW_ON_ERROR) . "\n");
      stream_set_timeout($pipes[1], 15);
      $root = fgets($pipes[1]);
      if ($root === false || !ctype_digit(trim($root))) {
        throw new RuntimeException('Unable to prepare concurrent storage root.');
      }
      $rootId = (int)trim($root);
      $entityManager->beginTransaction();
      $transactionStarted = true;
      fwrite($pipes[0], "commit\n");
      $started = microtime(true);
      $storage->synchronize();
      self::assertGreaterThan(0.5, microtime(true) - $started);
      self::assertSame($rootId, (int)$connection->fetchOne(
        'SELECT root_id FROM DatabaseStorages WHERE storage_id = ?',
        [$storage->getShortId()],
      ));
    } finally {
      if ($transactionStarted && $entityManager->isTransactionActive()) {
        $entityManager->rollBack();
      }
      $entityManager->clear();
      fclose($pipes[0]);
      fclose($pipes[1]);
      $errors = stream_get_contents($pipes[2]);
      fclose($pipes[2]);
      $status = proc_close($process);
      if ($rootId !== null) {
        $connection = $entityManager->getConnection();
        $connection->executeStatement(
          'DELETE FROM DatabaseStorages WHERE storage_id = ? AND root_id = ?',
          [$storage->getShortId(), $rootId],
        );
        $connection->executeStatement('DELETE FROM DatabaseStorageDirEntries WHERE id = ?', [$rootId]);
      }
      self::assertSame(0, $status, $errors);
    }
  }

  /** @return void */
  #[Attributes\Depends('testApplyMigrations')]
  public function testDebitMandateFinanceStorage(): void
  {
    $entityManager = $this->getEntityManager();
    $participant = $entityManager->find(Entities\ProjectParticipant::class, [
      'project' => self::$projectId,
      'musician' => self::$musicianId,
    ]);
    $musician = $participant->getMusician();
    $project = $participant->getProject();
    $participantStorage = new DatabaseStorage\ProjectParticipantsStorage([
      'configService' => $this->mockProvider->getConfigService(),
      'participant' => $participant,
    ]);
    $financeStorage = new DatabaseStorage\DebitMandatesStorage([
      'configService' => $this->mockProvider->getConfigService(),
    ]);
    $mandate = new Entities\SepaDebitMandate()
      ->setMusician($musician)
      ->setProject($project)
      ->setSepaBankAccount($musician->getSepaBankAccounts()->first())
      ->setMandateReference('MANDATE-1')
      ->setSequence(1)
      ->setNonRecurring(false)
      ->setMandateDate('2099-01-01');
    $file = new Entities\EncryptedFile('mandate', 'signed', 'application/octet-stream', $musician);

    $entityManager->beginTransaction();
    try {
      $entityManager->persist($file);
      $entityManager->persist($mandate);
      $participantLink = $participantStorage->addDebitMandate($mandate, $file, flush: false);
      $mandate->setWrittenMandate($participantLink);
      $entityManager->flush();
      self::assertSame('MANDATE-1', $participantLink->getName());

      $financeStorage->synchronize();
      $root = $entityManager->getRepository(Entities\DatabaseStorage::class)
        ->findOneBy(['storageId' => $financeStorage->getShortId()])->getRoot();
      $musicianFolder = $root->getFolderByName($musician->getUserIdSlug());
      $projectFolder = $musicianFolder->getFolderByName($project->getName());
      self::assertSame($file, $projectFolder->getFileByName('MANDATE-1')->getFile());
      self::assertSame($file, $participantLink->getFile());

      $mandate->setDeleted(new DateTimeImmutable('+1 day'));
      $entityManager->flush();
      $financeStorage->synchronize();
      self::assertNotNull($projectFolder->getFileByName('MANDATE-1'));

      $mandate->setDeleted(new DateTimeImmutable('-1 minute'));
      $entityManager->flush();
      $revokedName = $this->mockProvider->getConfigService()->getAppL10n()->t('Revoked');
      $blocker = $projectFolder->addDocument($file, $revokedName);
      $entityManager->persist($blocker);
      $entityManager->flush();
      try {
        $financeStorage->synchronize();
        self::fail('A file occupying the revoked folder must prevent reconciliation.');
      } catch (DatabaseEntityExistsException $exception) {
        self::assertStringContainsString('not a directory', $exception->getMessage());
        self::assertSame($blocker, $exception->existingEntity);
      }
      self::assertSame($file, $projectFolder->getFileByName('MANDATE-1')->getFile());
      self::assertSame($file, $participantLink->getFile());
      self::assertSame(
        $file->getId(),
        (int)$entityManager->getConnection()->fetchOne(
          'SELECT file_id FROM DatabaseStorageDirEntries WHERE id = ?',
          [$participantLink->getId()],
        ),
      );
      $blocker->setFile(null)->setParent(null);
      $entityManager->remove($blocker);
      $entityManager->flush();
      $financeStorage->synchronize();
      self::assertNull($projectFolder->getFileByName('MANDATE-1'));
      self::assertSame($file, $projectFolder->getFolderByName($revokedName)->getFileByName('MANDATE-1')->getFile());
      self::assertSame($file, $participantLink->getFile());

      $mandate->setDeleted(null);
      $entityManager->flush();
      $financeStorage->synchronize();
      self::assertSame($file, $projectFolder->getFileByName('MANDATE-1')->getFile());
      self::assertNull($projectFolder->getFolderByName($revokedName));
      $financeStorage->synchronize();
      self::assertSame($file, $projectFolder->getFileByName('MANDATE-1')->getFile());

      $replacement = new Entities\EncryptedFile('replacement', 'new', 'application/octet-stream', $musician);
      $entityManager->persist($replacement);
      $participantLink->setFile($replacement);
      $entityManager->flush();
      $financeStorage->synchronize();
      self::assertSame($replacement, $projectFolder->getFileByName('MANDATE-1')->getFile());

      $mandate->setWrittenMandate(null);
      $entityManager->flush();
      $financeStorage->synchronize();
      self::assertNull($root->getFolderByName($musician->getUserIdSlug()));
    } finally {
      $entityManager->rollBack();
      $entityManager->clear();
      Entities\DatabaseStorageFile::clearOrphans();
    }
  }

  /**
   * This is quas a tearDownAfterClass() but we need some mocked / stubbed
   * classes for the entity-manager.
   *
   * @return void
   */
  #[Attributes\Depends('testApplyMigrations')]
  #[Attributes\Depends('testParticipantFinancialDocumentLinks')]
  #[Attributes\Depends('testDedicatedAndParticipantLinks')]
  #[Attributes\Depends('testDebitMandateRootInitializationFromLockedStorageRow')]
  #[Attributes\Depends('testDebitMandateFirstAccessWaitsForConcurrentRootCreation')]
  #[Attributes\Depends('testDebitMandateFinanceStorage')]
  public function testUnapplyMigrations(): void
  {
    $this->unapplyMigrations();
    self::$migrationsApplied = false;
  }
}
