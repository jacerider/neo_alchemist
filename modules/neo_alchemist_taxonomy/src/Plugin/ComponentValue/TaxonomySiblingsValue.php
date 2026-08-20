<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_taxonomy\Plugin\ComponentValue;

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
use Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the sibling terms of the current taxonomy term.
 *
 * The mirror of TaxonomyChildrenValue, walking sideways instead of down: it
 * reads the terms sharing a parent with the term the component is attached to.
 * Beyond the per-child field mapping the only choice is whether the current
 * term is one of them — "Related services" wants it dropped, a section nav
 * wants it kept so the visitor can see where they are.
 */
#[ComponentValue(
  id: 'taxonomy_siblings',
  label: new TranslatableMarkup('Taxonomy Sibling Terms'),
  description: new TranslatableMarkup("Use the terms sharing a parent with the current taxonomy term to provide values from their fields."),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::ARRAY,
  ],
  entity_types: ['taxonomy_term.*'],
  weight: 5,
)]
final class TaxonomySiblingsValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ChildrenMatchSourceInterface {

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
    return ChildrenMatchMapper::defaultConfiguration()
      + ['exclude_self' => TRUE]
      + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * Matches its children-match siblings: a term with no peers fills no list,
   * and a list's examples are sample rows meant for the editor preview, never
   * for a visitor.
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_BLOCK;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    assert($this->shape instanceof ComponentShapeChildrenMatchPluginInterface);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    // The mapper reads $form['#id'] for its ajax wrapper.
    $form['#id'] = $wrapperId;
    $form = $this->childrenMatchMapper->buildConfigurationForm($this, $this->shape, $form, $form_state, $this->configuration);
    $form = $this->buildProcessingModeForm($form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildChildrenMatchSourceForm(array &$form, FormStateInterface $form_state): ?ChildrenMatchScope {
    $form['exclude_self'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exclude the current term'),
      '#description' => $this->t('If checked, the term being viewed is left out of its own list. Uncheck to include it — useful for a section navigation that highlights where the visitor is.'),
      '#default_value' => $this->configuration['exclude_self'] ?? TRUE,
    ];
    return new ChildrenMatchScope('taxonomy_term', $this->shape->getTargetEntityBundle());
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
    return $this->childrenMatchMapper->getValues($this, $this->shape, $this->configuration, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function getChildrenMatchEntities(): ChildrenMatchResult {
    $term = $this->shape->getEntity();
    // On a placeholder/new term (e.g. the Alchemist preview) fall through so
    // the Default provider's examples render; real term pages have a real term.
    if ($term->isNew() || $term->getEntityTypeId() !== 'taxonomy_term') {
      return ChildrenMatchResult::unavailable();
    }

    // A term may have several parents, so a sibling is anything sharing any one
    // of them. Root terms report parent 0 — which is why the vocabulary
    // condition below is not optional: without it a top-level term's "siblings"
    // would be the top level of every vocabulary on the site.
    $parentIds = [];
    foreach ($term->get('parent') as $item) {
      $parentIds[] = (int) $item->target_id;
    }
    $parentIds = array_values(array_unique($parentIds)) ?: [0];

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $entityType = $storage->getEntityType();
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition($entityType->getKey('bundle'), $term->bundle())
      ->condition('parent', $parentIds, 'IN')
      ->sort('weight')
      ->sort('name');
    if (!empty($this->configuration['exclude_self'])) {
      $query->condition($entityType->getKey('id'), $term->id(), '<>');
    }
    // No range or pager here, so there is no window for unpublished terms to
    // consume — the mapper's published filter is the whole policy.
    $ids = $query->execute();
    $siblings = $ids ? $storage->loadMultiple($ids) : [];

    // The list tag covers term add/edit/delete and re-parenting — including
    // re-parenting the current term, which changes the set without touching any
    // term in it; the mapper adds each loaded sibling as a dependency too.
    $this->shape->getCacheableMetadata()->addCacheTags($entityType->getListCacheTags());

    return ChildrenMatchResult::of($siblings);
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return !empty($shape->getTargetEntityType());
  }

}
