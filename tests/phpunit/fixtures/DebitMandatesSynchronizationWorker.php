<?php
/**
 * Concurrent debit-mandate reconciliation worker for the database-backed tests.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../MockProvider.php';

use OCA\CAFEVDB\Storage\Database\DebitMandatesStorage;
use OCA\CAFEVDB\Storage\UserStorage;
use OCA\CAFEVDB\Tests\Fixtures\DebitMandatesWorkerProvider;
use OCA\CAFEVDB\Tests\Fixtures\DebitMandatesWorkerTestCase;

try {
  DebitMandatesWorkerProvider::$databaseConfig = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
  $testCase = new DebitMandatesWorkerTestCase('worker');
  $provider = DebitMandatesWorkerProvider::create($testCase);
  $provider->registerClassInstance(UserStorage::class, $testCase->userStorage(), global: true);
  $storage = new DebitMandatesStorage(['configService' => $provider->getConfigService()]);
  fwrite(STDOUT, "READY\n");
  fflush(STDOUT);
  if (trim(fgets(STDIN)) !== 'synchronize') {
    throw new RuntimeException('Unexpected worker command.');
  }
  $started = microtime(true);
  fwrite(STDOUT, "SYNCHRONIZING\n");
  fflush(STDOUT);
  $storage->synchronize();
  fwrite(STDOUT, 'DONE ' . (microtime(true) - $started) . "\n");
  fflush(STDOUT);
} catch (Throwable $exception) {
  fwrite(STDERR, (string)$exception . "\n");
  exit(1);
}
