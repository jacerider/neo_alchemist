<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'array',
  label: new TranslatableMarkup('Array'),
)]
class ArrayShape extends ComponentShapePluginBase {

  use ShapeManagerDependentShapeTrait;
  use StringTranslationTrait;

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
  protected function isSingleProp(): bool {
    return empty($this->getSchema()['items']['properties']);
  }

  /**
   * Get the max number of items.
   *
   * @return int
   *   The max number of items.
   */
  protected function getMaxItems(): int {
    return (int) ($this->getSchema()['maxItems'] ?? 0);
  }

  /**
   * Get the min number of items.
   *
   * @return int
   *   The min number of items.
   */
  protected function getMinItems(): int {
    return (int) ($this->getSchema()['minItems'] ?? 0);
  }

  /**
   * Get child shapes.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The child shapes.
   */
  protected function getChildShapes(int $delta): array {
    $schema = $this->getSchema();
    if (!empty($schema['items'])) {
      if ($this->isSingleProp()) {
        $schema['items']['properties']['value'] = [
          'type' => [$schema['items']['type']],
        ];
      }
      // Merge in any examples set on array.
      foreach ($schema['items']['properties'] as $propName => &$prop) {
        if ($this->isSingleProp()) {
          $prop['examples'] = $schema['examples'][$delta] ?? $prop['examples'] ?? [];
        }
        else {
          $prop['examples'] = $schema['examples'][$delta][$propName] ?? $prop['examples'] ?? [];
        }
      }
      return $this->shapeManager->getInstancesFromSchema($schema['items']);
    }
    return [];
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
      $shapes = $this->getChildShapes($delta);
      foreach ($shapes as $shape) {
        $itemValue = $value[$shape->getName()] ?? ($this->isSingleProp() ? $value : []);
        $shape->setFieldItemValue($itemValue);
        $keyedShapes[$delta][$shape->getName()] = $shape;
      }
    }
    return $keyedShapes;
  }

  /**
   * {@inheritDoc}
   */
  public function adaptValue(mixed $values): array|string|int|float|bool {
    $newValues = [];
    foreach ($this->getChildShapeList() as $delta => $shapes) {
      /** @var \Drupal\neo_alchemist\ComponentShapePluginInterface[] $shapes */
      foreach ($shapes as $shape) {
        $value = $shape->getValue();
        if ($value) {
          if ($this->isSingleProp()) {
            $newValues[$delta] = $value;
          }
          else {
            $newValues[$delta][$shape->getName()] = $value;
          }
        }
      }
    }
    return $newValues;
  }

  /**
   * {@inheritDoc}
   */
  public function getForm(array $form, FormStateInterface $form_state): ?array {
    $elements = [];

    $parents = array_merge($form['#parents'] ?? [], [$this->getName()]);
    $id = Html::getId('shape-' . implode('-', $parents));

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
    $elements = [
      '#type' => 'fieldset',
      '#title' => $this->getTitle(),
      '#description' => implode('<br>', $description),
      '#description_display' => 'before',
      '#required' => $this->isRequired(),
      '#tree' => TRUE,
      '#id' => $id,
      '#parents' => $parents,
    ];
    if (!empty($shapeList)) {
      foreach ($shapeList as $delta => $shapes) {
        /** @var \Drupal\neo_alchemist\ComponentShapePluginInterface[] $shapes */
        $elements[$delta] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['form--inline', 'pb-form-item'],
          ],
        ];
        foreach ($shapes as $shape) {
          $shapeForm = [
            '#parents' => array_merge($elements['#parents'], [$delta]),
          ];
          $shapeForm = $shape->getForm($shapeForm, $form_state);
          $elements[$delta][$shape->getName()] = $shapeForm;
        }
        $elements[$delta]['remove'] = [
          '#type' => 'submit',
          '#name' => $id . '-remove-' . $delta,
          '#value' => $this->t('Remove'),
          '#submit' => [[get_class($this), 'removeItemSubmit']],
          '#attributes' => [
            'class' => ['btn-xs'],
          ],
          '#limit_validation_errors' => [],
          '#disabled' => $count <= $min,
          '#parents' => [
            'shape_remove',
          ],
          '#ajax' => [
            'callback' => [get_class($this), 'removeItemAjax'],
            'wrapper' => $id,
          ],
        ];
      }
    }
    if (!$max || $count < $max) {
      $elements['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add'),
        '#submit' => [[get_class($this), 'addMoreSubmit']],
        '#limit_validation_errors' => [],
        '#parents' => [
          'shape_add',
        ],
        '#ajax' => [
          'callback' => [get_class($this), 'addMoreAjax'],
          'wrapper' => $id,
        ],
      ];
    }
    return $elements;
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
    $rowParents = array_slice($button['#array_parents'], 0, -1);
    $delta = end($rowParents);

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, $parents);
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
  public function massageFormValues(array $form, FormStateInterface $form_state, array $values): array {
    $newValues = [];
    foreach ($values as $delta => $value) {
      $shapes = $this->getChildShapes($delta);
      foreach ($shapes as $shape) {
        $newValues[$delta][$shape->getName()] = $shape->massageFormValues($form, $form_state, $value[$shape->getName()] ?? []);
      }
    }
    return $newValues;
  }

}
