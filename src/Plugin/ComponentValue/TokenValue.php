<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;

/**
 * Plugin implementation of the neo_component_value_modifier.
 *
 * Builds a string prop from a token template resolved against the component's
 * target entity, e.g. "[term:name] Projects". A superset of prefix/suffix that
 * can pull any entity field or embed a token mid-string. Use the placeholder
 * "[value]" to reference the incoming value within the template.
 */
#[ComponentValue(
  id: 'token',
  label: new TranslatableMarkup('Token'),
  description: new TranslatableMarkup('Build the value from a token template resolved against the current entity.'),
  group: 'modifiers',
  inline: TRUE,
  prop_types: [
    ComponentShapePluginInterface::STRING,
  ],
  weight: 11,
)]
final class TokenValue extends ComponentValuePluginBase {

  use ComponentValueTokenTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'value' => '',
    ];
  }

  /**
   * Configuration form for the value modifier plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Template'),
      '#description' => $this->t('A token template, e.g. <code>[term:name] Projects</code>. Use <code>[value]</code> to include the incoming value.'),
      '#default_value' => $this->configuration['value'],
      '#required' => TRUE,
    ];
    $form['value'] = $this->attachTokenValidation($form['value']);
    $form['tokens'] = $this->buildTokenBrowser();
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function modifyValue(mixed $value): mixed {
    $template = (string) $this->configuration['value'];
    // Let the template weave in the value it is modifying.
    $template = str_replace('[value]', (string) $value, $template);
    return $this->replaceEntityTokens($template);
  }

}
