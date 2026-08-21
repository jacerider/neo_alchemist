<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface;
use Drupal\neo_alchemist\EntityTypeSelectBuilder;
use Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProducerInterface;
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
final class EntityLoadValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ChildrenMatchSourceInterface, ComponentValueProducerInterface {

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
   * @var \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper
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
    ] + ChildrenMatchMapper::defaultConfiguration();
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
    return $this->childrenMatchMapper->buildConfigurationForm($this, $this->shape, $form, $form_state, $this->configuration);
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
    $form['entity_type'] = EntityTypeSelectBuilder::entityTypeSelect(
      $this->entityTypeManager,
      $entityTypeId,
      ['callback' => [static::class, 'refreshAjax'], 'wrapper' => $wrapperId],
      TRUE,
      ['#description' => $this->t('Scope this component to a specific entity type.')],
    );

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
