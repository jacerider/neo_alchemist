<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatchResult;
use Drupal\neo_alchemist\ChildrenMatchScope;
use Drupal\neo_alchemist\ChildrenMatchSourceInterface;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'entity_load',
  label: new TranslatableMarkup('Entity Load'),
  description: new TranslatableMarkup('Load a specific entity by its ID to provide values from the loaded entity fields.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::OBJECT,
  ],
  weight: 5,
)]
final class EntityLoadValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ChildrenMatchSourceInterface {

  use DependencySerializationTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The children-match mapper.
   *
   * @var \Drupal\neo_alchemist\ChildrenMatchMapper
   */
  protected ChildrenMatchMapper $childrenMatchMapper;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    ChildrenMatchMapper $children_match_mapper,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->childrenMatchMapper = $children_match_mapper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['shape'],
      $configuration['settings'],
      $container->get('entity_type.manager'),
      $container->get('neo_alchemist.children_match_mapper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'entity_type' => '',
      'entity_id' => '',
    ] + ChildrenMatchMapper::defaultConfiguration()
      + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = [];
    if (($entityTypeId = $this->configuration['entity_type']) && $this->configuration['entity_id'] !== '') {
      $summary[] = $this->t('Loads @type #@id', [
        '@type' => $entityTypeId,
        '@id' => $this->configuration['entity_id'],
      ]);
    }
    return array_merge($summary, $this->childrenMatchMapper->summary($this->shape, $this->configuration));
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    assert($this->shape instanceof ComponentShapeChildrenMatchPluginInterface);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;
    $form = $this->childrenMatchMapper->buildConfigurationForm($this, $this->shape, $form, $form_state, $this->configuration);
    return $this->buildProcessingModeForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function buildChildrenMatchSourceForm(array &$form, FormStateInterface $form_state): ?ChildrenMatchScope {
    $wrapperId = $form['#id'];
    $entityTypeId = $this->configuration['entity_type'];

    $form['message'] = [
      '#markup' => $this->t('Content entities vary by environment. Only use this provider if you know the entity will always exist across all environments.'),
      '#prefix' => '<div class="messages messages--warning">',
      '#suffix' => '</div>',
    ];

    $entityTypes = $this->entityTypeManager->getDefinitions();
    $options = [];
    foreach ($entityTypes as $type) {
      if ($type instanceof ContentEntityTypeInterface) {
        $options[$type->id()] = $type->getLabel();
      }
    }
    asort($options);
    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity Type'),
      '#description' => $this->t('Scope this component to a specific entity type.'),
      '#default_value' => $entityTypeId,
      '#options' => $options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($entityTypeId && isset($entityTypes[$entityTypeId])) {
      $entityId = $this->configuration['entity_id'];
      $form['entity_id'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Entity ID'),
        '#target_type' => $entityTypeId,
        '#required' => TRUE,
        '#maxlength' => NULL,
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $wrapperId,
        ],
      ];

      if ($entityId) {
        $entity = $this->entityTypeManager->getStorage($entityTypeId)->load($entityId);
        $form['entity_id']['#default_value'] = $entity;
        if ($entity) {
          return new ChildrenMatchScope($entityTypeId, $entity->bundle());
        }
      }
    }
    return NULL;
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
  public function isEditable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    if (!$this->shape instanceof ComponentShapeChildrenMatchPluginInterface) {
      return $value;
    }
    // Can't act: the mapper passes the threaded value through rather than
    // wiping it to NULL.
    return $this->childrenMatchMapper->getValues($this, $this->shape, $this->configuration, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function getChildrenMatchEntities(): ChildrenMatchResult {
    $entityTypeId = $this->configuration['entity_type'];
    $entityId = $this->configuration['entity_id'];
    if ($entityTypeId && $entityId) {
      $entity = $this->entityTypeManager->getStorage($entityTypeId)->load($entityId);
      if ($entity) {
        $this->shape->addCacheableDependency($entity);
        return ChildrenMatchResult::of([$entity]);
      }
    }
    return ChildrenMatchResult::unavailable();
  }

}
