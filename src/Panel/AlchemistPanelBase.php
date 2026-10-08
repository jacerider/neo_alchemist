<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Panel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Base class for Alchemist editor side panels.
 *
 * Supplies label() from the attribute and an access() that lets anyone who may
 * update the host entity in. A panel overrides access() to narrow that, and
 * implements build() and publish().
 */
abstract class AlchemistPanelBase extends PluginBase implements AlchemistPanelInterface {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function access(ComponentTreeItem $item, AccountInterface $account): AccessResultInterface {
    return $item->getEntity()->access('update', $account, TRUE);
  }

}
