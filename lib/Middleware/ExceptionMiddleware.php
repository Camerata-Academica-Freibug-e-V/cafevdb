<?php
/**
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Middleware;

use Exception;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;
use OCA\CAFEVDB\Wrapped\OCA\RotDrop\Toolkit\Middleware\ExceptionMiddleware as ToolkitExceptionMiddleware;

/** Leave OCS errors to Nextcloud's OCS middleware. */
class ExceptionMiddleware extends ToolkitExceptionMiddleware
{
  /** {@inheritdoc} */
  public function afterException($controller, $methodName, Exception $exception)
  {
    if ($controller instanceof OCSController && $exception instanceof OCSException) {
      throw $exception;
    }

    return parent::afterException($controller, $methodName, $exception);
  }
}
