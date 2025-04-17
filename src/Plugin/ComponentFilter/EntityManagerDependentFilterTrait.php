<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentFilter;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_alchemist\ComponentFilterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A trait for adding entity type manager.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
trait EntityManagerDependentFilterTrait {

  use DependencySerializationTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentFilterInterface $filter,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $filter, $configuration);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['filter'],
      $configuration['settings'],
      $container->get('entity_type.manager'),
    );
  }

}
