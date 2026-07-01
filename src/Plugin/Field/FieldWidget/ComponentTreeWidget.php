<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A no-op widget for the 'neo_component_tree' field type.
 *
 * The component tree is not edited through a standard field widget. Instead, it
 * is managed on the dedicated Alchemist layout page (the "alchemist" link
 * template). This widget exists solely so that the field can be assigned a
 * valid widget plugin on the "Manage form display" page, which recent versions
 * of Drupal core require.
 *
 * It intentionally renders no editable element and never extracts or modifies
 * the stored value, so placing it in a form's content region cannot clear the
 * component tree.
 *
 * @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem
 */
#[FieldWidget(
  id: 'neo_component_tree',
  label: new TranslatableMarkup('Alchemist layout (managed separately)'),
  field_types: [
    'neo_component_tree',
  ],
)]
class ComponentTreeWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // This field is edited on the dedicated Alchemist layout page, not through
    // a field widget. Render nothing editable.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state) {
    // Do not touch the stored value. The component tree is managed on the
    // Alchemist layout page; extracting (empty) form values here would wipe the
    // stored tree and props.
  }

}
