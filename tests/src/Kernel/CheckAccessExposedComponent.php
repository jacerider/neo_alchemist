<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\Entity\Component;

/**
 * Exposes Component::checkAccess() for direct testing.
 *
 * A named subclass (no anonymous classes — phpcbf mangles them), constructed
 * from a saved component's toArray() so the settings.access entries written
 * by the test are live.
 */
final class CheckAccessExposedComponent extends Component {

  /**
   * Calls the protected checkAccess().
   *
   * @param string $operation
   *   The operation, e.g. 'view'.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   (optional) The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkAccessPublic(string $operation, ?AccountInterface $account = NULL): AccessResultInterface {
    return $this->checkAccess($operation, $account);
  }

}
