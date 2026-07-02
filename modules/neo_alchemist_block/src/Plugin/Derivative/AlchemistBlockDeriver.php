<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block plugin derivative for each Alchemist block config entity.
 *
 * @see \Drupal\neo_alchemist_block\Plugin\Block\AlchemistBlock
 */
final class AlchemistBlockDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * Constructs an AlchemistBlockDeriver.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The Alchemist block entity storage.
   */
  public function __construct(
    protected EntityStorageInterface $storage,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager')->getStorage('neo_alchemist_block')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $this->derivatives = [];
    foreach ($this->storage->loadMultiple() as $entity) {
      /** @var \Drupal\neo_alchemist_block\AlchemistBlockInterface $entity */
      $this->derivatives[$entity->id()] = $base_plugin_definition;
      $this->derivatives[$entity->id()]['admin_label'] = $entity->label();
      $this->derivatives[$entity->id()]['config_dependencies']['config'] = [
        $entity->getConfigDependencyName(),
      ];
    }
    return $this->derivatives;
  }

}
