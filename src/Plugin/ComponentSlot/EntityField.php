<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field\FieldLabelOptionsTrait;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentSlotPluginBase;
use Drupal\neo_alchemist\MatcherReference;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'entity_field',
  label: new TranslatableMarkup('Field Display'),
  description: new TranslatableMarkup('Render a entity field.'),
)]
final class EntityField extends ComponentSlotPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;
  use FieldLabelOptionsTrait;

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
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The formatter plugin manager.
   *
   * @var \Drupal\Core\Field\FormatterPluginManager
   */
  protected FormatterPluginManager $pluginManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The field matcher.
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
    ComponentInterface $component,
    string $uuid,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityFieldManagerInterface $entity_field_manager,
    FormatterPluginManager $formatter_manager,
    ModuleHandlerInterface $module_handler,
    MatcherReference $matcher_reference
  ) {
    parent::__construct($plugin_id, $plugin_definition, $component, $uuid, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityFieldManager = $entity_field_manager;
    $this->pluginManager = $formatter_manager;
    $this->moduleHandler = $module_handler;
    $this->matcherReference = $matcher_reference;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['component'],
      $configuration['uuid'],
      $configuration['settings'],
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.formatter'),
      $container->get('module_handler'),
      $container->get('neo_alchemist.matcher_reference')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'entity' => '',
      'field' => '',
      'field_label' => 'hidden',
      'field_settings' => [],
      'field_third_party_settings' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    if ($entityId = $this->configuration['entity']) {
      $references = $this->matcherReference->getReferences($this->component->getTargetEntityTypeId(), $this->component->getTargetEntityBundle());
      if (isset($references[$entityId])) {
        $summary[] = $this->t('Target Entity: @group → @title', ['@group' => $references[$entityId]['group'], '@title' => $references[$entityId]['title']]);
      }
    }
    if ($fieldName = $this->configuration['field']) {
      $field = $this->getField($fieldName);
      if ($field) {
        $summary[] = $this->t('Field: @label (@name)', ['@label' => $field->getLabel(), '@name' => $fieldName]);
      }
    }
    return $summary;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;
    $fields = $this->getFields();

    $options = $this->matcherReference->getReferencesAsOptions($this->component->getTargetEntityTypeId(), $this->component->getTargetEntityBundle());
    $form['entity'] = [
      '#type' => 'select',
      '#title' => $this->t('Target entity type'),
      '#options' => $options,
      '#default_value' => $this->configuration['entity'],
      '#empty_option' => $this->component->getTargetEntity()->getEntityType()->getLabel(),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    $options = array_map(fn ($field) => $field->getLabel(), $fields);

    $fieldName = $this->configuration['field'];
    $form['field'] = [
      '#type' => 'select',
      '#title' => $this->t('Field'),
      '#options' => $options,
      '#default_value' => $fieldName,
      '#empty_option' => $this->t('- None -'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($fieldName && isset($fields[$fieldName])) {
      $field = $fields[$fieldName];
      $form['field_label'] = [
        '#type' => 'select',
        '#title' => $this->t('Label display'),
        '#options' => $this->getFieldLabelOptions(),
        '#default_value' => $this->configuration['field_label'],
        '#required' => TRUE,
      ];
      if ($pluginOptions = $this->getApplicablePluginOptions($field)) {
        $pluginId = $this->configuration['field_plugin'];
        $form['field_plugin'] = [
          '#type' => 'select',
          '#title' => $this->t('Display format'),
          '#options' => $pluginOptions,
          '#default_value' => $pluginId,
          '#required' => TRUE,
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
        if ($pluginId && $this->pluginManager->hasDefinition($pluginId)) {
          $plugin = $this->pluginManager->getInstance([
            'field_definition' => $field,
            'view_mode' => 'full',
            'prepare' => FALSE,
            'configuration' => [
              'type' => $pluginId,
              'label' => $this->configuration['field_label'],
              'settings' => $this->configuration['field_settings'],
              'third_party_settings' => $this->configuration['field_third_party_settings'],
              'region' => 'content',
            ],
          ]);
          $form['field_settings'] = $plugin->settingsForm($form, $form_state);

          $settings_form = [];
          // Invoke hook_field_widget_third_party_settings_form(), keying resulting
          // subforms by module name.
          $this->moduleHandler->invokeAllWith(
            'field_formatter_third_party_settings_form',
            function (callable $hook, string $module) use (&$settings_form, $plugin, $field, &$form, $form_state) {
              $settings_form[$module] = $hook(
                $plugin,
                $field,
                'full',
                $form,
                $form_state
              );
            }
          );

          $form['field_third_party_settings'] = $settings_form;
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
  public function toRenderable() {
    if ($fieldName = $this->configuration['field']) {
      $entity = $this->component->getTargetEntity();
      if ($entityKey = $this->configuration['entity']) {
        $entity = $this->matcherReference->getReferenceEntity($this->component->getTargetEntity(), $entityKey) ?? $entity;
      }
      if ($entity instanceof ContentEntityInterface && $entity->hasField($fieldName) && !$entity->get($fieldName)->isEmpty()) {
        return $entity->get($fieldName)->view([
          'label' => 'hidden',
          'type' => $this->configuration['field_plugin'],
          'settings' => $this->configuration['field_settings'],
          'third_party_settings' => $this->configuration['field_third_party_settings'],
        ]);
      }
    }
    return [];
  }

  /**
   * Get all fields for the current entity type.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   The field definitions.
   */
  private function getFields(): array {
    $fields = [];
    $entityTypeId = $this->component->getTargetEntityTypeId();
    if ($entityKey = $this->configuration['entity']) {
      $entity = $this->matcherReference->getReferenceEntity($this->component->getTargetEntity(), $entityKey, TRUE);
      $entityTypeId = $entity->getEntityTypeId();
    }
    $bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
    foreach ($bundles as $bundle => $data) {
      $fields += $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    }
    return $fields;
  }

  /**
   * Get field definition.
   *
   * @param string $fieldName
   *   The field name.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|null
   *   The field definition.
   */
  private function getField(string $fieldName): ?FieldDefinitionInterface {
    return $this->getFields()[$fieldName] ?? NULL;
  }

  /**
   * Returns an array of applicable widget or formatter options for a field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return array
   *   An array of applicable widget or formatter options.
   */
  protected function getApplicablePluginOptions(FieldDefinitionInterface $field_definition) {
    $options = $this->pluginManager->getOptions($field_definition->getType());
    $applicable_options = [];
    foreach ($options as $option => $label) {
      $plugin_class = DefaultFactory::getPluginClass($option, $this->pluginManager->getDefinition($option));
      if ($plugin_class::isApplicable($field_definition)) {
        $applicable_options[$option] = $label;
      }
    }
    return $applicable_options;
  }

}
