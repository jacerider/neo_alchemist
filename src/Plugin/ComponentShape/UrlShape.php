<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'url',
  label: new TranslatableMarkup('Url'),
  default_field_type: 'link',
  default_field_widget: 'neo_link',
)]
class UrlShape extends ComponentShapePluginBase {

  /**
   * Get the default widget settings.
   *
   * @return array
   *   The default widget settings.
   */
  protected function getDefaultWidgetSettings(): array {
    return [
      'icon' => FALSE,
      'wrapper_type' => 'container',
      'target' => TRUE,
    ];
  }

  /**
   * Get the default field instance settings.
   *
   * @return array
   *   The default field instance settings.
   */
  protected function getDefaultFieldInstaceSettings(): array {
    return [
      'title' => FALSE,
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldItemValue(): array {
    if (!$this->isFieldItemEmpty()) {
      /** @var \Drupal\link\Plugin\Field\FieldType\LinkItem $item */
      $item = $this->fieldItem;
      $value = $item->getValue();
      $value['access'] = $item->getUrl()->access();
      return $value;
    }
    return [];
  }

  /**
   * {@inheritDoc}
   */
  public function adaptValue(mixed $value): mixed {
    if (!empty($value)) {
      $value['access'] = $value['access'] ?? TRUE;
      // Use target if passed in with the options.
      if (empty($value['target']) && !empty($value['options']['attributes']['target'])) {
        $value['target'] = $value['options']['attributes']['target'];
      }
      $value['target'] = $value['target'] ?? '_self';
    }
    return $value;
  }

}
