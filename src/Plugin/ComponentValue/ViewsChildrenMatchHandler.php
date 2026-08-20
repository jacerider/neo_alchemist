<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchField;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchFormContext;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchHandlerBase;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface;

/**
 * Handles `_view:<field>`: a column the view itself renders.
 *
 * The one handler a plugin contributes rather than the mapper owning it, and
 * the reason the registration point exists. A view can render a column that is
 * not a field on the row's entity at all — a rendered entity, a computed
 * field, an aggregate — so only the views provider knows the choices and how to
 * read them, and it registers this through
 * \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchFieldSourceInterface.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue
 */
final class ViewsChildrenMatchHandler extends ChildrenMatchHandlerBase {

  /**
   * Constructs a ViewsChildrenMatchHandler.
   *
   * @param \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue $viewsValue
   *   The views provider that owns the executed view and its rows.
   */
  public function __construct(
    protected ViewsValue $viewsValue,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'view';
  }

  /**
   * {@inheritdoc}
   */
  public function addOptions(array &$options, ChildrenMatchFormContext $context): void {
    if ($context->shape->getType() !== 'string') {
      return;
    }
    // The executed view is stashed on the form state by the source form, built
    // against the form's current (unsaved) settings.
    $view = $context->formState->get('view');
    if (!$view) {
      return;
    }
    $display = $view->getDisplay();
    /** @var \Drupal\views\Plugin\views\field\FieldPluginBase[] $fields */
    $fields = $display->getHandlers('field');
    foreach ($fields as $fieldName => $field) {
      $options['- Views -']['_view:' . $fieldName] = $field->adminLabel();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(ChildrenMatchField $field, ChildrenMatchMapper $mapper, ChildrenMatchSourceInterface $source): mixed {
    return $this->viewsValue->getViewRowFieldValue($field);
  }

}
