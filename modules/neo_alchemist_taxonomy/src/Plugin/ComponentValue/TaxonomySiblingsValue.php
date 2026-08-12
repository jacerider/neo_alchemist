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
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\MatcherField;
use Drupal\neo_alchemist\MatcherReference;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueChildrenMatchTrait;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait;
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
final class TaxonomySiblingsValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface {

  use DependencySerializationTrait;
  use ComponentValueChildrenMatchTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

  /**
   * The reference matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherReference
   */
  protected MatcherReference $matcherReference;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    MatcherField $matcher_field,
    MatcherReference $matcher_reference,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->matcherField = $matcher_field;
    $this->matcherReference = $matcher_reference;
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
      $container->get('neo_alchemist.matcher_field'),
      $container->get('neo_alchemist.matcher_reference')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return $this->childrenMatchDefaultConfiguration()
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
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    // buildChildrenMatchConfigurationForm() reads $form['#id'] for its wrapper.
    $form['#id'] = $wrapperId;
    $bundle = $this->shape->getTargetEntityBundle();
    $form = $this->buildChildrenMatchConfigurationForm($this->shape, $form, $form_state, 'taxonomy_term', $bundle, $this->configuration);
    $form['exclude_self'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exclude the current term'),
      '#description' => $this->t('If checked, the term being viewed is left out of its own list. Uncheck to include it — useful for a section navigation that highlights where the visitor is.'),
      '#default_value' => $this->configuration['exclude_self'] ?? TRUE,
    ];
    $form = $this->buildProcessingModeForm($form, $form_state);
    return $form;
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
    $term = $this->shape->getEntity();
    // On a placeholder/new term (e.g. the Alchemist preview) fall through so
    // the Default provider's examples render; real term pages have a real term.
    if ($term->isNew() || $term->getEntityTypeId() !== 'taxonomy_term') {
      return $value;
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
    if (!empty($this->configuration['shape_published'])) {
      $query->condition('status', 1);
    }
    $ids = $query->execute();
    $siblings = $ids ? $storage->loadMultiple($ids) : [];

    // The list tag covers term add/edit/delete and re-parenting — including
    // re-parenting the current term, which changes the set without touching any
    // term in it; the trait adds each loaded sibling as a cacheable dependency.
    $this->shape->getCacheableMetadata()->addCacheTags($entityType->getListCacheTags());

    $value = $this->getChildrenMatchValues($this->shape, $siblings, $this->configuration);
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return !empty($shape->getTargetEntityType());
  }

}
