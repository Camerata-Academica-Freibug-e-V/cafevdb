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

namespace OCA\CAFEVDB\Tests\Unit\Controller;

use DateTimeImmutable;
use ReflectionProperty;
use UnexpectedValueException;

use ParagonIE\Halite\KeyFactory;

use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use OC\AppFramework\Http\Request;

use OCA\CAFEVDB\Controller;
use OCA\CAFEVDB\Crypto\HaliteAsymmetricCryptor;
use OCA\CAFEVDB\Crypto\ICryptor;
use OCA\CAFEVDB\Database\Doctrine\DBAL\Types\EnumTaxType;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\Transformable\Encryption;
use OCA\CAFEVDB\Database\EntityManager;
use OCA\CAFEVDB\Exceptions\EnduserNotificationException;
use OCA\CAFEVDB\Storage\Database as DatabaseStorage;
use OCA\CAFEVDB\Storage\Database\Factory as StorageFactory;
use OCA\CAFEVDB\Storage\UserStorage;
use OCA\CAFEVDB\Tests\MockProvider;
use OCA\CAFEVDB\Tests\Unit\Common\TimeFactoryTrait;
use OCA\CAFEVDB\Tests\Unit\Database\Doctrine\EnableDeprecationsTrait;
use OCA\CAFEVDB\Tests\Unit\Database\Doctrine\ORM\Entities\EntityGeneratorTrait;
use OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations\SetupMigrationTrait;
use OCA\CAFEVDB\Tests\Unit\Service\SetupCalendarBackendTrait;

/** Test aspects of the DocumentStorageUploadController. */
#[Attributes\CoversClass(Controller\DocumentStorageUploadController::class)]
#[Attributes\UsesClass(Controller\DTO\UploadFileData::class)]
#[Attributes\UsesClass(Controller\DTO\UploadFileMetaData::class)]
#[Attributes\UsesClass(HaliteAsymmetricCryptor::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\AsymmetricKeyService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\CloudAsymmetricKeyStorage::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Crypto\Registration::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\Util::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Events\EntityManagerClosedEvent::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Common\AbstractDecimalRational::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Exceptions\EnduserNotificationException::class)]
#[Attributes\UsesClass(DatabaseStorage\Factory::class)]
#[Attributes\UsesClass(DatabaseStorage\InvoicesStorage::class)]
#[Attributes\UsesClass(DatabaseStorage\DonationReceiptsStorage::class)]
#[Attributes\UsesClass(DatabaseStorage\ProjectParticipantsStorage::class)]
#[Attributes\UsesTrait(DatabaseStorage\DatabaseStorageNodeNameTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\AppConfigTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Traits\UserPreferencesTrait::class)]
#[Attributes\UsesClass(Entities\CompositePayment::class)]
#[Attributes\UsesClass(Entities\DatabaseStorage::class)]
#[Attributes\UsesClass(Entities\DatabaseStorageCacheChange::class)]
#[Attributes\UsesClass(Entities\DatabaseStorageDirEntry::class)]
#[Attributes\UsesClass(Entities\DatabaseStorageFile::class)]
#[Attributes\UsesClass(Entities\DatabaseStorageFolder::class)]
#[Attributes\UsesClass(Entities\DonationReceipt::class)]
#[Attributes\UsesClass(Entities\EncryptedFile::class)]
#[Attributes\UsesClass(Entities\EncryptedFileData::class)]
#[Attributes\UsesClass(Entities\File::class)]
#[Attributes\UsesClass(Entities\FileData::class)]
#[Attributes\UsesClass(Entities\Invoice::class)]
#[Attributes\UsesClass(Entities\TaxationStatutorySource::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Listener\DatabaseStorageFileEntityListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\Sluggable\AssociationSlugHandler::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Common\RationalNumber::class)]
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
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\DBAL\Logging\CloudLogger::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\DeprecationLogger::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\Migrations\AbstractMigration::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\Migrations\AbstractTransactionalMigration::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\Migrations\DependencyFactory::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\Instrument::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\InstrumentFamily::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\LogEntry::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\Musician::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\MusicianEmailAddress::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\Project::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\ProjectParticipant::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Entities\SepaBankAccount::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\DatabaseStorageTimestampListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\StorageChangeCollector::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\DoctrineMigrationsListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\GedmoLoggableListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\GedmoSluggableListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\GedmoTranslatableListener::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Database\Doctrine\ORM\Listeners\Sluggable\HashHandler::class)]
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
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20261125155000::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Maintenance\Migrations\Version20261125161000::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\WopiCredentialsViewService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\EmailTemplateDefaultsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\CalDavService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ConfigService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\ContactsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\DoctrineMigrationsService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\EmailAddressService::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Service\EncryptionService::class)]
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
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\AbstractDecimalRationalType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\ArrayType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\DecimalRationalMonetaryType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\DBAL\Types\UuidType::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\ORM\AbstractEntityManager::class)]
#[Attributes\UsesClass(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\ORM\EnumOrderByOptions::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\ArrayTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\AutoIncrementTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\CreatedAt::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\DateTimeTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\EncryptionContextTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\FactoryTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\SoftDeleteableEntity::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\TranslatableTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\UpdatedAt::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Database\Doctrine\ORM\Traits\UuidTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Doctrine\ORM\FindLikeTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\BackedEnumTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\CamelCaseToDashesTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\DateTimeTrait::class)]
#[Attributes\UsesTrait(\OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Traits\TranslatableEnumTrait::class)]
class DocumentStorageUploadControllerTest extends TestCase
{
  use EnableDeprecationsTrait;
  use EntityGeneratorTrait;
  use SetupCalendarBackendTrait;
  use SetupMigrationTrait;
  use TestRoutesAreDefinedTrait;
  use TimeFactoryTrait;

