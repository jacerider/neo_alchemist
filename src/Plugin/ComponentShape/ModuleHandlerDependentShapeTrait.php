<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A trait for adding the module handler.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
trait ModuleHandlerDependentShapeTrait {

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
    protected ModuleHandlerInterface $moduleHandler,
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
      $container->get('module_handler')
    );
  }

}
