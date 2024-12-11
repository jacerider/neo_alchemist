<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginBase;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'object',
  label: new TranslatableMarkup('Object'),
)]
class ObjectShape extends ComponentShapePluginBase implements ComponentShapeChildrenPluginInterface {

  use ShapeManagerDependentShapeTrait;

  /**
   * {@inheritDoc}
   */
  protected function getDefaultFieldType(): string {
    return 'map';
  }

  /**
   * {@inheritDoc}
   */
  public function getChildShapes(int $delta = 0): array {
    $shapes = $this->shapeManager->getInstancesFromSchema($this->getSchema(), $this->getComponent());
    $values = $this->getFieldItemValue();
    foreach ($shapes as $shape) {
      $shape->setNested()->setFieldItemValue($values[$shape->getName()] ?? []);
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
  protected function form(array $form, FormStateInterface $form_state): array {
    $widget = $this->getWidget();
    if ($widget) {
      // Objects can specify a widget. When they do, we use that widget.
      return parent::form($form, $form_state);
    }
    if ($shapes = $this->getChildShapes()) {
      // $parents = array_merge($form['#parents'], [$this->getName()]);
      $values = $this->getFieldItemValue();
      $form['#type'] = 'fieldset';
      $form['#title'] = $this->getTitle();
      $form['#description'] = $this->getDescription();
      $form['#description_display'] = 'before';
      foreach ($shapes as $shape) {
        $shape->setFieldItemValue($values[$shape->getName()] ?? []);
        $form[$shape->getName()] = [
          '#parents' => $form['#parents'],
        ];
        $subform_state = SubformState::createForSubform($form[$shape->getName()], $form, $form_state);
        $form[$shape->getName()] = $shape->getForm($form[$shape->getName()], $subform_state);
      }
    }
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    foreach ($this->getChildShapes() as $shape) {
      $shapeValue = $values[$shape->getName()] ?? [];
      // If the shape value is an array, continue the massage process.
      if (is_array($shapeValue) && isset($form[$shape->getName()])) {
        $subform_state = SubformState::createForSubform($form[$shape->getName()], $form, $form_state);
        $values[$shape->getName()] = $shape->massageFormValues($shapeValue, $form[$shape->getName()], $subform_state);
      }
    }
    return parent::massageFormValues($values, $form, $form_state);
  }

}
