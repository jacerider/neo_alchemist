<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentFilter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentFilter;
use Drupal\neo_alchemist\Filter\ComponentFilterPluginBase;

/**
 * Plugin implementation of the neo_component_filter.
 */
#[ComponentFilter(
  id: 'options',
  label: new TranslatableMarkup('Options'),
  description: new TranslatableMarkup('A pre-defined set of options.'),
  deriver: 'Drupal\neo_alchemist\Plugin\Derivative\OptionFilterDeriver',
)]
final class OptionFilter extends ComponentFilterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $is_default_form = FALSE): array {
    $form = parent::buildForm($form, $form_state, $is_default_form);
    $form['value']['#type'] = 'select';
    $form['value']['#title'] = $this->t('Select an option');
    $form['value']['#options'] = $this->getPluginDefinition()['options'];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(?string $value = NULL): mixed {
    if (!isset($this->getPluginDefinition()['options'][$value])) {
      if ($this->filter->hasDefaultValue()) {
        return $this->filter->getDefaultValue();
      }
      return NULL;
    }
    return $value;
  }

}
