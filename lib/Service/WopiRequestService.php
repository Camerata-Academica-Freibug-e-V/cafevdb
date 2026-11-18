<?php
/**
 * Orchestra member, musician and project management application.
 *
 * @author Copilot <223556219+Copilot@users.noreply.github.com>
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

namespace OCA\CAFEVDB\Service;

use OCA\Richdocuments\Middleware\WOPIMiddleware;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

/** Isolate richdocuments request classification from its authentication state. */
class WopiRequestService
{
  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    private IRequest $request,
    private ContainerInterface $appContainer,
  ) {
  }
  // phpcs:enable

  /** @return bool Whether the route looks like WOPI, without authenticating it. */
  public function isWopiRoute(): bool
  {
    // Services can be constructed before routing; do not rely on route parameters.
    return preg_match('~(?:^|/)apps/richdocuments/wopi(?:/|$)~', $this->request->getRawPathInfo()) === 1;
  }

  /** @return bool Whether richdocuments has validated this WOPI request. */
  public function isValidatedWopiRequest(): bool
  {
    return $this->appContainer->get(WOPIMiddleware::class)->isWOPIRequest();
  }
}
