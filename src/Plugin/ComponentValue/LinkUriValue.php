<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentValuePluginBase;

/**
 * Plugin implementation of the neo_component_value_modifier.
 *
 * Sets a link's URL from a token template resolved against the component's
 * target entity, e.g. "internal:/projects?market=[term:tid]".
 */
#[ComponentValue(
  id: 'link_uri',
  label: new TranslatableMarkup('Link URL'),
  description: new TranslatableMarkup('Override a link URL value.'),
  group: 'modifiers',
  inline: TRUE,
  ref_types: [
    'link',
  ],
  weight: 10,
)]
final class LinkUriValue extends ComponentValuePluginBase {

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
      '#title' => $this->t('URL'),
      '#description' => $this->t('The URL to set on the link. Use a Drupal URI and tokens are supported, e.g. <code>internal:/projects?market=[term:tid]</code>.'),
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
  public function alterValue(mixed $value, string $type): mixed {
    $value['uri'] = $this->replaceEntityTokens((string) $this->configuration['value']);
    return $value;
  }

}
