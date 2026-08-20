<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\neo_alchemist\Event\ComponentValueEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Handles `_event`: ask a subscriber for the value.
 *
 * @see \Drupal\neo_alchemist\Event\ComponentValueEvent
 */
final class ChildrenMatchEventHandler extends ChildrenMatchHandlerBase {

  /**
   * Constructs a ChildrenMatchEventHandler.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   */
  public function __construct(
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'event';
  }

  /**
   * {@inheritdoc}
   */
  public function addOptions(array &$options, ChildrenMatchFormContext $context): void {
    $options['- Shape -']['_event'] = $this->t('Use Event');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array $configuration, ChildrenMatchFormContext $context): ?array {
    $form['info'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#value' => $this->t('Will call the <em>\Drupal\neo_alchemist\Event\ComponentValueEvent</em> to get the value.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(ChildrenMatchField $field, ChildrenMatchMapper $mapper, ChildrenMatchSourceInterface $source): mixed {
    $event = new ComponentValueEvent($field->shape, [], $field->entity, $field->delta, $field->shapeId);
    $this->eventDispatcher->dispatch($event, ComponentValueEvent::EVENT_NAME);
    $value = $event->getValue();
    $field->shape->addCacheableDependency($event);
    return $value;
  }

}
