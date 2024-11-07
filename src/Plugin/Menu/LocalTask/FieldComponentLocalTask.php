<?php

declare(strict_types = 1);

namespace Drupal\neo_alchemist\Plugin\Menu\LocalTask;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handle a local task for entity components.
 */
class FieldComponentLocalTask extends LocalTaskDefault implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Constructs a new FieldComponentLocalTask object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityFieldManagerInterface $entityFieldManager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(Request $request = NULL) {
    $route = $this->routeProvider()->getRouteByName($this->getRouteName());
    $route->compile();
    $entityTypeId = $route->getDefault('entity_type_id');
    $bundle = $this->routeMatch->getParameter('bundle') ?? $entityTypeId;
    $field = $route->getDefault('field');
    $fieldDefinition = array_filter($this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle), fn($fieldDefinition) => $fieldDefinition->getName() === $field);
    if ($fieldDefinition) {
      $fieldDefinition = reset($fieldDefinition);
      return $fieldDefinition->getLabel();
    }
    return $this->pluginDefinition['title'];
  }

}