  private const CONTROLLER_CLASS = Controller\DocumentStorageUploadController::class;
  private const EXPECTED_ROUTES = [
    'documents',
  ];

  private MockProvider $mockProvider;

  private Controller\DocumentStorageUploadController $controller;

  private array $postData = [];

  private static bool $migrationsApplied = false;

  private static int $projectId;

  private static int $musicianId;

  private bool $fixtureTransaction = false;

  private array $uploadPaths = [];

  private ?Encryption $encryptionTransformer = null;

  private ?ICryptor $previousAppCryptor = null;

  /** {@inheritdoc} */
  public function setup(): void
  {
    self::enableDeprecations();

    $this->generateCalendarBackend();

    $this->mockProvider = $this->mockProvider ?? MockProvider::create($this);

    $this->mockTimeFactory(DateTimeImmutable::createFromFormat('Y-m-d h:i:s', '2099-01-01 12:00:00'));

    if (!self::$migrationsApplied) {
      $this->applyMigrations('latest');
      $this->generateProjectParticipant(persist: true, now: $this->now, delete: false);
      self::$projectId = $this->project->getId();
      self::$musicianId = $this->musician->getId();

      self::$migrationsApplied = true;
    }

    $this->entityManager = $this->entityManager ?? $this->mockProvider->getEntityManager();

    $request = $this->createStub(Request::class);
    $request->method('getParam')->willReturnCallback(
      function(string $key, mixed $default = null) {
        return $this->postData[$key] ?? $default;
      },
    );

    $this->mockProvider->registerClassInstance(
      UserStorage::class, $this->createStub(UserStorage::class), global: true,
    );

    $this->controller = new Controller\DocumentStorageUploadController(
      appName: $this->mockProvider->appName,
      request: $request,
      configService: $this->mockProvider->getConfigService(),
      entityManager: $this->entityManager,
      storageFactory: $this->createStub(StorageFactory::class),
    );
  }

