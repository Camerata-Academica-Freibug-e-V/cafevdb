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
use OCA\CAFEVDB\Service\ParticipantFileAuditService;
use OCA\CAFEVDB\Tests\Unit\Database\Doctrine\ORM\Entities\EntityGeneratorTrait;
use OCA\CAFEVDB\Tests\Unit\Maintenance\Migrations\SetupMigrationTrait;
use OCA\CAFEVDB\Tests\Unit\Service\SetupCalendarBackendTrait;

/** Exercise audit queries against the migrated MariaDB schema. */
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
}
