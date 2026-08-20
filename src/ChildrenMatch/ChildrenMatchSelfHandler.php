<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ChildrenMatch;

use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\ComponentShapeMediaPluginInterface;

/**
 * Handles `_self`: the iterated entity IS the media the child wants.
 *
 * Used when the iteration source yields entities a child shape can consume
 * directly — a media reference field iterated by entity_reference, a media
 * entity query, etc. The media shape's own converter builds the value, so the
 * child receives the same structure a stored media reference would produce.
 */
final class ChildrenMatchSelfHandler extends ChildrenMatchHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'self';
  }

  /**
   * {@inheritdoc}
   */
  public function addOptions(array &$options, ChildrenMatchFormContext $context): void {
    // The iterated entity is itself a media entity a media shape can consume
    // (e.g. a media reference field used as the iteration source). Bundle
    // support is deliberately not checked here — the placeholder bundle is
    // unreliable for multi-bundle references — the strict check happens at
    // fetch time.
    if ($context->shape instanceof ComponentShapeMediaPluginInterface && $context->scope->entityTypeId === 'media') {
      $options['- Shape -']['_self'] = $this->t('This entity');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(ChildrenMatchField $field, ChildrenMatchMapper $mapper, ChildrenMatchSourceInterface $source): mixed {
    if (!$field->entity instanceof MediaInterface) {
      return NULL;
    }
    $childShape = $mapper->getChildShapeById($field->shape, $field->shapeId);
    if (!$childShape instanceof ComponentShapeMediaPluginInterface || !in_array($field->entity->bundle(), $childShape->getSupportedMediaTypes(), TRUE)) {
      return NULL;
    }
    return $childShape->getValueFromMedia($field->entity) ?: NULL;
  }

}
