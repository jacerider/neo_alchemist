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
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\Event\ComponentValueEntityQueryEvent;
use Drupal\neo_alchemist\MatcherField;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'entity_query',
  label: new TranslatableMarkup('Entity Query'),
  description: new TranslatableMarkup('Use the results of an entity query to provide values from the queried entity fields.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::ARRAY,
    ComponentShapePluginInterface::OBJECT,
  ],
  weight: 5,
)]
final class EntityQueryValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

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
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

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
    EventDispatcherInterface $event_dispatcher,
    MatcherField $matcher_field,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->eventDispatcher = $event_dispatcher;
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
      $container->get('event_dispatcher'),
      $container->get('neo_alchemist.matcher_field')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'entity_type' => '',
      'bundle' => '',
      'start' => 0,
      'length' => 1,
      'continue' => FALSE,
    ] + $this->childrenMatchDefaultConfiguration();
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    assert($this->shape instanceof ComponentShapeChildrenPluginInterface);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    $entityTypeId = $this->configuration['entity_type'];
    $bundle = $this->configuration['bundle'];

    $entityTypes = $this->entityTypeManager->getDefinitions();
    $options = [];
    foreach ($entityTypes as $type) {
      $options[$type->id()] = $type->getLabel();
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
      $entityType = $entityTypes[$entityTypeId];
      if ($entityType->hasKey('bundle')) {
        if ($bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId)) {
          $options = array_map(
            fn ($bundle) => $bundle['label'],
            $bundles
          );
          asort($options);
          $form['bundle'] = [
            '#type' => 'select',
            '#title' => $this->t('Entity Bundle'),
            '#default_value' => $bundle,
            '#options' => $options,
            '#empty_option' => $this->t('- All -'),
            '#ajax' => [
              'callback' => [static::class, 'refreshAjax'],
              'wrapper' => $wrapperId,
            ],
          ];
        }
      }
      else {
        // $bundle = $entityTypeId;
      }

      // Add shape fields.
      $form += $this->buildChildrenMatchConfigurationForm($this->shape, $form, $form_state, $entityTypeId, $bundle, $this->configuration);

      $form['start'] = [
        '#type' => 'number',
        '#title' => $this->t('Start'),
        '#description' => $this->t('The starting index of the results to return.'),
        '#default_value' => $this->configuration['start'],
        '#min' => 0,
        '#step' => 1,
      ];

      $form['length'] = [
        '#type' => 'number',
        '#title' => $this->t('Length'),
        '#description' => $this->t('The number of results to return.'),
        '#default_value' => $this->configuration['length'],
        '#min' => 1,
        '#step' => 1,
      ];

      $form['continue'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Continue when no results'),
        '#description' => $this->t('This will allow any following value providers to be processed if the view returns no results.'),
        '#default_value' => $this->configuration['continue'],
      ];
    }

    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $form_state->setValue('continue', (bool) $form_state->getValue('continue'));
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
  public function provideOverrideValue(mixed $value, mixed $defaultValue): mixed {
    if (!$this->shape instanceof ComponentShapeChildrenPluginInterface) {
      return $value;
    }

    $entityTypeId = $this->configuration['entity_type'];
    if ($entityTypeId) {
      $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      $query = $storage->getQuery();
      $query->accessCheck(TRUE);
      $query->range($this->configuration['start'], $this->configuration['length']);
      $bundle = $this->configuration['bundle'];
      if ($bundle) {
        if ($entityType->hasKey('bundle')) {
          $query->condition($entityType->getKey('bundle'), $bundle);
        }
      }
      if ($entityType->hasKey('status')) {
        $query->condition($entityType->getKey('status'), 1);
      }
      $event = new ComponentValueEntityQueryEvent($this->shape, $query);
      $this->eventDispatcher->dispatch($event, ComponentValueEntityQueryEvent::EVENT_NAME);
      $ids = $query->execute();
      $entities = $ids ? $storage->loadMultiple($ids) : [];
      $results = $this->getChildrenMatchValues($this->shape, $entities, $this->configuration);
      if (!empty($results) || empty($this->configuration['continue'])) {
        $value = $results;
        $this->stopFurtherProcessing();
      }
    }
    return $value;
  }

}
