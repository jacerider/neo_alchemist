<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'page_title',
  label: new TranslatableMarkup('Page Title'),
  description: new TranslatableMarkup('Provide the page title as a value.'),
  group: 'providers',
  ref_types: [
    ComponentShapePluginInterface::STRING,
  ],
)]
final class PageTitleValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface {

  use ComponentValueTitleResolverTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'override' => TRUE,
    ] + $this->processingModeDefaultConfiguration();
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['override'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow override'),
      '#description' => $this->t('Will allow this value to be changed from the page title. If not checked, the page title will be used and will not be able to be changed.'),
      '#default_value' => $this->configuration['override'],
    ];

    $form = $this->buildProcessingModeForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return !empty($this->configuration['override']);
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    return $this->getPageTitle();
  }

}
