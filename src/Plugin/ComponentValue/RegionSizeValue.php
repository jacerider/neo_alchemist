<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentShapeRegionPluginInterface;
use Drupal\neo_alchemist\ComponentSizePluginManager;
use Drupal\neo_alchemist\ComponentSizesInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_modifier.
 */
#[ComponentValue(
  id: 'region_size',
  label: new TranslatableMarkup('Region Size'),
  description: new TranslatableMarkup('Select the sizes that are supported by this region.'),
  group: 'providers',
  ref_types: [
    'region',
  ],
  weight: 10,
)]
final class RegionSizeValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The size manager.
   *
   * @var \Drupal\neo_alchemist\ComponentSizePluginManager
   */
  protected ComponentSizePluginManager $sizeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    ComponentSizePluginManager $size_manager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->sizeManager = $size_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['shape'],
      $configuration['settings'],
      $container->get('plugin.manager.neo_component_size'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'sizes' => [],
    ];
  }

  /**
   * Configuration form for the value modifier plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['sizes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Sizes'),
      '#options' => $this->getSizesAsOptions(),
      '#default_value' => $this->configuration['sizes'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationMassage(array $values, array $form, FormStateInterface $form_state): array {
    $values['sizes'] = array_filter($values['sizes'] ?? []);
    return $values;
  }

  /**
   * Get sizes as options.
   *
   * @return array
   *   The sizes as options.
   */
  protected function getSizesAsOptions(): array {
    return array_map(
      fn ($definition) => $definition['label'],
      $this->sizeManager->getDefinitions()
    );
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    parent::onShapeInit();
    if ($this->shape instanceof ComponentSizesInterface) {
      $this->shape->setSizes($this->configuration['sizes']);
    }
  }

}
