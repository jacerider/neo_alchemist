<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Match\MatcherField;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProducerInterface;
use Drupal\neo_alchemist\Value\ComponentValueProvision;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'entity_has_value',
  label: new TranslatableMarkup('Entity has Value'),
  description: new TranslatableMarkup('Check if an entity field has a value.'),
  group: 'providers',
  ref_types: [
    ComponentShapePluginInterface::BOOLEAN,
  ],
  entity_types: ['*'],
  weight: -5,
)]
final class EntityHasValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProducerInterface {

  use DependencySerializationTrait;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\Match\MatcherField
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
    MatcherField $matcher_field,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
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
      $container->get('neo_alchemist.matcher_field')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'field' => '',
    ];
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['field'] = [
      '#type' => 'neo_field_select',
      '#title' => $this->t('Field'),
      '#description' => $this->t('Select the field to use as the value.'),
      '#component' => $this->shape->getComponent()->id(),
      '#prop' => $this->shape->getRootShape()->getName(),
      '#shape' => $this->shape->id(),
      '#all' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $this->configuration['field'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Only allow processing if the entity is not new.
   */
  public function isAllowed(string $op): bool {
    return match($this->shape->getScope()) {
      'field' => match($op) {
        'default' => !$this->shape->getEntity()->isNew(),
        default => TRUE,
      },
      default => !$this->shape->getEntity()->isNew(),
    };
  }

  /**
   * {@inheritdoc}
   *
   * An empty field vetoes: claim FALSE so the search halts and no fallback can
   * put a truthy value back and reveal a component that has nothing to show. A
   * field with content offers the threaded value untouched.
   */
  public function provide(mixed $value): ComponentValueProvision {
    // Emptiness is the field item list's own isEmpty() — the field type's
    // semantic notion — NOT truthiness of getValue(): an empty text-with-
    // format field still returns a phantom [{value: NULL, format: NULL}]
    // item, which read as "has a value". Dynamic selections (_entity:/
    // _field: keys) resolve to no item list; those keep the value
    // truthiness check.
    $field = $this->matcherField->getEntityField(
      entity: $this->shape->getEntity(),
      key: $this->configuration['field'],
      published: TRUE,
      cacheableMetadata: $this->shape->getCacheableMetadata()
    );
    $hasValue = $field ? !$field->isEmpty() : !empty($this->matcherField->getEntityValue(
      entity: $this->shape->getEntity(),
      key: $this->configuration['field'],
      published: TRUE,
      cacheableMetadata: $this->shape->getCacheableMetadata()
    ));
    return $hasValue
      ? ComponentValueProvision::offer($value)
      : ComponentValueProvision::claim(FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    return $this->provide($value)->getValue();
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

}
