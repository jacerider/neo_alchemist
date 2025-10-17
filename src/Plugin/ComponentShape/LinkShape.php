<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentPropGeneratorInterface;
use DrupalCodeGenerator\InputOutput\Interviewer;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'link',
  label: new TranslatableMarkup('Link'),
  default_field_type: 'link',
  default_field_widget: 'neo_link',
)]
class LinkShape extends ComponentShapePluginBase {

  use ModuleHandlerDependentShapeTrait;

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
    $value['options'] = $value['otions'] ?? [];
    $value['icon'] = $value['icon'] ?? '';
    $value['target'] = $value['target'] ?? '_self';
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
        $value['access'] = TRUE;
      }
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
      if (empty($value['icon']) && !empty($value['options']['attributes']['data-icon'])) {
        $value['icon'] = $value['options']['attributes']['data-icon'];
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

  /**
   * {@inheritDoc}
   */
  public static function onGeneration(array &$prop, array $vars, Interviewer $ir, NeoComponentPropGeneratorInterface $generator, array $parents) {
    $prop['examples'] = [
      'uri' => 'internal:/',
      'title' => 'Example link',
    ];
  }

}
