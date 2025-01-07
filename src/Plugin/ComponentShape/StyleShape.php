<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\ComponentShapeStylePluginInterface;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'style',
  label: new TranslatableMarkup('Style'),
  default_field_type: 'list_string',
  default_field_widget: 'options_select',
)]
class StyleShape extends ComponentShapePluginBase implements ComponentShapeStylePluginInterface {

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
  public function getFieldOptions(): ?array {
    if (array_key_exists('styles', $this->schema)) {
      return array_map(function ($style) {
        return $style['label'] ?? 'Unnamed';
      }, $this->schema['styles']);
    }
    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function modifyAttributes(Attribute $attributes) {
    if (array_key_exists('styles', $this->schema)) {
      if ($value = $this->getValue()) {
        if (isset($this->schema['styles'][$value]['value'])) {
          $attributes->addClass($this->schema['styles'][$value]['value']);
        }
      }
    }
  }

}
