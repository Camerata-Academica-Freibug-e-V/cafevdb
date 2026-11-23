<?php
/**
 * Orchestra member, musician and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Tests\Unit\EmailForm;

use DOMDocument;
use DOMXPath;
use OCA\CAFEVDB\EmailForm\Composer;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;

/** Verify that attachment diagnostics are rendered as text. */
#[Attributes\CoversNothing]
class StatusPageTest extends TestCase
{
  /** @return DOMXPath */
  private function renderStatusPage(array $message, array $attachmentValidation):DOMXPath
  {
    $diagnostics = [
      Composer::DIAGNOSTICS_TOTAL_COUNT => 1,
      Composer::DIAGNOSTICS_FAILED_COUNT => 0,
      Composer::DIAGNOSTICS_STAGE => Composer::DIAGNOSTICS_STAGE_SEND,
      Composer::DIAGNOSTICS_TEMPLATE_VALIDATION => [],
      Composer::DIAGNOSTICS_ADDRESS_VALIDATION => ['CC' => [], 'BCC' => [], 'Empty' => false],
      Composer::DIAGNOSTICS_SUBJECT_VALIDATION => true,
      Composer::DIAGNOSTICS_FROM_VALIDATION => true,
      Composer::DIAGNOSTICS_EXTERNAL_LINK_VALIDATION => ['status' => true],
      Composer::DIAGNOSTICS_SHARE_LINK_VALIDATION => [],
      Composer::DIAGNOSTICS_ATTACHMENT_VALIDATION => $attachmentValidation,
      Composer::DIAGNOSTICS_FAILED_RECIPIENTS => [],
      Composer::DIAGNOSTICS_MAILER_EXCEPTIONS => [],
      Composer::DIAGNOSTICS_DUPLICATES => [],
      Composer::DIAGNOSTICS_COPY_TO_SENT => [],
      Composer::DIAGNOSTICS_MESSAGE => $message,
    ];
    $l = new class {
      public function t(string $message, mixed $parameters = []):string
      {
        return $message;
      }

      public function n(string $singular, string $plural, int $count):string
      {
        return $count === 1 ? $singular : $plural;
      }
    };
    $appName = 'cafevdb';
    ob_start();
    try {
      include __DIR__ . '/../../../../templates/emailform/part.emailform.statuspage.php';
      $html = ob_get_contents();
    } finally {
      ob_end_clean();
    }

    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($document);
  }

  /** @return void */
  public function testAttachedEventAndFileNamesAreText():void
  {
    $event = 'Ä &amp; <img src=x onerror=alert(1)> "event"';
    $file = "résumé & <svg onload=alert(1)> 'file'.pdf";
    $xpath = $this->renderStatusPage(
      ['Text' => '', 'Events' => [$event], 'Files' => [$file]],
      ['Events' => [], 'Files' => []],
    );

    self::assertSame($event, $xpath->query('//div[contains(@class, "message events")]//li/span')->item(0)->textContent);
    self::assertSame($file, $xpath->query('//div[contains(@class, "message files")]//li/span')->item(0)->textContent);
    self::assertSame(0, $xpath->query('//img|//svg')->length);
  }

  /** @return void */
  public function testFailedAttachmentNamesAndEventIdsAreText():void
  {
    $original = '<img src=x onerror=alert(1)>';
    $temporary = 'résumé &amp; <svg onload=alert(1)>';
    $event = '<svg onload=alert(1)>';
    $xpath = $this->renderStatusPage(
      ['Text' => '', 'Events' => [], 'Files' => []],
      ['Events' => [$event], 'Files' => [['original_name' => $original, 'tmp_name' => $temporary]]],
    );

    self::assertSame($original, $xpath->query('//span[contains(@class, "original-name")]')->item(0)->textContent);
    self::assertSame(
      '(' . $temporary . ')',
      $xpath->query('//span[contains(@class, "tmp-name")]')->item(0)->textContent,
    );
    self::assertSame(
      $event,
      $xpath->query('//div[contains(@class, "attachments events")]//li/span')->item(0)->textContent,
    );
    self::assertSame(0, $xpath->query('//img|//svg')->length);
  }
}
