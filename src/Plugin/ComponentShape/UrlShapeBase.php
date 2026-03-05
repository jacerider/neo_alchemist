<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Template\Attribute;
use Drupal\neo\NeoLinkitFormatterTrait;
use Drupal\neo_alchemist\ComponentShapePluginBase;

/**
 * Base shape for URL-based components.
 */
class UrlShapeBase extends ComponentShapePluginBase {

  use NeoLinkitFormatterTrait;

  /**
   * Get the default widget settings.
   *
   * @return array
   *   The default widget settings.
   */
  protected function getDefaultWidgetSettings(): array {
    return [
      'icon' => FALSE,
      'target' => TRUE,
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getDefaultSchemaValue(): mixed {
    $value = parent::getDefaultSchemaValue();
    if (!is_array($value)) {
      $value = [
        'uri' => $value,
      ];
    }
    $value['options'] = $value['options'] ?? [];
    $value['icon'] = $value['icon'] ?? '';
    $value['target'] = $value['target'] ?? '_self';
    $value['access'] = $value['access'] ?? TRUE;
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldItemValue(): array {
    if (!$this->isFieldItemEmpty()) {
      /** @var \Drupal\link\Plugin\Field\FieldType\LinkItem $item */
      $item = $this->fieldItem;
      $value = $item->getValue();
      try {
        $value['access'] = $item->getUrl()->access();
      }
      catch (\Exception $e) {
        $value['access'] = $value['access'] ?? TRUE;
      }
      return $value;
    }
    return [];
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $value = parent::preRenderValue($value, $attributes);
    if (!empty($value)) {
      // Check if we have Linkit data attributes in options['attributes'].
      // The Neo Link widget stores them there, but getLinkitUrl() expects
      // them directly in options for proper entity URL substitution.
      $fieldItem = $this->getFieldItem();

      if (!empty($value['options']['attributes']['data-entity-type']) ||
          !empty($value['options']['attributes']['data-entity-uuid'])) {
        // Create a clone of the field item to avoid modifying the original.
        $fieldItem = clone $fieldItem;
        $itemValue = $fieldItem->getValue();
        $itemValue['options'] = $itemValue['options'] ?? [];

        // Move Linkit data from attributes to options root.
        foreach (['data-entity-type', 'data-entity-uuid', 'data-entity-substitution'] as $key) {
          if (isset($value['options']['attributes'][$key])) {
            $itemValue['options'][$key] = $value['options']['attributes'][$key];
          }
        }

        $fieldItem->setValue($itemValue);
      }

      if ($url = $this->getLinkitUrl($fieldItem)) {
        $value['uri'] = $url->toString();
      }

      // Use target if passed in with the options.
      if (empty($value['target']) && !empty($value['options']['attributes']['target'])) {
        $value['target'] = $value['options']['attributes']['target'];
      }
      $value['target'] = $value['target'] ?? '_self';
      unset($value['options']['attributes']);
    }
    return $value;
  }

  /**
   * Matches the field definition type with the entity field definition type.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   *
   * @return bool
   *   TRUE if the field definition types match, FALSE otherwise.
   */
  public function supportsFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool {
    return $entityFieldDefinition->getType() === 'link';
  }

}
