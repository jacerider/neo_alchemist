<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\ComponentShapeStylePluginInterface;

/**
 * A base class for style shapes.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
abstract class StyleShapeBase extends ComponentShapePluginBase implements ComponentShapeStylePluginInterface {

  // /**
  //  * {@inheritDoc}
  //  */
  // public function allowPlugins(): bool {
  //   return FALSE;
  // }

  // /**
  //  * {@inheritDoc}
  //  */
  // public function isExpandable(): bool {
  //   return FALSE;
  // }

  // /**
  //  * {@inheritDoc}
  //  */
  // protected function checkAccess(string $op, AccountInterface $account): AccessResultInterface {
  //   if ($op === 'update') {
  //     return AccessResult::forbidden('Style shape is not editable.');
  //   }
  //   return parent::checkAccess($op, $account);
  // }

  /**
   * {@inheritDoc}
   */
  public function modifyAttributes(Attribute $attributes) {
    if (array_key_exists('apply', $this->schema) && !empty($this->schema['apply'])) {
      $value = $this->getPropValue();
      if ($value instanceof Attribute) {
        $attributes->merge($value);
      }
    }
  }

}
