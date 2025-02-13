<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapeInterablePluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'array',
  label: new TranslatableMarkup('Array'),
)]
class ArrayShape extends ChildrenShapeBase implements ComponentShapeInterablePluginInterface {

  /**
   * The single prop shape.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface|null
   */
  protected ?ComponentShapePluginInterface $singlePropShape;

  /**
   * {@inheritDoc}
   */
  public function init(): self {
    $this->getOptionEmpty()->setAccess(FALSE, 'Array shapes cannot be set as empty.');
    return parent::init();
  }

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
    return empty($this->getSchema()['items']['properties']);
  }

  /**
   * Get keyed child shapes.
   *
   * @param array|null $values
   *   (optional) The values to set on the child shapes. If empty, will use
   *   the field item value.
   *
   * @return array
   *   The child shapes keyed by delta.
   */
  protected function getChildShapeList($values = NULL): array {
    $keyedShapes = [];
    $values = $values ?? $this->getFieldItemValue();
    foreach ($values as $delta => $value) {
      if (is_int($delta)) {
        $shapes = $this->getChildShapes((int) $delta);
        foreach ($shapes as $shapeName => $shape) {
          $keyedShapes[$delta][$shapeName] = $shape;
        }
      }
    }
    return $keyedShapes;
  }

  /**
   * {@inheritDoc}
   */
  protected function getChildSchema(int|null $delta = 0): array {
    $schema = $this->getSchema();
    $defaultValue = $this->getDefaultValue();
    if (empty($schema['items'])) {
      return [];
    }
    if ($this->isSingleProp()) {
      $schema['items']['properties']['value'] = [
        'type' => [$schema['items']['type']],
      ];
    }
    // Merge in any examples set on array.
    foreach ($schema['items']['properties'] as $propName => &$prop) {
      if ($this->isSingleProp()) {
        $prop['examples'] = $defaultValue[$delta] ?? $schema['examples'][$delta] ?? $prop['examples'] ?? [];
      }
      else {
        $prop['examples'] = $defaultValue[$delta][$propName] ?? $schema['examples'][$delta][$propName] ?? $prop['examples'] ?? [];
      }
    }
    return $schema['items'];
  }

  /**
   * Get the single prop shape.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface|null
   *   The single prop shape.
   */
  protected function getSinglePropShape(): ?ComponentShapePluginInterface {
    if (!isset($this->singlePropShape)) {
      $this->singlePropShape = NULL;
      if ($this->isSingleProp()) {
        $shapes = $this->getChildShapes(0);
        $this->singlePropShape = reset($shapes);
      }
    }
    return $this->singlePropShape;
  }

  /**
   * {@inheritDoc}
   */
  public function getMaxItems(): int {
    return (int) ($this->getSchema()['maxItems'] ?? 0);
  }

  /**
   * {@inheritDoc}
   */
  public function getMinItems(): int {
    return (int) ($this->getSchema()['minItems'] ?? 0);
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(): mixed {
    $values = parent::getValue();
    if ($this->getOptionDefault()->isEnabled()) {
      return $values;
    }
    foreach ($this->getChildShapeList() as $delta => $shapes) {
      /** @var \Drupal\neo_alchemist\ComponentShapePluginInterface[] $shapes */
      foreach ($shapes as $shapeName => $shape) {
        $value = $shape->getValue();
        if ($value) {
          if ($this->isSingleProp()) {
            $values[$delta] = $value;
          }
          else {
            $values[$delta][$shapeName] = $value;
          }
        }
      }
    }
    return $values;
  }

  /**
   * {@inheritDoc}
   */
  public function adaptValue(mixed $values): mixed {
    if ($this->getOptionDefault()->isEnabled()) {
      return $values;
    }
    $newValues = [];
    foreach ($this->getChildShapeList() as $delta => $shapes) {
      /** @var \Drupal\neo_alchemist\ComponentShapePluginInterface[] $shapes */
      foreach ($shapes as $shapeName => $shape) {
        $value = $shape->getValue();
        if ($value) {
          if ($this->isSingleProp()) {
            $newValues[$delta] = $value;
          }
          else {
            $newValues[$delta][$shapeName] = $value;
          }
        }
      }
    }
    return $newValues;
  }

  /**
   * {@inheritDoc}
   */
  protected function form(array $form, FormStateInterface $form_state): array {
    $parents = array_merge($form['#parents'] ?? [], [$this->getName()]);
    $id = $form['#id'];

    $values = $form_state->get($id) ?? $this->getFieldItemValue();

    // A delta has been flagged for removal.
    $remove = $form_state->get($id . '-remove');
    if (!is_null($remove)) {
      unset($values[$remove]);
      $values = array_values($values);
      $form_state->set($id . '-remove', NULL);
    }

    // Ensure we have the requested item count.
    $pendingCount = $form_state->get($id . '-count');
    if (!is_null($pendingCount)) {
      $pendingCount = (int) $pendingCount;
      $values = array_slice($values, 0, $pendingCount);
      for ($i = 0; $i < $pendingCount; $i++) {
        if (!isset($values[$i])) {
          $values[] = [];
        }
      }
    }
    $form_state->set($id, $values);

    $shapeList = $this->getChildShapeList($values);
    $count = count($shapeList);
    $max = $this->getMaxItems();
    $min = $this->getMinItems();
    if (!$form_state->get($id . '-count')) {
      $form_state->set($id . '-count', $count);
    }
    $description[] = $this->getDescription();
    if ($max || $min) {
      if ($max && $min) {
        $description[] = $this->t('Must have between <strong>@min</strong> and <strong>@max</strong> items.', [
          '@min' => $min,
          '@max' => $max,
        ]);
      }
      elseif ($max) {
        $description[] = $this->t('Must have no more than <strong>@max</strong> items.', ['@max' => $max]);
      }
      else {
        $description[] = $this->t('Must have at least <strong>@min</strong> items.', ['@min' => $min]);
      }
    }

    $form['#type'] = 'fieldset';
    $form['#title'] = $this->getTitle();
    $form['#description'] = implode('<br>', $description);
    $form['#description_display'] = 'before';
    $form['#required'] = $this->isRequired();

    if (!empty($shapeList)) {
      foreach ($shapeList as $delta => $shapes) {
        /** @var \Drupal\neo_alchemist\ComponentShapePluginInterface[] $shapes */
        $form[$delta] = [
          '#type' => 'container',
        ];
        if ($min < $count) {
          $form[$delta]['delta'] = [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#attributes' => [
              'class' => ['badge bg-base text-base-content flex-none self-center !min-w-8'],
            ],
            '#value' => $delta + 1,
          ];
        }
        if ($max !== 1) {
          $form[$delta]['#attributes']['class'][] = 'form--inline mb-form-item';
        }
        foreach ($shapes as $shape) {
          $form[$delta][$shape->getName()] = [
            '#parents' => array_merge($form['#parents'], [$delta]),
          ];
          $subform_state = SubformState::createForSubform($form[$delta][$shape->getName()], $form, $form_state);
          $form[$delta][$shape->getName()] = $shape->getForm($form[$delta][$shape->getName()], $subform_state);
        }

        if ($min < $count) {
          $form[$delta]['remove'] = [
            '#type' => 'submit',
            '#name' => $id . '-remove-' . $delta,
            '#value' => $this->t('Remove'),
            '#description' => $this->t('Remove this item.'),
            '#widget_parents' => array_merge($form['#parents'], [$delta]),
            '#submit' => [[get_class($this), 'removeItemSubmit']],
            '#attributes' => [
              'class' => ['icon-only flex-none self-center !min-w-8'],
            ],
            '#limit_validation_errors' => [],
            '#disabled' => $count <= $min,
            '#parents' => array_merge(['remove_shape'], $parents, [$delta]),
            '#ajax' => [
              'callback' => [get_class($this), 'removeItemAjax'],
              'wrapper' => $id,
            ],
          ];
        }
      }
    }

    if (!$max || $count < $max) {
      $form['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add'),
        '#submit' => [[get_class($this), 'addMoreSubmit']],
        '#limit_validation_errors' => [],
        '#parents' => array_merge(['shape_add'], $parents),
        '#ajax' => [
          'callback' => [get_class($this), 'addMoreAjax'],
          'wrapper' => $id,
        ],
      ];
    }
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function validateForm(array $form, FormStateInterface $form_state, array $values): void {
    parent::validateForm($form, $form_state, $values);
    $shapeList = $this->getChildShapeList();
    foreach ($shapeList as $delta => $shapes) {
      foreach ($shapes as $shapeName => $shape) {
        if (isset($form[$delta][$shapeName])) {
          $subform_state = SubformState::createForSubform($form[$delta][$shapeName], $form, $form_state);
          $shape->validateForm($form[$delta][$shapeName], $subform_state, $values[$delta][$shapeName] ?? []);
        }
      }
    }
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): array {
    foreach ($values as $delta => $value) {
      $shapes = $this->getChildShapes((int) $delta);
      foreach ($shapes as $shapeName => $shape) {
        $shapeValue = $values[$delta][$shapeName] ?? [];
        // If the shape value is an array, continue the massage process.
        if (isset($form[$delta][$shapeName]) && is_array($shapeValue)) {
          $subform_state = SubformState::createForSubform($form[$delta][$shapeName], $form, $form_state);
          unset($shapeValue['_options']);
          $shapeOriginalValues = $original_values[$shapeName] ?? [];
          if (!is_array($shapeOriginalValues)) {
            $shapeOriginalValues = [$shapeOriginalValues];
          }
          $values[$delta][$shapeName] = $shape->massageFormValues($shapeValue, $shapeOriginalValues, $form[$delta][$shapeName], $subform_state);
        }
      }
    }
    return parent::massageFormValues($values, $original_values, $form, $form_state);
  }

  /**
   * Submission handler for the "Add another item" button.
   */
  public static function addMoreSubmit(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
    $count = ((int) $form_state->get($element['#id'] . '-count') ?: 0) + 1;
    $form_state->set($element['#id'] . '-count', $count);

    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the "Add another item" button.
   *
   * This returns the new page content to replace the page content made obsolete
   * by the form submission.
   */
  public static function addMoreAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
    return $element;
  }

  /**
   * Submission handler for the "Add another item" button.
   */
  public static function removeItemSubmit(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    $parents = array_slice($button['#array_parents'], 0, -2);
    $rowParents = $button['#widget_parents'];
    $parents = array_slice($rowParents, 0, -1);
    $delta = end($rowParents);

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -2));
    $form_state->set($element['#id'] . '-remove', $delta);

    // Decrement the count.
    $count = max(((int) $form_state->get($element['#id'] . '-count') ?: 0) - 1, 0);
    $form_state->set($element['#id'] . '-count', $count);

    // Removed the currently removed item from user input and re-index the
    // array.
    $userInput = $form_state->getUserInput();
    NestedArray::unsetValue($userInput, $rowParents);
    NestedArray::setValue($userInput, $parents, array_values(NestedArray::getValue($userInput, $parents)));
    $form_state->setUserInput($userInput);

    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the "Add another item" button.
   *
   * This returns the new page content to replace the page content made obsolete
   * by the form submission.
   */
  public static function removeItemAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -2));
    return $element;
  }

  /**
   * {@inheritDoc}
   */
  protected function getFieldDefinitionForSupportCheck(): FieldDefinitionInterface {
    if ($singlePropShape = $this->getSinglePropShape()) {
      // Use the single prop shape field definition.
      return $singlePropShape->getFieldItem()->getFieldDefinition();
    }
    // $definintions = [];
    // foreach ($this->getChildShapes() as $shape) {
    //   $definition = $shape->getFieldItem()->getFieldDefinition();
    //   $definintions[$definition->getName()] = $definition;
    // }
    // return $definitions;
    return parent::getFieldDefinitionForSupportCheck();
  }

}
