<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
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
final class AlchemistBlock extends BlockBase implements ContainerFactoryPluginInterface, TrustedCallbackInterface {

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
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      // When FALSE, the block renders without the surrounding block.html.twig
      // wrapper so its components sit directly at the root of the region.
      'wrapper' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $form['wrapper'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Wrap in block markup'),
      '#description' => $this->t('When disabled, the block is rendered without the surrounding block wrapper (block.html.twig) so its components sit directly at the root of the region.'),
      '#default_value' => $this->configuration['wrapper'] ?? TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $this->configuration['wrapper'] = (bool) $form_state->getValue('wrapper');
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

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['removeBlockWrapper'];
  }

  /**
   * Render API #pre_render callback: strips the block.html.twig wrapper.
   *
   * Runs after \Drupal\block\BlockViewBuilder::preRender() has moved the block
   * content into the 'content' child, so unsetting '#theme' leaves that content
   * to render on its own — no surrounding block markup.
   *
   * @param array $build
   *   The block render array.
   *
   * @return array
   *   The block render array without the '#theme' wrapper.
   *
   * @see neo_alchemist_block_block_view_neo_alchemist_block_alter()
   */
  public static function removeBlockWrapper(array $build): array {
    unset($build['#theme']);
    return $build;
  }

}
