<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentSlotPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'block_plugin',
  label: new TranslatableMarkup('Block Plugin'),
  description: new TranslatableMarkup('Embed a config plugin.'),
)]
final class BlockPluginSlot extends ComponentSlotPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The block manager.
   *
   * @var \Drupal\Core\Block\BlockManagerInterface
   */
  protected BlockManagerInterface $blockManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentInterface $component,
    string $uuid,
    array $configuration,
    BlockManagerInterface $blockManager,
    RendererInterface $renderer,
    AccountInterface $currentUser,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $component, $uuid, $configuration);
    $this->blockManager = $blockManager;
    $this->renderer = $renderer;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['component'],
      $configuration['uuid'],
      $configuration['settings'],
      $container->get('plugin.manager.block'),
      $container->get('renderer'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'block_plugin' => NULL,
      'block_settings' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();

    $block_plugin = $this->configuration['block_plugin'];
    if ($block_plugin && $this->blockManager->hasDefinition($block_plugin)) {
      $plugin = $this->blockManager->createInstance($block_plugin, $this->configuration['block_settings']);
      $summary[] = $this->t('Block Plugin: @block_plugin', ['@block_plugin' => $plugin->label()]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    $definitions = $this->blockManager->getDefinitions();
    unset($definitions['broken']);
    $options = array_map(fn ($definition) => $definition['admin_label'], $definitions);
    asort($options);
    $block_plugin = $this->configuration['block_plugin'];

    $form['block_plugin'] = [
      '#type' => 'select',
      '#title' => $this->t('Block Plugin'),
      '#options' => $options,
      '#default_value' => $block_plugin,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];
    if ($block_plugin && $this->blockManager->hasDefinition($block_plugin)) {
      $plugin = $this->blockManager->createInstance($block_plugin, $this->configuration['block_settings']);
      $form['block_settings'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Block settings'),
        '#tree' => TRUE,
        '#parents' => array_merge($form['#parents'], ['block_settings']),
      ];
      $subform_state = SubformState::createForSubform($form['block_settings'], $form, $form_state);
      $form['block_settings'] = $plugin->buildConfigurationForm($form['block_settings'], $subform_state);
      $form['block_settings']['admin_label']['#access'] = FALSE;
      $form['block_settings']['label']['#access'] = FALSE;
      $form['block_settings']['label_display']['#access'] = FALSE;
      $form['block_settings']['context_mapping']['#access'] = FALSE;
      $form['block_settings']['#access'] = Element::getVisibleChildren($form['block_settings']) ? TRUE : FALSE;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    if (!empty($form['block_settings'])) {
      $plugin = $this->blockManager->createInstance($this->configuration['block_plugin'], $this->configuration['block_settings']);
      $subform_state = SubformState::createForSubform($form['block_settings'], $form, $form_state);
      $plugin->validateConfigurationForm($form['block_settings'], $subform_state);
    }
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $block_plugin = $this->configuration['block_plugin'];
    $block_settings = $this->configuration['block_settings'];
    if ($block_plugin && $this->blockManager->hasDefinition($block_plugin)) {
      $plugin = $this->blockManager->createInstance($block_plugin, $block_settings);
      $access_result = $plugin->access($this->currentUser);
      if (is_object($access_result) && $access_result->isForbidden() || is_bool($access_result) && !$access_result) {
        return NULL;
      }
      $build = [];
      $render = $plugin->build();
      if ($render && is_array($render) && Element::isVisibleElement($render)) {
        $build = $render;
        // Add the cache tags/contexts.
        $this->renderer->addCacheableDependency($build, $plugin);
      }
      return $build;
    }
    return NULL;
  }

}
