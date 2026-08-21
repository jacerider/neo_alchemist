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
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Match\MatcherReference;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProducerInterface;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'entity_reference',
  label: new TranslatableMarkup('Entity Reference'),
  description: new TranslatableMarkup('Use an entity reference field to provide values from the referenced entity fields.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::ARRAY,
    ComponentShapePluginInterface::OBJECT,
  ],
  weight: 4,
)]
final class EntityReferenceValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ChildrenMatchSourceInterface, ComponentValueProducerInterface {

  use DependencySerializationTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The reference matcher.
   *
   * @var \Drupal\neo_alchemist\Match\MatcherReference
   */
  protected MatcherReference $matcherReference;

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
      'entity' => '',
    ] + ChildrenMatchMapper::defaultConfiguration()
      + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * Blocks on empty like its children-match siblings entity_query,
   * entity_filter and views. The prop this fills — a list's rows, or an
   * object's children — carries schema examples that are editor scaffolding:
   * placeholder cards and placehold.co images that make the preview legible.
   * An unpopulated reference field means there is no content, so it must
   * render as nothing; getDefaultValue() keeps the seeded example past a
   * non-claiming producer, and only a claim says "empty is the answer".
   *
   * The exception is deliberate and per-instance: when this plugin is the
   * PRIMARY source above a fallback provider (typically entity_query), the
   * site builder overrides this instance to stop_when_found so an empty
   * reference falls through to the fallback instead of claiming emptiness.
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_BLOCK;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = [];
    if ($entityKey = $this->configuration['entity']) {
      $summary[] = $this->t('From %field', [
        '%field' => explode(':', $entityKey)[0],
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
    $component = $this->shape->getComponent();
    $options = $this->matcherReference->getReferencesAsOptions($component->getTargetEntityTypeId(), $component->getTargetEntityBundle());
    $entityKey = $this->configuration['entity'];
    $form['entity'] = [
      '#type' => 'select',
      '#title' => $this->t('Target entity reference field'),
      '#options' => $options,
      '#default_value' => $entityKey,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($entityKey) {
      $entity = $this->matcherReference->getReferenceEntity($component->getTargetEntity(), $entityKey, TRUE);
      if ($entity) {
        return new ChildrenMatchScope($entity->getEntityTypeId(), $entity->bundle());
      }
      $form['message'] = [
        '#markup' => $this->t('The selected entity could not be loaded.'),
        '#prefix' => '<div class="messages messages--warning">',
        '#suffix' => '</div>',
      ];
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
    // Produce the value; the configurable processing mode (applied by the
    // pipeline) decides whether to claim it or fall through when empty.
    return $this->childrenMatchMapper->getValues($this, $this->shape, $this->configuration, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function getChildrenMatchEntities(): ChildrenMatchResult {
    $entityKey = $this->configuration['entity'];
    if (!$entityKey) {
      return ChildrenMatchResult::unavailable();
    }
    $component = $this->shape->getComponent();
    $field = $this->matcherReference->getReferenceField($component->getTargetEntity(), $entityKey, $component->getCacheableMetadata());
    // Only map when the reference actually resolves to entities, which is why
    // this returns emptyValue() rather than of([]). With zero entities the
    // mapper's non-iterable branch returns a map of per-child empties that
    // isProvidedValueEmpty() counts as NON-empty — which under stop_when_found
    // would claim, starving a fallback provider and force-hiding every child.
    // Today that cannot be reached: MatcherReference::getReferenceField()
    // returns NULL whenever the first target fails to load, so a dangling
    // reference never gets this far (pinned by
    // EntityReferenceAggregateFallbackTest::testDanglingReferenceFallsBack()).
    // The guard is defense-in-depth so a matcher change cannot silently
    // resurrect the trap. An empty value falls through, which is also what an
    // empty field does.
    $entities = $field ? $field->referencedEntities() : [];
    return $entities ? ChildrenMatchResult::of($entities) : ChildrenMatchResult::emptyValue();
  }

  /**
   * {@inheritdoc}
   *
   * Entity-scoped, and — like entity_query — only shapes whose children can
   * receive a distribution: iterable lists and expandable objects (the
   * `_aggregate` shape of an aggregated component being the important one).
   * Non-expandable object shapes such as link and heading stay excluded.
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    if (empty($shape->getTargetEntityType())) {
      return FALSE;
    }
    return $shape->isIterable() || $shape->isExpandable();
  }

}
