<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;

/**
 * Plugin implementation of the neo_component_value_modifier.
 */
#[ComponentValue(
  id: 'link_title',
  label: new TranslatableMarkup('Link Title'),
  description: new TranslatableMarkup('Override a link title value.'),
  group: 'modifiers',
  inline: TRUE,
  ref_types: [
    'link',
  ],
  weight: 10,
)]
final class LinkTitleValue extends ComponentValuePluginBase {

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
      '#title' => $this->t('Title'),
      '#description' => $this->t('The title to set on the link. Tokens are supported, e.g. <code>View [term:name] Projects</code>.'),
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
    $value['title'] = $this->replaceEntityTokens((string) $this->configuration['value']);
    return $value;
  }

}
