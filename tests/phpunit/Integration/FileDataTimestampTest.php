<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Integration;

use OCA\CAFEVDB\Database\Doctrine\ORM\Entities;
use OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations\SetupMigrationTrait;
use PHPUnit\Framework\TestCase;

/** Exercise file-data timestamps and their migration against the app database. */
class FileDataTimestampTest extends TestCase
{
  use SetupMigrationTrait;

  /** @return void */
  public function testMigrationPreservesLegacyContentsAndUnknownDates(): void
  {
    $this->applyMigrations('20261125161000');
    try {
      $connection = $this->entityManager->getConnection();
      $connection->executeStatement(
        "INSERT INTO Files (file_name, mime_type, size, type) VALUES ('legacy.txt', 'text/plain', 6, 'generic')",
      );
      $id = $connection->lastInsertId();
      $connection->executeStatement(
        "INSERT INTO FileData (file_id, data_hash, data, type) VALUES (?, ?, ?, 'generic')",
        [$id, md5('legacy'), 'legacy'],
      );

      $this->applyMigrations('latest');
      $row = $connection->fetchAssociative('SELECT data, created, updated FROM FileData WHERE file_id = ?', [$id]);
      self::assertSame('legacy', $row['data']);
      self::assertNull($row['created']);
      self::assertNull($row['updated']);

      $file = $this->entityManager->find(Entities\File::class, $id);
      $file->getFileData()->setData('changed');
      $this->entityManager->flush();
      $this->entityManager->clear();
      $file = $this->entityManager->find(Entities\File::class, $id);
      self::assertSame('changed', $file->getFileData()->getData());
      self::assertNull($file->getFileData()->getCreated());
      self::assertNotNull($file->getFileData()->getUpdated());

      $connection->executeStatement(
        "INSERT INTO Files (file_name, mime_type, size, type) VALUES ('new.txt', 'text/plain', 3, 'generic')",
      );
      $newId = $connection->lastInsertId();
      $connection->executeStatement(
        "INSERT INTO FileData (file_id, data_hash, data, type) VALUES (?, ?, ?, 'generic')",
        [$newId, md5('new'), 'new'],
      );
      $row = $connection->fetchAssociative('SELECT created, updated FROM FileData WHERE file_id = ?', [$newId]);
      self::assertNotNull($row['created']);
      self::assertNotNull($row['updated']);
    } finally {
      $this->unapplyMigrations();
    }
  }

  /** @return void */
  public function testFileDataSubtypesInheritTimestampsAndContentUpdatesAreRecorded(): void
  {
    $this->applyMigrations('latest');
    try {
      foreach ([Entities\FileData::class, Entities\ImageFileData::class, Entities\EncryptedFileData::class] as $class) {
        $metadata = $this->entityManager->getClassMetadata($class);
        self::assertTrue($metadata->hasField('created'));
        self::assertTrue($metadata->hasField('updated'));
      }

      foreach ([new Entities\File('plain.txt', 'before', 'text/plain'), new Entities\EncryptedFile('sealed.txt', 'before', 'text/plain')] as $file) {
        $this->entityManager->persist($file);
        $this->entityManager->flush();
        $id = $file->getId();
        $this->entityManager->getConnection()->executeStatement(
          "UPDATE FileData SET updated = '2000-01-01 00:00:00' WHERE file_id = ?",
          [$id],
        );
        $this->entityManager->clear();

        $file = $this->entityManager->find($file::class, $id);
        $data = $file->getFileData();
        $created = $data->getCreated();
        self::assertNotNull($created);
        self::assertSame('2000-01-01', $data->getUpdated()->format('Y-m-d'));
        $data->setData('after');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $file = $this->entityManager->find($file::class, $id);
        $data = $file->getFileData();
        self::assertSame('after', $data->getData());
        self::assertEquals($created, $data->getCreated());
        self::assertGreaterThan('2000-01-01', $data->getUpdated()->format('Y-m-d'));
      }
    } finally {
      $this->unapplyMigrations();
    }
  }
}
