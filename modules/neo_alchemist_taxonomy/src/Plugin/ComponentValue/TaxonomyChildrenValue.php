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
use Drupal\neo_alchemist\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatchResult;
use Drupal\neo_alchemist\ChildrenMatchScope;
use Drupal\neo_alchemist\ChildrenMatchSourceInterface;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the child terms of the current taxonomy term.
 *
 * A thin, source-less sibling of the Entity Reference / Entity Query providers:
 * it always reads the children of the term the component is attached to, so the
 * only configuration is the per-child field mapping. Restricted to components
 * attached to a taxonomy term via the attribute's entity_types.
 */
#[ComponentValue(
  id: 'taxonomy_children',
  label: new TranslatableMarkup('Taxonomy Child Terms'),
  description: new TranslatableMarkup("Use the current taxonomy term's child terms to provide values from their fields."),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::ARRAY,
  ],
  entity_types: ['taxonomy_term.*'],
  weight: 5,
)]
final class TaxonomyChildrenValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ChildrenMatchSourceInterface {

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
    return ChildrenMatchMapper::defaultConfiguration()
      + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * Matches its children-match siblings: a term with no children fills no list,
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
   *
   * There is nothing to configure about the source itself: it always reads the
   * children of the term the component is attached to.
   */
  public function buildChildrenMatchSourceForm(array &$form, FormStateInterface $form_state): ?ChildrenMatchScope {
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

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    // No range or pager here, so there is no window for unpublished terms to
    // consume — the mapper's published filter is the whole policy.
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('parent', $term->id())
      ->sort('weight')
      ->sort('name')
      ->execute();
    $children = $ids ? $storage->loadMultiple($ids) : [];

    // The list tag covers term add/edit/delete and re-parenting; the mapper
    // adds each loaded child term as a cacheable dependency.
    $this->shape->getCacheableMetadata()->addCacheTags($storage->getEntityType()->getListCacheTags());

    return ChildrenMatchResult::of($children);
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return !empty($shape->getTargetEntityType());
  }

}
