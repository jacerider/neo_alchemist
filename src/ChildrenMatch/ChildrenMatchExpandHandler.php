<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ChildrenMatch;

use Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface;

/**
 * Handles `_expand`: map this same entity onto the child's own children.
 *
 * The child shape is itself a children-match shape, so its own child shapes are
 * mapped against the same entity — one level deeper in the shape id chain.
 */
final class ChildrenMatchExpandHandler extends ChildrenMatchHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'expand';
  }

  /**
   * {@inheritdoc}
   */
  public function addOptions(array &$options, ChildrenMatchFormContext $context): void {
    if ($context->shape->isExpandable()) {
      $options['- Shape -']['_expand'] = $this->t('Expand to configure child shapes');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array $configuration, ChildrenMatchFormContext $context): ?array {
    $shape = $context->shape;
    if (!$shape instanceof ComponentShapeChildrenMatchPluginInterface) {
      return $form;
    }
    $wrapperId = $form['#id'];
    foreach ($shape->getChildShapes() as $childShapeName => $childShape) {
      $form['shape_fields'][$childShapeName] = [
        '#id' => $wrapperId . '-' . $childShapeName,
        '#parents' => array_merge($form['#parents'], [
          'shape_fields',
          $childShapeName,
        ]),
      ];
      $form['shape_fields'][$childShapeName] = $context->mapper->buildChildForm($context->source, $childShape, $form['shape_fields'][$childShapeName], $context->formState, $context->scope, $configuration['shape_fields'][$childShapeName] ?? []);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(ChildrenMatchField $field, ChildrenMatchMapper $mapper, ChildrenMatchSourceInterface $source): mixed {
    if ($field->settings['shape_fields'] ?? []) {
      $childShapeNames = array_keys($field->settings['shape_fields']);
      return $mapper->fetchValues($source, $childShapeNames, $field->shape, [$field->entity], $field->settings, $field->shapeId, $mapper->isChildIterable($field->shape, $field->shapeId));
    }
    return NULL;
  }

}
