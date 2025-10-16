<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentFilter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentFilter;
use Drupal\neo_alchemist\ComponentFilterPluginBase;

/**
 * Plugin implementation of the neo_component_filter.
 */
#[ComponentFilter(
  id: 'number',
  label: new TranslatableMarkup('Number'),
  description: new TranslatableMarkup('A raw numeric value.'),
)]
final class NumberFilter extends ComponentFilterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $is_default_form = FALSE): array {
    $form = parent::buildForm($form, $form_state, $is_default_form);
    $form['value']['#type'] = 'number';
    return $form;
  }

}
