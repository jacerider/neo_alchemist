<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ChildrenMatch;

use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\Match\MatcherReference;

/**
 * Handles `_reference~key`: map the entities that reference field points at.
 *
 * The nested level maps a DIFFERENT entity from the one being iterated, so it
 * gets its own scope built from the referenced entity's type and bundle.
 */
final class ChildrenMatchReferenceHandler extends ChildrenMatchHandlerBase {

  /**
   * Constructs a ChildrenMatchReferenceHandler.
   *
   * @param \Drupal\neo_alchemist\Match\MatcherReference $matcherReference
   *   The reference matcher: lists reference fields and follows them.
   */
  public function __construct(
    protected MatcherReference $matcherReference,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'reference';
  }

  /**
   * {@inheritdoc}
   */
  public function addOptions(array &$options, ChildrenMatchFormContext $context): void {
    // An array child shape is filled by iterating a reference field rather than
    // by reading one, so its choices are reference fields. A non-iterable shape
    // reads a field through the browser instead and is offered none here.
    if (!$context->shape->isIterable()) {
      return;
    }
    $scope = $context->scope;
    $refOptions = $this->matcherReference->getReferencesAsOptions($scope->entityTypeId, $scope->bundle);
    foreach ($refOptions as $group => $refs) {
      foreach ($refs as $refKey => $refLabel) {
        $options[$group]['_reference~' . $refKey] = $refLabel;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array $configuration, ChildrenMatchFormContext $context): ?array {
    $shape = $context->shape;
    $parts = explode('~', $configuration['field'] ?? '');
    $entityKey = $parts[1] ?? '';
    $referenceEntity = $this->matcherReference->getReferenceEntityByEntityType($context->scope->entityTypeId, $entityKey);
    if ($referenceEntity && $shape instanceof ComponentShapeChildrenMatchPluginInterface) {
      // The nested level maps a DIFFERENT entity, so it gets its own scope.
      // Reusing the outer one offered the wrong entity's fields.
      $referenceScope = new ChildrenMatchScope($referenceEntity->getEntityTypeId(), $referenceEntity->bundle());
      $form = $context->mapper->buildMappingForm($context->source, $shape, $form, $context->formState, $referenceScope, $configuration);
    }
    $form['#type'] = 'details';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(ChildrenMatchField $field, ChildrenMatchMapper $mapper, ChildrenMatchSourceInterface $source): mixed {
    if ($field->settings['shape_fields'] ?? []) {
      $entityKey = explode('~', $field->settings['field'])[1];
      $referenceField = $this->matcherReference->getReferenceField($field->entity, $entityKey, $field->shape->getCacheableMetadata());
      if ($referenceField) {
        $childShapeNames = array_keys($field->settings['shape_fields']);
        return $mapper->fetchValues($source, $childShapeNames, $field->shape, $referenceField->referencedEntities(), $field->settings, $field->shapeId, $mapper->isChildIterable($field->shape, $field->shapeId));
      }
    }
    return NULL;
  }

}