  /** @return void */
  public function tearDown(): void
  {
    if ($this->fixtureTransaction) {
      if ($this->entityManager->isTransactionActive()) {
        $this->entityManager->rollback();
      }
      $this->entityManager->clear();
      Entities\DatabaseStorageFile::clearOrphans();
    }
    $this->encryptionTransformer?->setAppCryptor($this->previousAppCryptor);
    foreach ($this->uploadPaths as $path) {
      if (file_exists($path)) {
        unlink($path);
      }
    }
    restore_error_handler();
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
  #[Attributes\DataProvider('financialDocumentTypes')]
  public function testFinancialDocumentLifecycle(bool $invoice): void
  {
    $id = $this->createFinancialDocument($invoice);
    $this->uploadDocument($invoice, $id, "%PDF-1.4\nfirst document");
    $document = $this->assertDocumentLinks($invoice, $id, "%PDF-1.4\nfirst document");
    $firstFileId = $document->getFile()->getId();
    $firstName = $document->getName();
    self::assertStringEndsWith('.pdf', $firstName);

    $otherFolder = $document->getParent()->addSubFolder('Unrelated');
    $otherLink = $otherFolder->addDocument($document->getFile(), 'shared.pdf');
    $this->entityManager->persist($otherFolder);
    $this->entityManager->persist($otherLink);
    $this->entityManager->flush();
    $otherLinkId = $otherLink->getId();

    $this->uploadDocument($invoice, $id, "%PDF-1.4\nreplacement");
    $document = $this->assertDocumentLinks($invoice, $id, "%PDF-1.4\nreplacement");
    self::assertNotSame($firstFileId, $document->getFile()->getId());
    self::assertSame($firstName, $document->getName());
    self::assertSame(
      "%PDF-1.4\nfirst document",
      $this->entityManager->find(Entities\DatabaseStorageFile::class, $otherLinkId)->getFile()->getFileData()->getData(),
    );

    $oldEntryId = $document->getId();
    $oldFileId = $document->getFile()->getId();
    $oldFolderId = $document->getParent()->getId();
    $this->uploadDocument($invoice, $id, 'Changed extension');
    $document = $this->assertDocumentLinks($invoice, $id, 'Changed extension');
    self::assertStringEndsWith('.txt', $document->getName());
    self::assertNull($this->entityManager->find(Entities\DatabaseStorageFile::class, $oldEntryId));
    self::assertNull($this->entityManager->find(Entities\EncryptedFile::class, $oldFileId));
    self::assertNull(
      $this->entityManager->find(Entities\DatabaseStorageFolder::class, $oldFolderId)->getFileByName($firstName),
    );
    self::assertNull($this->participantDocumentFolder($invoice)->getFileByName($firstName));

    $backupFileId = $document->getFile()->getId();
    $backupEntryId = $document->getId();
    $name = $document->getName();
    $this->uploadDocument($invoice, $id, 'Renamed replacement', Controller\EnumAddDocumentConflictAction::RENAME);
    $document = $this->assertDocumentLinks($invoice, $id, 'Renamed replacement');
    self::assertSame($name, $document->getName());
    $backup = $this->entityManager->find(Entities\DatabaseStorageFile::class, $backupEntryId);
    self::assertSame($backupFileId, $backup->getFile()->getId());
    self::assertSame('Changed extension', $backup->getFile()->getFileData()->getData());
    self::assertSame(pathinfo($name, PATHINFO_FILENAME) . '~1.txt', $backup->getName());

    $fileId = $document->getFile()->getId();
    $entryId = $document->getId();
    $this->deleteDocument($invoice, $id);
    self::assertNull($this->reloadDocument($invoice, $id));
    self::assertNull($this->participantDocumentFolder($invoice)->getFileByName($name));
    self::assertNull($this->entityManager->find(Entities\DatabaseStorageFile::class, $entryId));
    self::assertNull($this->entityManager->find(Entities\EncryptedFile::class, $fileId));
    self::assertSame(
      "%PDF-1.4\nfirst document",
      $this->entityManager->find(Entities\DatabaseStorageFile::class, $otherLinkId)->getFile()->getFileData()->getData(),
    );
    self::assertNotNull($this->entityManager->find(Entities\DatabaseStorageFile::class, $backupEntryId));
  }

  /**
   * @param bool $invoice
   *
   * @return void
   */
  #[Attributes\DataProvider('financialDocumentTypes')]
  public function testLinkingDoesNotGrantOwnershipAndDeletionPreservesOtherLinks(bool $invoice): void
  {
    $id = $this->createFinancialDocument($invoice);
    $this->uploadDocument($invoice, $id, 'Original document');
    $document = $this->assertDocumentLinks($invoice, $id, 'Original document');
    $unowned = new Entities\EncryptedFile('existing.txt', 'Linked content', 'text/plain');
    $other = $document->getParent()->addDocument($unowned, 'existing.txt');
    $this->entityManager->persist($other);
    $this->entityManager->flush();
    $fileId = $unowned->getId();
    $otherId = $other->getId();
    $ciphertext = $this->storedFileData($fileId);

    $this->postData['files'] = json_encode([[
      'name' => 'existing.txt', 'original_name' => (string)$fileId,
      'tmp_name' => '', 'size' => strlen('Linked content'), 'type' => 'text/plain',
      'error' => UPLOAD_ERR_OK, 'upload_mode' => Controller\EnumFileUploadMode::LINK->value,
    ]], JSON_THROW_ON_ERROR);
    $response = $this->documentController()->documents(
      section: Controller\DocumentStorageUploadController::SECTION_FINANCE,
      topic: $this->topic($invoice),
      operation: Controller\DocumentStorageUploadController::DOCUMENT_ACTION_UPLOAD,
      musicianId: self::$musicianId,
      projectId: self::$projectId,
      data: ['optionKey' => $id],
      uploadMode: Controller\EnumFileUploadMode::LINK->value,
    );
    self::assertSame(200, $response->getStatus());
    $document = $this->assertDocumentLinks($invoice, $id, 'Linked content', owned: false);
    self::assertSame($fileId, $document->getFile()->getId());
    self::assertSame($ciphertext, $this->storedFileData($fileId));
    $context = new ReflectionProperty(Entities\EncryptedFileData::class, 'encryptionContext')
      ->getValue($document->getFile()->getFileData());
    self::assertNotContains($this->participant()->getMusician()->getUserIdSlug(), $context);

    $this->deleteDocument($invoice, $id);
    self::assertNull($this->reloadDocument($invoice, $id));
    $other = $this->entityManager->find(Entities\DatabaseStorageFile::class, $otherId);
    self::assertSame($fileId, $other->getFile()->getId());
    self::assertSame('Linked content', $other->getFile()->getFileData()->getData());
    self::assertCount(0, $other->getFile()->getOwners());
    self::assertSame($ciphertext, $this->storedFileData($fileId));
  }

  /**
   * @param bool $invoice
   *
   * @return void
   */
  #[Attributes\DataProvider('financialDocumentTypes')]
  public function testParticipantCollisionRollsBackReplacement(bool $invoice): void
  {
    $id = $this->createFinancialDocument($invoice);
    $this->uploadDocument($invoice, $id, 'Original document');
    $document = $this->assertDocumentLinks($invoice, $id, 'Original document');
    $entryId = $document->getId();
    $fileId = $document->getFile()->getId();
    $ciphertext = $this->storedFileData($fileId);
    $folder = $this->participantDocumentFolder($invoice);
    $collision = new Entities\EncryptedFile('collision.txt', 'Unrelated content', 'text/plain');
    $folder->getFileByName($document->getName())->setFile($collision);
    $this->entityManager->flush();
    $collisionId = $collision->getId();
    $fileCount = $this->entityManager->getRepository(Entities\EncryptedFile::class)->count([]);
    $entryCount = $this->entityManager->getRepository(Entities\DatabaseStorageFile::class)->count([]);

    try {
      $this->uploadDocument($invoice, $id, 'Rejected replacement', Controller\EnumAddDocumentConflictAction::RENAME);
      self::fail('A conflicting participant link must abort the upload.');
    } catch (EnduserNotificationException $exception) {
      self::assertInstanceOf(UnexpectedValueException::class, $exception->getPrevious());
      self::assertStringContainsString('different participant document', $exception->getPrevious()->getMessage());
    }

    $document = $this->reloadDocument($invoice, $id);
    self::assertSame($entryId, $document->getId());
    self::assertSame($fileId, $document->getFile()->getId());
    self::assertSame('Original document', $document->getFile()->getFileData()->getData());
    self::assertSame($ciphertext, $this->storedFileData($fileId));
    self::assertSame(
      $collisionId,
      $this->participantDocumentFolder($invoice)->getFileByName($document->getName())->getFile()->getId(),
    );
    self::assertSame($fileCount, $this->entityManager->getRepository(Entities\EncryptedFile::class)->count([]));
    self::assertSame($entryCount, $this->entityManager->getRepository(Entities\DatabaseStorageFile::class)->count([]));
  }

  /**
   * @param bool $invoice
   *
   * @return int
   */
  private function createFinancialDocument(bool $invoice): int
  {
    $keys = KeyFactory::generateSignatureKeyPair();
    $cryptor = new HaliteAsymmetricCryptor($keys->getSecretKey());
    $cryptor->setPublicKey($keys->getPublicKey());
    $this->encryptionTransformer = $this->entityManager->getDataTransformer(EntityManager::TRANSFORM_ENCRYPT);
    $this->previousAppCryptor = $this->encryptionTransformer->getAppCryptor();
    $this->encryptionTransformer->setAppCryptor($cryptor);
    $this->entityManager->beginTransaction();
    $this->fixtureTransaction = true;
    $participant = $this->participant();
    if ($invoice) {
      $taxation = new Entities\TaxationStatutorySource()
        ->setTaxType(EnumTaxType::SALES)->setCountry('DE')->setLaw('Lifecycle test');
      $entity = new Entities\Invoice()
        ->setProject($participant->getProject())
        ->setProjectParticipant($participant)
        ->setDebitor($participant->getMusician())
        ->setInvoiceNumber('2099-001')
        ->setInvoiceDate('2099-01-01')
        ->setSubject('Lifecycle test')
        ->setTaxationStatutorySource($taxation);
    } else {
      $donation = new Entities\CompositePayment()
        ->setProjectParticipant($participant)
        ->setSubject('Lifecycle test')
        ->setDateOfReceipt('2099-01-01');
      $this->entityManager->persist($donation);
      $entity = new Entities\DonationReceipt()->setDonation($donation);
    }
    $this->entityManager->persist($entity);
    $this->entityManager->flush();
    return $entity->getId();
  }

  /** @return Entities\ProjectParticipant */
  private function participant(): Entities\ProjectParticipant
  {
    return $this->entityManager->find(Entities\ProjectParticipant::class, [
      'project' => self::$projectId, 'musician' => self::$musicianId,
    ]);
  }

  /** @return Controller\DocumentStorageUploadController */
  private function documentController(): Controller\DocumentStorageUploadController
  {
    $config = $this->mockProvider->getConfigService();
    foreach ([DatabaseStorage\InvoicesStorage::class, DatabaseStorage\DonationReceiptsStorage::class] as $class) {
      $this->mockProvider->registerClassInstance($class, new $class(['configService' => $config]), global: true);
    }
    $request = $this->createStub(Request::class);
    $request->method('getParam')->willReturnCallback(fn($key, $default = null) => $this->postData[$key] ?? $default);
    $request->method('offsetExists')->willReturnCallback(fn($key) => isset($this->postData[$key]));
    $request->method('offsetGet')->willReturnCallback(fn($key) => $this->postData[$key] ?? null);
    return new Controller\DocumentStorageUploadController(
      appName: $this->mockProvider->appName,
      request: $request,
      storageFactory: new StorageFactory($config),
      configService: $config,
      entityManager: $this->entityManager,
    );
  }

  /**
   * @param bool $invoice
   *
   * @return string
   */
  private function topic(bool $invoice): string
  {
    return $invoice
      ? Controller\DocumentStorageUploadController::FINANCE_TOPIC_INVOICES
      : Controller\DocumentStorageUploadController::FINANCE_TOPIC_DONATION_RECEIPTS;
  }

  /**
   * @param bool $invoice
   * @param int $id
   * @param string $content
   * @param Controller\EnumAddDocumentConflictAction $conflict
   *
   * @return void
   */
  private function uploadDocument(
    bool $invoice,
    int $id,
    string $content,
    Controller\EnumAddDocumentConflictAction $conflict = Controller\EnumAddDocumentConflictAction::REPLACE,
  ): void {
    $path = tempnam(sys_get_temp_dir(), 'financial-upload-');
    self::assertNotFalse($path);
    $this->uploadPaths[] = $path;
    self::assertSame(strlen($content), file_put_contents($path, $content));
    $this->postData['files'] = json_encode([[
      'name' => 'uploaded.txt', 'tmp_name' => $path,
      'size' => strlen($content), 'type' => 'text/plain', 'error' => UPLOAD_ERR_OK,
    ]], JSON_THROW_ON_ERROR);
    $response = $this->documentController()->documents(
      section: Controller\DocumentStorageUploadController::SECTION_FINANCE,
      topic: $this->topic($invoice),
      operation: Controller\DocumentStorageUploadController::DOCUMENT_ACTION_UPLOAD,
      musicianId: self::$musicianId,
      projectId: self::$projectId,
      data: ['optionKey' => $id],
      conflict: $conflict,
    );
    self::assertSame(200, $response->getStatus());
    self::assertFileDoesNotExist($path);
  }

  /**
   * @param bool $invoice
   * @param int $id
   *
   * @return void
   */
  private function deleteDocument(bool $invoice, int $id): void
  {
    $this->postData['optionKey'] = $id;
    $response = $this->documentController()->documents(
      section: Controller\DocumentStorageUploadController::SECTION_FINANCE,
      topic: $this->topic($invoice),
      operation: Controller\DocumentStorageUploadController::DOCUMENT_ACTION_DELETE,
      musicianId: self::$musicianId,
      projectId: self::$projectId,
    );
    self::assertSame(200, $response->getStatus());
  }

  /**
   * @param bool $invoice
   * @param int $id
   *
   * @return null|Entities\DatabaseStorageFile
   */
  private function reloadDocument(bool $invoice, int $id): ?Entities\DatabaseStorageFile
  {
    $this->entityManager->clear();
    $entity = $this->entityManager->find($invoice ? Entities\Invoice::class : Entities\DonationReceipt::class, $id);
    self::assertNotNull($entity);
    return $invoice ? $entity->getWrittenInvoice() : $entity->getSupportingDocument();
  }

  /**
   * @param bool $invoice
   *
   * @return Entities\DatabaseStorageFolder
   */
  private function participantDocumentFolder(bool $invoice): Entities\DatabaseStorageFolder
  {
    $folderName = $this->mockProvider->getConfigService()->getAppL10n()->t($invoice ? 'Invoices' : 'DonationReceipts');
    return $this->participant()->getDatabaseDocuments()->getRoot()->getFolderByName($folderName);
  }

  /**
   * @param bool $invoice
   * @param int $id
   * @param string $content
   * @param bool $owned
   *
   * @return Entities\DatabaseStorageFile
   */
  private function assertDocumentLinks(bool $invoice, int $id, string $content, bool $owned = true): Entities\DatabaseStorageFile
  {
    $document = $this->reloadDocument($invoice, $id);
    self::assertNotNull($document);
    $link = $this->participantDocumentFolder($invoice)->getFileByName($document->getName());
    self::assertNotNull($link);
    self::assertNotSame($document->getId(), $link->getId());
    self::assertSame($document->getFile(), $link->getFile());
    $root = $document->getParent();
    while ($root->getParent() !== null) {
      $root = $root->getParent();
    }
    self::assertSame($invoice ? 'finance/invoices/' : 'finance/receipts/donations/', $root->getStorage()->getStorageId());
    $file = $document->getFile();
    self::assertSame($content, $file->getFileData()->getData());
    self::assertCount($owned ? 1 : 0, $file->getOwners());
    self::assertSame($owned, $file->getOwners()->contains($this->participant()->getMusician()));
    $context = new ReflectionProperty(Entities\EncryptedFileData::class, 'encryptionContext')->getValue($file->getFileData());
    if ($owned) {
      self::assertContains($this->participant()->getMusician()->getUserIdSlug(), $context);
    } else {
      self::assertNotContains($this->participant()->getMusician()->getUserIdSlug(), $context);
    }
    $stored = $this->storedFileData($file->getId());
    self::assertNotSame('', $stored);
    self::assertStringNotContainsString($content, $stored);
    return $document;
  }

  /**
   * @param int $fileId
   *
   * @return string
   */
  private function storedFileData(int $fileId): string
  {
    $data = $this->entityManager->getConnection()->fetchOne('SELECT data FROM FileData WHERE file_id = ?', [$fileId]);
    self::assertNotFalse($data);
    return is_resource($data) ? stream_get_contents($data) : $data;
  }

  /**
   * This is quasi a tearDownAfterClass() but we need some mocked / stubbed
   * classes for the entity-manager.
   *
   * @return void
   */
  #[Attributes\Depends('testRoutesAreDefined')]
  #[Attributes\Depends('testFinancialDocumentLifecycle')]
  #[Attributes\Depends('testLinkingDoesNotGrantOwnershipAndDeletionPreservesOtherLinks')]
  #[Attributes\Depends('testParticipantCollisionRollsBackReplacement')]
  public function testUnapplyMigrations(): void
  {
    $this->unapplyMigrations();
    self::$migrationsApplied = false;
  }
}
