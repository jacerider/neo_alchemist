<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentFilterPluginEntityInterface;
use Drupal\neo_alchemist\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatchResult;
use Drupal\neo_alchemist\ChildrenMatchScope;
use Drupal\neo_alchemist\ChildrenMatchSourceInterface;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\MatcherReference;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'entity_filter',
  label: new TranslatableMarkup('Entity Filter'),
  description: new TranslatableMarkup('Use the results of a component filter to provide values from the filtered entity fields.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::ARRAY,
    ComponentShapePluginInterface::OBJECT,
  ],
  weight: 5,
)]
final class EntityFilterValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ChildrenMatchSourceInterface {

  use DependencySerializationTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The reference matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherReference
   */
  protected MatcherReference $matcherReference;

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
    MatcherReference $matcher_reference,
    ChildrenMatchMapper $children_match_mapper,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->matcherReference = $matcher_reference;
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
      $container->get('neo_alchemist.matcher_reference'),
      $container->get('neo_alchemist.children_match_mapper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'filter' => '',
      'entity' => '',
    ] + ChildrenMatchMapper::defaultConfiguration()
      + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_BLOCK;
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
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
    $filters = array_filter($this->shape->getComponent()->getFilters(), fn($filter) => $filter->getPlugin() instanceof ComponentFilterPluginEntityInterface);
    if ($filters) {
      $filterId = $this->configuration['filter'] ?? '';
      $options = [
        'all' => $this->t('- Select -'),
      ] + array_map(fn($filter) => $filter->label(), $filters);
      $form['filter'] = [
        '#type' => 'select',
        '#title' => $this->t('Filter'),
        '#description' => $this->t('Select a filter to use for this value provider.'),
        '#options' => $options,
        '#default_value' => $filterId,
        '#required' => TRUE,
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $wrapperId,
        ],
      ];
      if ($filterId) {
        $filter = $this->shape->getComponent()->getFilter($filterId);
        if (!$filter) {
          $form['message']['#markup'] = $this->t('The selected filter does not exist.');
        }
        else {
          /** @var \Drupal\neo_alchemist\ComponentFilterPluginEntityInterface $plugin */
          $plugin = $filter->getPlugin();
          $entityTypeId = $plugin->getEntityTypeId();
          $bundles = $plugin->getEntityBundles();
          $bundle = count($bundles) > 1 ? NULL : reset($bundles);

          $options = $this->matcherReference->getReferencesAsOptions($entityTypeId, $bundle);
          $entityKey = $this->configuration['entity'];
          $form['entity'] = [
            '#type' => 'select',
            '#title' => $this->t('Follow reference'),
            '#description' => $this->t('Optionally follow an entity reference field and use the referenced entities instead of the entities the filter returned. The fields below will then belong to the referenced entity. Leave empty to use the filtered entities directly.'),
            '#options' => $options,
            '#default_value' => $entityKey,
            '#empty_option' => $this->t('- None -'),
            '#ajax' => [
              'callback' => [static::class, 'refreshAjax'],
              'wrapper' => $wrapperId,
            ],
          ];
          if ($entityKey) {
            $entity = $this->matcherReference->getReferenceEntityByEntityType($entityTypeId, $entityKey);
            $entityTypeId = $entity->getEntityTypeId();
            $bundle = $entity->bundle();
          }

          return new ChildrenMatchScope($entityTypeId, $bundle ?: NULL);
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
  public function provideDefaultValue(mixed $value): mixed {
    if (!$this->shape instanceof ComponentShapeChildrenMatchPluginInterface) {
      return $value;
    }
    return $this->childrenMatchMapper->getValues($this, $this->shape, $this->configuration, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function getChildrenMatchEntities(): ChildrenMatchResult {
    $filter = $this->shape->getComponent()->getFilter($this->configuration['filter']);
    if (!$filter) {
      return ChildrenMatchResult::unavailable();
    }

    $plugin = $filter->getPlugin();
    if (!$plugin instanceof ComponentFilterPluginEntityInterface) {
      return ChildrenMatchResult::unavailable();
    }

    $entities = $plugin->getEntities();

    // Support referenced entities.
    $entityKey = $this->configuration['entity'];
    if ($entityKey) {
      $referencedEntities = [];
      foreach ($entities as $entity) {
        $field = $this->matcherReference->getReferenceField($entity, $entityKey, $this->shape->getComponent()->getCacheableMetadata());
        foreach ($field as $item) {
          $referencedEntities[] = $item->entity;
        }
      }
      $entities = $referencedEntities;
    }

    return ChildrenMatchResult::of($entities);
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    // By default, plugins are available for all shapes.
    return !empty(array_filter($shape->getComponent()->getFilters(), fn($filter) => $filter->getPlugin() instanceof ComponentFilterPluginEntityInterface));
  }

}
