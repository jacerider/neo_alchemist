<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Handles `_default`: contribute nothing, so the child keeps its SDC example.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatchMapper
 */
final class ChildrenMatchDefaultHandler extends ChildrenMatchHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'default';
  }

  /**
   * {@inheritdoc}
   */
  public function addOptions(array &$options, ChildrenMatchFormContext $context): void {
    $options['- Shape -']['_default'] = $this->t('Use Default');
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(ChildrenMatchField $field, ChildrenMatchMapper $mapper, ChildrenMatchSourceInterface $source): mixed {
    $field->shape->getChildShapeState()->setFlag($field->shapeId, ChildShapeState::USE_DEFAULT, TRUE);
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function removeChildWhenAbsent(): bool {
    return TRUE;
  }

}
