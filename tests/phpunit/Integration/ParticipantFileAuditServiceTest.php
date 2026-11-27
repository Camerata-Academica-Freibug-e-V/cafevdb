<?php
/**
 * CAFEVDB -- Orchestra Management App.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use OCA\CAFEVDB\Database\Doctrine\ORM\Entities\EncryptedFile;
use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Service\ParticipantFileAuditService;
use OCA\CAFEVDB\Service\ParticipantFileRepairService;
use OCA\CAFEVDB\PageRenderer\DatabaseTables;
use OCA\CAFEVDB\Tests\Unit\Database\Doctrine\ORM\Entities\EntityGeneratorTrait;
use OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations\SetupMigrationTrait;
use OCA\CAFEVDB\Tests\Unit\Service\SetupCalendarBackendTrait;

/** Exercise audit queries and ORM repairs against the migrated MariaDB schema. */
#[CoversNothing]
class ParticipantFileAuditServiceTest extends TestCase
{
  use EntityGeneratorTrait;
  use SetupMigrationTrait;
  use SetupCalendarBackendTrait;

  /** @return void */
  public function testEncryptionDiagnosticsReadBinarySealMetadata(): void
  {
    $this->generateCalendarBackend();
    $this->applyMigrations('latest');
    try {
      $this->generateProjectParticipant(persist: true, now: new DateTimeImmutable('2099-01-01'), delete: false);
      $userId = $this->musician->getUserIdSlug();
      $file = new EncryptedFile('audit-test', 'initial', 'application/octet-stream', $this->musician);
      $this->entityManager->beginTransaction();
      $this->entityManager->persist($file);
      $this->entityManager->flush();
      $this->entityManager->commit();

      $connection = $this->entityManager->getConnection();
      $service = new ParticipantFileAuditService($connection, 0);
      $status = static fn(string $id): array => [
        'user_exists' => true,
        'logged_in' => true,
        'public_key_present' => true,
        'private_key_present' => true,
      ];
      foreach ([
        ["00000004|\0xyz|" . $userId . ':secret-key', 'sealed', true],
        ["00000004|\0xyz!$userId:secret-key", 'invalid-seal', null],
        ['00000004|x', 'invalid-seal', null],
      ] as [$data, $expectedStatus, $expectedRecipient]) {
        $connection->executeStatement('UPDATE FileData SET data = ? WHERE file_id = ?', [$data, $file->getId()]);
        $report = $service->audit(includeFileNames: true, includeFieldNames: true, userStatus: $status);
        $diagnostics = array_values(array_filter(
          $report['encryption_diagnostics'],
          static fn(array $diagnostic): bool => $diagnostic['file_id'] === $file->getId(),
        ));
        self::assertCount(1, $diagnostics);
        self::assertSame($expectedStatus, $diagnostics[0]['seal_status']);
        self::assertSame($expectedRecipient, $diagnostics[0]['recipient_present']);
        self::assertStringNotContainsString('secret-key', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertSame($data, $connection->fetchOne('SELECT data FROM FileData WHERE file_id = ?', [$file->getId()]));
      }
    } finally {
      $this->unapplyMigrations();
    }
  }

  /** @return void */
  public function testRepairCreatesFileEntriesThroughOrmAndRecordsCacheChanges(): void
  {
    $this->generateCalendarBackend();
    $this->applyMigrations('latest');
    try {
      $this->generateProjectParticipant(persist: true, now: new DateTimeImmutable('2099-01-01'), delete: false);
      $connection = $this->entityManager->getConnection();
      $storageId = 'integration/participant-repair/' . bin2hex(random_bytes(8));
      $participantRoot = (new Entities\DatabaseStorageFolder)->setName('');
      $storage = (new Entities\DatabaseStorage)->setStorageId($storageId)->setRoot($participantRoot);
      $participantRoot->setStorage($storage);
      $this->participant->setDatabaseDocuments($storage);
      $mandatesFolder = $this->mockProvider->getL10N()->t('DebitMandates');

      $orphanRoot = (new Entities\DatabaseStorageFolder)->setName('orphan');
      $file = new EncryptedFile('mandate.pdf', 'signed mandate', 'application/pdf', $this->musician);
      $orphanEntry = $orphanRoot->addDocument($file);
      $mandate = (new Entities\SepaDebitMandate)
        ->setMusician($this->musician)
        ->setProject($this->project)
        ->setSepaBankAccount($this->musician->getSepaBankAccounts()->first())
        ->setMandateReference('REVOKED-REPAIR')
        ->setSequence(1)
        ->setNonRecurring(false)
        ->setMandateDate('2099-01-01')
        ->setDeleted(new DateTimeImmutable('2000-01-01'))
        ->setWrittenMandate($orphanEntry);
      $this->entityManager->persist($storage);
      $this->entityManager->persist($orphanRoot);
      $this->entityManager->persist($mandate);
      $this->entityManager->flush();
      $orphanEntryId = $orphanEntry->getId();
      $participantRootId = $participantRoot->getId();

      $service = new ParticipantFileRepairService(
        $connection,
        0,
        fn(array $finding): array => [$mandatesFolder],
        function(int $rootId, array $folders, string $fileName, int $fileId): int {
          $folder = $this->entityManager->find(Entities\DatabaseStorageFolder::class, $rootId);
          if ($folder === null) {
            throw new \RuntimeException('The participant storage root disappeared during test repair.');
          }
          foreach ($folders as $name) {
            $folder = $folder->addSubFolder($name);
          }
          $fileEntity = $this->entityManager->find(Entities\EncryptedFile::class, $fileId);
          if ($fileEntity === null) {
            throw new \RuntimeException('The encrypted file disappeared during test repair.');
          }
          $entry = $folder->addDocument($fileEntity, $fileName);
          if (!$entry instanceof Entities\DatabaseStorageFile) {
            throw new \RuntimeException('The ORM did not create the participant file entry.');
          }
          $this->entityManager->persist($entry);
          $this->entityManager->flush();
          return (int)$entry->getId();
        },
      );
      $report = $service->repair();

      self::assertSame([], $report['remaining']['findings']);
      self::assertNotSame(
        $orphanEntryId,
        (int)$connection->fetchOne('SELECT written_mandate_id FROM SepaDebitMandates WHERE sequence = ?', [1]),
      );
      $deleted = $connection->fetchOne('SELECT deleted FROM SepaDebitMandates WHERE sequence = ?', [1]);
      self::assertSame('2000-01-01', (new DateTimeImmutable((string)$deleted))->format('Y-m-d'));
      $paths = $connection->fetchFirstColumn(
        'SELECT path FROM ' . DatabaseTables::DATABASE_STORAGE_CACHE_CHANGES_TABLE . ' WHERE storage_id = ?',
        [$storageId],
      );
      self::assertContains($mandatesFolder . '/mandate.pdf', $paths);
      self::assertSame(
        1,
        (int)$connection->fetchOne(
          'SELECT COUNT(*) FROM DatabaseStorageDirEntries WHERE id = ? AND parent_id IS NOT NULL',
          [(int)$connection->fetchOne('SELECT written_mandate_id FROM SepaDebitMandates WHERE sequence = ?', [1])],
        ),
      );
      self::assertNotSame(0, $participantRootId);
    } finally {
      $this->unapplyMigrations();
    }
  }
}
