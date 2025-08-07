<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentFilterPluginEntityInterface;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\MatcherField;
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
final class EntityFilterValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;
  use ComponentValueChildrenMatchTrait;

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
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    MatcherField $matcher_field,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->matcherField = $matcher_field;
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
      $container->get('entity_type.bundle.info'),
      $container->get('neo_alchemist.matcher_field')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'filter' => '',
    ] + $this->childrenMatchDefaultConfiguration();
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
    assert($this->shape instanceof ComponentShapeChildrenPluginInterface);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;
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
          $form += $this->buildChildrenMatchConfigurationForm($this->shape, $form, $form_state, $entityTypeId, $bundle, $this->configuration);
        }
      }
    }
    return $form;
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
  public function provideOverrideValue(mixed $value, mixed $defaultValue): mixed {
    if (!$this->shape instanceof ComponentShapeChildrenPluginInterface) {
      return $value;
    }
    $filter = $this->shape->getComponent()->getFilter($this->configuration['filter']);
    if (!$filter) {
      return $value;
    }

    $plugin = $filter->getPlugin();
    if (!$plugin instanceof ComponentFilterPluginEntityInterface) {
      return $value;
    }

    $entities = $plugin->getEntities();
    $results = $this->getChildrenMatchValues($this->shape, $entities, $this->configuration);
    if (!empty($results) || empty($this->configuration['continue'])) {
      $value = $results;
      $this->stopFurtherProcessing();
    }

    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    // By default, plugins are available for all shapes.
    return !empty(array_filter($shape->getComponent()->getFilters(), fn($filter) => $filter->getPlugin() instanceof ComponentFilterPluginEntityInterface));
  }

}
