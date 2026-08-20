<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentAccess;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentAccess;
use Drupal\neo_alchemist\ComponentAccessInterface;
use Drupal\neo_alchemist\ComponentAccessPluginBase;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Match\MatcherField;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_access.
 *
 * Hides the component on the frontend unless every selected field on the
 * target entity has a value — the access counterpart of the entity_has_value
 * ComponentValue provider: that one blanks a single boolean prop, this one
 * gates the whole component. Only offered on components registered against an
 * entity type (see isApplicable()).
 */
#[ComponentAccess(
  id: 'entity_field_value',
  label: new TranslatableMarkup('Entity field value'),
  description: new TranslatableMarkup('Only show the component when the selected field(s) on the target entity have a value.'),
)]
final class EntityFieldValueAccess extends ComponentAccessPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\Match\MatcherField
   */
  protected MatcherField $matcherField;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentAccessInterface $access,
    array $configuration,
    MatcherField $matcher_field,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $access, $configuration);
    $this->matcherField = $matcher_field;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['access'],
      $configuration['settings'],
      $container->get('neo_alchemist.matcher_field'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentInterface $component): bool {
    // A field can only be checked against a host entity, so the plugin is
    // meaningless on components that are not registered against an entity
    // type.
    return !empty($component->getTargetEntityTypeId());
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'fields' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();

    $fields = array_filter($this->configuration['fields'] ?? []);
    if ($fields) {
      $options = $this->getFieldOptions();
      $labels = array_map(
        fn ($field) => (string) ($options[$field] ?? $field),
        array_values($fields),
      );
      $summary[] = $this->t('Requires a value in: @fields', [
        '@fields' => implode(', ', $labels),
      ]);
    }

    return $summary;
  }

  /**
   * Configuration form for the access plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Required fields'),
      '#description' => $this->t('The component is shown on the frontend only if every selected field has a value on the target entity.'),
      '#options' => $this->getFieldOptions(),
      '#default_value' => $this->configuration['fields'] ?? [],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * Form validation for the access plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $fields = array_values(array_filter($form_state->getValue('fields', [])));
    $form_state->setValue('fields', $fields);
  }

  /**
   * Builds the field options for the component's target entity type.
   *
   * When the component targets a specific bundle only that bundle's fields
   * are offered; otherwise the union across all bundles (a field missing on
   * the rendered bundle simply has no value, which is the semantic anyway).
   *
   * @return array
   *   Field labels keyed by field name.
   */
  protected function getFieldOptions(): array {
    $component = $this->access->getComponent();
    $entityTypeId = $component->getTargetEntityTypeId();
    if (!$entityTypeId) {
      return [];
    }
    $bundle = $component->getTargetEntityBundle();
    $bundles = $bundle ? [$bundle] : array_keys($this->entityTypeBundleInfo->getBundleInfo($entityTypeId));
    $fields = [];
    foreach ($bundles as $bundleId) {
      $fields += $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundleId);
    }
    $options = [];
    foreach ($fields as $fieldName => $definition) {
      $options[$fieldName] = (string) $definition->getLabel();
    }
    asort($options);
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function bypassAdminAccess(string $op): bool {
    // Enforce the content-presence gate on the frontend even for
    // administrators, so a component whose fields are empty is hidden for
    // everyone. Backend management (update/create) still bypasses for
    // administrators.
    return $op !== 'view';
  }

  /**
   * {@inheritdoc}
   */
  public function access(string $op, AccountInterface $account): AccessResultInterface {
    // Only gate frontend visibility; never block backend management.
    if ($op !== 'view') {
      return AccessResult::neutral();
    }

    $fields = array_filter($this->configuration['fields'] ?? []);
    if (!$fields) {
      return AccessResult::neutral();
    }

    $component = $this->access->getComponent();
    if (!$component->getTargetEntityTypeId()) {
      return AccessResult::neutral();
    }
    $entity = $component->getTargetEntity();
    // A new entity is a preview placeholder (or an entity still being
    // created) — there is no saved content to judge, so stay neutral rather
    // than hiding the component in every builder preview. Mirrors
    // EntityHasValue::isAllowed().
    if ($entity->isNew()) {
      return AccessResult::neutral();
    }

    // The decision depends on the entity's field values, so the entity itself
    // must invalidate it; getEntityField() adds anything it traverses.
    // Emptiness is the field item list's own isEmpty() — the field type's
    // semantic notion — NOT truthiness of getValue(): an empty text-with-
    // format field still returns a phantom [{value: NULL, format: NULL}]
    // item, which would read as "has a value".
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($entity);
    $result = AccessResult::neutral();
    foreach ($fields as $fieldName) {
      $field = $this->matcherField->getEntityField($entity, $fieldName, TRUE, $cacheability);
      if (!$field || $field->isEmpty()) {
        $result = AccessResult::forbidden(sprintf('The "%s" field has no value.', $fieldName));
        break;
      }
    }

    $result->addCacheableDependency($cacheability);
    return $result;
  }

}
