<?php

declare(strict_types = 1);

namespace Drupal\neo_alchemist\Plugin\Menu\LocalTask;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handle a local task for entity components.
 */
class EntityComponentLocalTask extends LocalTaskDefault implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Constructs a new FieldComponentLocalTask object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly RouteMatchInterface $routeMatch
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
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(Request $request = NULL) {
    $routeParameters = $this->getRouteParameters($this->routeMatch);
    $field = ComponentFieldConfig::getFieldnameFromKey($routeParameters['neo_field']);

    $entity = $this->getEntityFromRouteMatch();
    if ($entity instanceof ContentEntityInterface) {
      $field = $entity->getFieldDefinition($field);
      return $field->getLabel();
    }
    return $this->pluginDefinition['title'];
  }

  /**
   * Retrieves entity from route match.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity object as determined from the passed-in route match.
   */
  protected function getEntityFromRouteMatch() {
    $parameter_name = $this->routeMatch->getRouteObject()->getDefault('entity_type_id');
    return $this->routeMatch->getParameter($parameter_name);
  }

}
