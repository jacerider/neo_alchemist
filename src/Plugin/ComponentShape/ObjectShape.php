<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
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
    // Merge in any examples to each property.
    if (!empty($schema['properties'])) {
      $value = $this->getDefaultValue();
      foreach ($schema['properties'] as $propName => &$prop) {
        $prop['examples'] = $value[$propName] ?? $schema['examples'][$propName] ?? $prop['examples'] ?? [];
      }
    }
    return $schema;
  }

  /**
   * {@inheritDoc}
   */
  public function resolveValue(mixed $value): mixed {
    $value = parent::resolveValue($value);
    if (is_array($value) && $value) {
      $value = $this->resolveChildValues($value);
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  protected function buildValue(?Attribute $renderAttributes = NULL): mixed {
    $value = parent::buildValue($renderAttributes);
    if (empty($value) && !$this->allowExpanded()) {
      // When the parent value is empty and this shape cannot be expanded,
      // we return an empty value so none of the children get populted.
      return $value;
    }
    $forceChildDefaultValue = $this->forceChildDefaultValues();
    foreach ($this->getChildShapes(NULL, $value) as $shapeName => $shape) {
      if ($forceChildDefaultValue) {
        $value[$shapeName] = $shape->buildDefaultValue($renderAttributes);
      }
      else {
        $value[$shapeName] = $shape->getValue($renderAttributes);
      }
      if ($shape->isProvidedValueEmpty($value[$shapeName])) {
        // Do not include empty values or values that are set to empty. Empty
        // follows the value-emptiness contract, not empty(): 0, '0' and FALSE
        // are values an authored child can legitimately hold.
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
    // A child shape has no editability setting of its own and no UI that could
    // give it one — it only ever inherits the component's prop editability
    // mode. That mode answers a question about content editors, so in config
    // scope, where a site builder is authoring the component itself, it must
    // not decide whether a child's form is built. Left in, a "locked"
    // component's object props rendered no fields at all in the prop form
    // while its scalar props still rendered their Default value widget — the
    // mode does not otherwise blank that form, and there is nothing about an
    // object prop that should make it the exception.
    $editableOnly = $this->getScope() !== 'config';
    $shapes = $editableOnly
      ? array_filter($this->getChildShapes(), fn ($shape) => $shape->isEditable())
      : $this->getChildShapes();
    if (empty($shapes)) {
      $this->enforceLocked();
    }
    if ($shapes) {
      $values = $this->buildValue();
      $form['#type'] = 'fieldset';
      $form['#title'] = $this->getTitle();
      $form['#description'] = $this->getDescription();
      $form['#description_display'] = 'before';
      foreach ($shapes as $shape) {
        // When in config scope, we only display forms if the child shape allows
        // plugins.
        if (!$editableOnly && $shape->allowConfigurablePlugins()) {
          continue;
        }
        // Force values to allow nesting of multiple shapes. Only do this if the
        // parent value is an override and the shape is not expanded.
        if ($values[$shape->getName()] ?? NULL) {
          $shape->setFieldItemValue($values[$shape->getName()]);
        }
        $subform = [
          '#type' => 'container',
          '#parents' => array_merge($form['#parents']),
        ];
        $form[$shape->getName()] = $shape->getForm($subform, $form_state);
      }
    }
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function validateForm(array $form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach ($this->getChildShapes() as $shape) {
      if (isset($form[$shape->getName()])) {
        $subform_state = SubformState::createForSubform($form[$shape->getName()], $form, $form_state);
        $shape->validateForm($form[$shape->getName()], $subform_state);
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
