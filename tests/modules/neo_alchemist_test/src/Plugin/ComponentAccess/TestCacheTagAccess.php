<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentAccess;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentAccess;
use Drupal\neo_alchemist\ComponentAccessPluginBase;

/**
 * An access plugin that tags its result, optionally forbidding.
 *
 * Exists to prove Component::checkAccess() folds the cacheability of every
 * consulted plugin into the result it returns — for both the neutral path
 * and the first-forbidden path.
 */
#[ComponentAccess(
  id: 'na_cache_tag_access',
  label: new TranslatableMarkup('Test cache tag access'),
  description: new TranslatableMarkup('Returns neutral or forbidden with a configurable cache tag.'),
)]
final class TestCacheTagAccess extends ComponentAccessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'forbid' => FALSE,
      'tag' => 'na_access_sentinel',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function access(string $op, AccountInterface $account): AccessResultInterface {
    $config = $this->getConfiguration();
    $result = empty($config['forbid'])
      ? AccessResult::neutral()
      : AccessResult::forbidden('Forbidden by na_cache_tag_access.');
    $result->addCacheTags([$config['tag'] ?? 'na_access_sentinel']);
    return $result;
  }

}
