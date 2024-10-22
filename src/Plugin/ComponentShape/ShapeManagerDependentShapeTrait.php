<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\neo_alchemist\ComponentShapePluginManager;
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
    bool $required,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TypedDataManagerInterface $typedDataManager,
    protected WidgetPluginManager $widgetManager,
    protected ComponentShapePluginManager $shapeManager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $schema, $required, $entityTypeManager, $typedDataManager, $widgetManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['schema'],
      $configuration['required'],
      $container->get('entity_type.manager'),
      $container->get(TypedDataManagerInterface::class),
      $container->get('plugin.manager.field.widget'),
      $container->get('plugin.manager.neo_component_shape')
    );
  }

}
