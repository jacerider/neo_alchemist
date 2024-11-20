<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValueProvider;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValueProvider;
use Drupal\neo_alchemist\ComponentValueProviderPluginBase;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValueProvider(
  id: 'entity',
  label: new TranslatableMarkup('Entity'),
  description: new TranslatableMarkup('Provide values from entity fields.'),
  entity_types: ['node.*', 'commerce_product.default'],
  weight: 5,
)]
final class EntityValueProvider extends ComponentValueProviderPluginBase {

  /**
   * {@inheritdoc}
   */
  public function modify(FieldItemInterface $item, bool &$stopProcessing) {
    if ($value = $item->getEntity()->label()) {
      $item->setValue($value);
      $stopProcessing = TRUE;
    }
    // $item->setValue('poops');
  }

  public function widgetFormAlter(array &$element, FormStateInterface $form_state, FieldItemInterface $fieldItem) {
    // ksm($element, $fieldItem->getValue());
    // $element['#theme_wrappers'][] = 'fieldset';
    // $element['hide'] = [
    //   '#type' => 'checkbox',
    //   '#title' => $this->t('Use Default Value'),
    //   // '#default_value' => empty($this->getValue()),
    //   // '#access' => empty(Element::children($form['widget']['selection'])) && !empty($this->getDefaultValue()),
    // ];
  }

}
