<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist_block\AlchemistBlockInterface;
use Drupal\neo_alchemist_block\Entity\AlchemistBlockFieldConfig;
use Drupal\neo_alchemist_block\Plugin\Derivative\AlchemistBlockDeriver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the components of an Alchemist block config entity.
 */
#[Block(
  id: 'neo_alchemist_block',
  admin_label: new TranslatableMarkup('Alchemist block'),
  category: new TranslatableMarkup('Alchemist'),
  deriver: AlchemistBlockDeriver::class,
)]
final class AlchemistBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Loads the Alchemist block config entity for this derivative.
   *
   * @return \Drupal\neo_alchemist_block\AlchemistBlockInterface|null
   *   The Alchemist block, or NULL if it no longer exists.
   */
  protected function getAlchemistBlock(): ?AlchemistBlockInterface {
    return $this->entityTypeManager->getStorage('neo_alchemist_block')->load($this->getDerivativeId());
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $block = $this->getAlchemistBlock();
    if (!$block) {
      return AccessResult::forbidden('The Alchemist block no longer exists.');
    }
    return AccessResult::allowedIf($block->status())->addCacheableDependency($block);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $block = $this->getAlchemistBlock();
    if (!$block || !$block->hasComponentValues()) {
      return [];
    }
    // Read the field at entity scope: the item list auto-populates with the
    // block's component values, and the resulting ComponentEntity instances
    // delegate view access to the host access handler (so the block renders
    // for site visitors, unlike config-scope ComponentField instances).
    /** @var \Drupal\Core\Entity\ContentEntityInterface $host */
    $host = $this->entityTypeManager->getStorage(AlchemistBlockFieldConfig::HOST_ENTITY_TYPE_ID)->create([
      'type' => $block->id(),
    ]);
    /** @var \Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList $list */
    $list = $host->get(AlchemistBlockFieldConfig::FIELD_NAME);
    $build = $list->isEmpty() ? [] : $list->first()->toRenderable();
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($block)
      ->applyTo($build);
    return $build;
  }

}
