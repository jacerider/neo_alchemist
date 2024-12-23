<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapeExpandedPluginInterface;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'object',
  label: new TranslatableMarkup('Object'),
)]
class ObjectShape extends ChildrenShapeBase implements ComponentShapeExpandedPluginInterface {

  /**
   * The child shapes.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   */
  protected $childShapes;

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
  public function allowExpanded(): bool {
    return TRUE;
  }

  /**
   * {@inheritDoc}
   */
  public function getChildShapes(int $delta = 0): array {
    if (!isset($this->childShapes)) {
      $schema = $this->getSchema();
      $defaultValue = $this->getDefaultValue();
      // Merge in any examples to each property.
      foreach ($schema['properties'] as $propName => &$prop) {
        $prop['examples'] = $defaultValue[$propName] ?? $schema['examples'][$propName] ?? $prop['examples'] ?? [];
      }
      $this->childShapes = array_map(function ($shape) {
        if ($this->isSingleProp()) {
          $shape->setOptionDefaultAccess(FALSE);
        }
        return $shape->init();
      }, $this->getChildShapesFromSchema($schema));

      // Check if the object has required properties. If so, we allow the prop
      // to be set as empty.
      if (!empty(array_filter($this->childShapes, fn ($shape) => $shape->isRequired()))) {
        $logMessage = 'Object has children that are required and cannot be set as empty.';
        $this->getOptionEmpty()->setLockedValue(FALSE, $logMessage)->setAccess(FALSE, $logMessage);
      }
    }
    return $this->childShapes;
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
  public function adaptValue(mixed $value): mixed {
    $value = [];
    foreach ($this->getChildShapes() as $shape) {
      $value[$shape->getName()] = $shape->getValue();
      if ($shape->getOptionEmpty()->isEnabled() || empty($value[$shape->getName()])) {
        // Do not include empty values or values that are set to empty.
        unset($value[$shape->getName()]);
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
    if ($shapes) {
      $values = $this->getFieldItemValue();
      $form['#type'] = 'fieldset';
      $form['#title'] = $this->getTitle();
      $form['#description'] = $this->getDescription();
      $form['#description_display'] = 'before';
      foreach ($shapes as $shape) {
        // When in config scope, we only display forms if the child shape allows
        // plugins.
        if ($shape->getScope() === 'config' && $shape->allowPlugins()) {
          continue;
        }
        // Force values to allow nesting of multiple shapes.
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
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): array {
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
    return parent::massageFormValues($values, $original_values, $form, $form_state);
  }

}
