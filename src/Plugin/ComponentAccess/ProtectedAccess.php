<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentAccess;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentAccess;
use Drupal\neo_alchemist\ComponentAccessPluginBase;

/**
 * Plugin implementation of the neo_component_access.
 */
#[ComponentAccess(
  id: 'protected',
  label: new TranslatableMarkup('Protected'),
  description: new TranslatableMarkup('Only users with the "use protected components" permission can manage this component.'),
)]
final class ProtectedAccess extends ComponentAccessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function access(string $op, AccountInterface $account): AccessResultInterface {
    if ($op !== 'view') {
      return AccessResult::forbiddenIf(!$account->hasPermission('use protected components'))->cachePerPermissions();
    }
    return AccessResult::neutral();
  }

}
