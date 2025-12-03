<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentSlotPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'entity_display',
  label: new TranslatableMarkup('Entity Display'),
  description: new TranslatableMarkup('Render a entity display.'),
)]
final class EntityDisplay extends ComponentSlotPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The entity display repository service.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected EntityDisplayRepositoryInterface $entityDisplayRepository;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentInterface $component,
    string $uuid,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityDisplayRepositoryInterface $entity_display_repository,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $component, $uuid, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityDisplayRepository = $entity_display_repository;
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
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_display.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'bundle' => '',
      'display' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    $displayId = $this->configuration['display'];
    if ($displayId) {
      $options = $this->getDisplayOptions();
      $summary[] = $this->t('View mode: @display', [
        '@display' => $options[$displayId] ?? $displayId,
      ]);
    }
    return $summary;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    $displayId = $this->configuration['display'];
    $form['display'] = [
      '#type' => 'select',
      '#title' => $this->t('View mode'),
      '#options' => $this->getDisplayOptions(),
      '#default_value' => $displayId,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * Get display options for the target entity type.
   *
   * @return array
   *   An array of display options.
   */
  protected function getDisplayOptions(): array {
    $bundles = array_keys($this->entityTypeBundleInfo->getBundleInfo($this->component->getTargetEntityTypeId()));
    $options = [];
    foreach ($bundles as $bundle) {
      $options += $this->entityDisplayRepository->getViewModeOptionsByBundle($this->component->getTargetEntityTypeId(), $bundle);
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $entity = $this->component->getTargetEntity();
    $build = $this->entityTypeManager->getViewBuilder($this->component->getTargetEntityTypeId())->view($entity, $this->configuration['display']);
    // Hide the page title when rendering a full node view within a component.
    // @see neo_base_preprocess_node().
    // @see neo_base_preprocess_taxonomy_term().
    $build['#page'] = TRUE;
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentInterface $component) {
    return !empty($component->getTargetEntityTypeId());
  }

}
