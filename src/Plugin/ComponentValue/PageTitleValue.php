<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

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
final class PageTitleValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use ComponentValueTitleResolverTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'override' => TRUE,
    ];
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
    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $form_state->setValue('override', !empty($form_state->getValue('override')));
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    parent::onShapeInit();
    $this->shape->getOptionDefault()->setValue(TRUE, 'Default page title to the default value.');
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
    $this->stopFurtherProcessing();
    return $this->getPageTitle();
  }

  /**
   * {@inheritdoc}
   */
  public function provideOverrideValue(mixed $value, mixed $defaultValue): mixed {
    $isDefault = $this->shape->getOptionDefault()->isEnabled();
    if ($isDefault) {
      $value = NULL;
      $this->stopFurtherProcessing();
    }
    return $value;
  }

}
