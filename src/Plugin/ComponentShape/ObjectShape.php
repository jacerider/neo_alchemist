<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'object',
  label: new TranslatableMarkup('Object'),
)]
class ObjectShape extends ComponentShapePluginBase {

  use ShapeManagerDependentShapeTrait;

  /**
   * {@inheritDoc}
   */
  protected function getDefaultFieldType(): string {
    return 'map';
  }

  /**
   * Get child shapes.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The child shapes.
   */
  protected function getChildShapes(): array {
    $shapes = $this->shapeManager->getInstancesFromSchema($this->getSchema());
    $values = $this->getFieldItemValue();
    foreach ($shapes as $shape) {
      $shape->setFieldItemValue($values[$shape->getName()] ?? []);
    }
    return $shapes;
  }

  /**
   * {@inheritDoc}
   */
  public function adaptValue(mixed $value): array|string|int|float|bool {
    foreach ($this->getChildShapes() as $shape) {
      if (isset($value[$shape->getName()])) {
        $value[$shape->getName()] = $shape->getValue();
        if (empty($value[$shape->getName()])) {
          unset($value[$shape->getName()]);
        }
      }
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public function getForm(array $form, FormStateInterface $form_state): ?array {
    $elements = [];
    if ($shapes = $this->getChildShapes()) {
      $parents = array_merge($form['#parents'] ?? [], [$this->getName()]);
      $elements = [
        '#type' => 'fieldset',
        '#title' => $this->getTitle(),
        '#description' => $this->getDescription(),
        '#description_display' => 'before',
        '#tree' => TRUE,
        '#parents' => $parents,
      ];
      $values = $this->getFieldItemValue();
      foreach ($shapes as $shape) {
        $shape->setFieldItemValue($values[$shape->getName()] ?? []);
        $elements[$shape->getName()] = $shape->getForm($elements, $form_state);
      }
    }
    return $elements;
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $form, FormStateInterface $form_state, array $values): array {
    foreach ($this->getChildShapes() as $shape) {
      $values[$shape->getName()] = $shape->massageFormValues($form, $form_state, $values[$shape->getName()] ?? []);
    }
    return $values;
  }

}
