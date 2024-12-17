<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentShapePluginManager;
use Drupal\neo_alchemist\ComponentValueModifierPluginManager;
use Drupal\neo_alchemist\ComponentValueProviderPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A trait for adding entity type manager.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
trait ShapeManagerDependentShapeTrait {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    array $schema,
    array $settings,
    protected ComponentInterface $component,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TypedDataManagerInterface $typedDataManager,
    protected WidgetPluginManager $widgetManager,
    protected ComponentValueProviderPluginManager $valueProviderManager,
    protected ComponentValueModifierPluginManager $valueModifierManager,
    protected ComponentShapePluginManager $shapeManager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $schema, $settings, $component, $entityTypeManager, $typedDataManager, $widgetManager, $valueProviderManager, $valueModifierManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['schema'],
      $configuration['settings'],
      $configuration['component'],
      $container->get('entity_type.manager'),
      $container->get(TypedDataManagerInterface::class),
      $container->get('plugin.manager.field.widget'),
      $container->get('plugin.manager.neo_component_value_provider'),
      $container->get('plugin.manager.neo_component_value_modifier'),
      $container->get('plugin.manager.neo_component_shape')
    );
  }

}
