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
 * Provides the child terms of the current taxonomy term.
 *
 * A thin, source-less sibling of the Entity Reference / Entity Query providers:
 * it always reads the children of the term the component is attached to, so the
 * only configuration is the per-child field mapping. Restricted to components
 * attached to a taxonomy term via the attribute's entity_types.
 */
#[ComponentValue(
  id: 'taxonomy_children',
  label: new TranslatableMarkup('Child Terms'),
  description: new TranslatableMarkup("Use the current taxonomy term's child terms to provide values from their fields."),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::ARRAY,
  ],
  entity_types: ['taxonomy_term.*'],
  weight: 5,
)]
final class TaxonomyChildrenValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface {

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
      + $this->processingModeDefaultConfiguration();
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

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('parent', $term->id())
      ->sort('weight')
      ->sort('name');
    if (!empty($this->configuration['shape_published'])) {
      $query->condition('status', 1);
    }
    $ids = $query->execute();
    $children = $ids ? $storage->loadMultiple($ids) : [];

    // The list tag covers term add/edit/delete and re-parenting; the trait adds
    // each loaded child term as a cacheable dependency.
    $this->shape->getCacheableMetadata()->addCacheTags($storage->getEntityType()->getListCacheTags());

    $value = $this->getChildrenMatchValues($this->shape, $children, $this->configuration);
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return !empty($shape->getTargetEntityType());
  }

}
