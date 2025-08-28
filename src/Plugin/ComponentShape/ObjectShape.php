<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapeExpandedPluginInterface;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentPropGeneratorInterface;
use DrupalCodeGenerator\InputOutput\Interviewer;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'object',
  label: new TranslatableMarkup('Object'),
)]
class ObjectShape extends ChildrenShapeBase implements ComponentShapeExpandedPluginInterface {

  /**
   * {@inheritDoc}
   */
  protected bool $optionEmptyInitAccess = TRUE;

  /**
   * {@inheritDoc}
   */
  protected function getDefaultFieldType(): string {
    return 'map';
  }

  /**
   * {@inheritDoc}
   */
  public function allowExpanded(): bool {
    return TRUE;
  }

  /**
   * Check if the schema is a single property.
   *
   * @return bool
   *   Whether the schema is a single property.
   */
  public function isSingleProp(): bool {
    return count($this->getSchema()['properties']) === 1;
  }

  /**
   * {@inheritDoc}
   */
  protected function getChildSchemaProperties(): array {
    $schema = $this->getSchema();
    return $schema['properties'] ?? [];
  }

  /**
   * {@inheritDoc}
   */
  protected function loadChildSchema(int|null $delta = NULL): array {
    $schema = $this->getSchema();
    // Use complete value instead of the getDefaultValue().
    $value = $this->getDefaultValue();
    // Merge in any examples to each property.
    foreach ($schema['properties'] as $propName => &$prop) {
      $prop['examples'] = $value[$propName] ?? $schema['examples'][$propName] ?? $prop['examples'] ?? [];
    }
    return $schema;
  }

  /**
   * {@inheritDoc}
   */
  public function isEditable(): bool {
    $editable = parent::isEditable();
    if ($editable && $this->isSingleProp()) {
      // If we only have a single property, we use that shapes access.
      foreach ($this->getChildShapes() as $shape) {
        if (!$shape->isEditable()) {
          return FALSE;
        }
      }
    }
    return $editable;
  }

  /**
   * {@inheritDoc}
   */
  protected function buildValue() {
    $value = parent::buildValue();
    if (empty($value) && !$this->allowExpanded()) {
      // When the parent value is empty and this shape cannot be expanded,
      // we return an empty value so none of the children get populted.
      return $value;
    }
    foreach ($this->getChildShapes(NULL, $value) as $shapeName => $shape) {
      $value[$shapeName] = $shape->getValue();
      if (empty($value[$shapeName])) {
        // Do not include empty values or values that are set to empty.
        unset($value[$shapeName]);
      }
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  protected function form(array $form, FormStateInterface $form_state): array {
    if ($this->getWidget()) {
      // Objects can specify a widget. When they do, we use that widget.
      return parent::form($form, $form_state);
    }
    $shapes = array_filter($this->getChildShapes(), fn ($shape) => $shape->isEditable());
    if (empty($shapes)) {
      $this->enforceLocked();
    }
    if ($shapes) {
      $values = $this->getFieldItemValue();
      $form['#type'] = 'fieldset';
      $form['#title'] = $this->getTitle();
      $form['#description'] = $this->getDescription();
      $form['#description_display'] = 'before';
      foreach ($shapes as $shape) {
        if (!$shape->isEditable()) {
          continue;
        }
        // When in config scope, we only display forms if the child shape allows
        // plugins.
        if ($shape->getScope() === 'config' && $shape->allowConfigurablePlugins()) {
          continue;
        }
        // Force values to allow nesting of multiple shapes. Only do this if the
        // parent value is an override and the shape is not expanded.
        if ($values[$shape->getName()] ?? NULL) {
          $shape->setFieldItemValue($values[$shape->getName()]);
        }
        $subform = [
          '#type' => 'container',
          '#parents' => $form['#parents'],
        ];
        $subform_state = SubformState::createForSubform($subform, $form, $form_state);
        $form[$shape->getName()] = $shape->getForm($subform, $subform_state);
      }
    }
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function validateForm(array $form, FormStateInterface $form_state, array $values): void {
    parent::validateForm($form, $form_state, $values);
    foreach ($this->getChildShapes() as $shape) {
      if (isset($form[$shape->getName()])) {
        $subform_state = SubformState::createForSubform($form[$shape->getName()], $form, $form_state);
        $shape->validateForm($form[$shape->getName()], $subform_state, $values[$shape->getName()] ?? []);
      }
    }
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): ?array {
    $values = parent::massageFormValues($values, $original_values, $form, $form_state);
    foreach ($this->getChildShapes() as $shape) {
      $shapeName = $shape->getName();
      $shapeValue = $values[$shapeName] ?? [];
      // If the shape value is an array, continue the massage process.
      if (isset($form[$shapeName]) && is_array($shapeValue)) {
        $subform_state = SubformState::createForSubform($form[$shapeName], $form, $form_state);
        unset($shapeValue['_options']);
        $shapeOriginalValues = $original_values[$shapeName] ?? [];
        if (!is_array($shapeOriginalValues)) {
          $shapeOriginalValues = [$shapeOriginalValues];
        }
        $values[$shapeName] = $shape->massageFormValues($shapeValue, $shapeOriginalValues, $form[$shapeName], $subform_state);
      }
    }
    return $values;
  }

  /**
   * {@inheritDoc}
   */
  public static function onGeneration(array &$prop, array $vars, Interviewer $ir, NeoComponentPropGeneratorInterface $generator, array $parents) {
    // Only act on object props.
    if ($prop['type'] !== 'object') {
      return;
    }
    $parents[$prop['name']] = $prop['title'];
    $parentLabels = implode(' → ', ['Root'] + $parents);
    if ($ir->confirm(\sprintf('%s: Need component props?', $parentLabels))) {
      $prop['properties'] = [];
      do {
        $objectProp = $generator->askProp($vars, $ir, $parents);
        $prop['properties'][] = $objectProp;
      } while ($ir->confirm(\sprintf('%s: Add another prop?', $parentLabels)));
    }
    $prop['examples'] = [];
    foreach ($prop['properties'] as $key => $p) {
      $prop['examples'][$p['name']] = $p['examples'] ?? '';
      if (!empty($p['examples'])) {
        $prop['properties'][$key]['examples'] = FALSE;
      }
    }
    $prop['properties'] = \array_filter($prop['properties'] ?? []);
  }

}
