<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\FilterFormatInterface;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentPropRenderable;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'formatted_text',
  label: new TranslatableMarkup('Formatted Text'),
  description: new TranslatableMarkup('Provide formatted text support to markup props.'),
  // A modifier: it sources no value, it renders one that already exists
  // through a text format (alterValue() + modifyValue()).
  group: 'modifiers',
  ref_types: ['markup'],
  weight: 10,
)]
final class FormattedTextValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The available text formats.
   *
   * @var \Drupal\filter\FilterFormatInterface[]
   */
  protected array $formats;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'format' => 'neo_simple',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    parent::onShapeInit();
    $shape = $this->shape;
    $shape->setFieldType('text_long');
    $shape->setFieldInstanceSettings([
      'allowed_formats' => [$this->configuration['format']],
    ]);
    $shape->setWidget('text_textarea');
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $options = array_map(
      static fn (FilterFormatInterface $textFormat) => $textFormat->label(),
      $this->getTextFormats()
    );
    $form['format'] = [
      '#type' => 'select',
      '#title' => $this->t('Text format'),
      '#description' => $this->t('The text format to use for the formatted text.'),
      '#options' => $options,
      '#default_value' => $this->configuration['format'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$element, FormStateInterface $form_state) {
    $element['widget']['widget']['#after_build'][] = [
      static::class,
      'afterBuild',
    ];
  }

  /**
   * Form element #after_build callback.
   */
  public static function afterBuild(array $element, FormStateInterface $form_state): array {
    $element[0]['format']['#attributes']['class'][] = 'hidden';
    $element[0]['format']['guidelines']['#access'] = FALSE;
    $element[0]['format']['help']['#access'] = FALSE;
    $element[0]['format']['format']['#access'] = FALSE;
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function alterValue(mixed $value, string $type): mixed {
    if (is_array($value) && isset($value['format'])) {
      $value['format'] = $this->configuration['format'];
    }
    return $value;
  }

  /**
   * Retrieves the available text formats.
   *
   * This method loads the text formats from the 'filter_format' storage if they
   * are not already set. It uses the entity type manager to get the storage and
   * load multiple formats.
   *
   * @return \Drupal\filter\FilterFormatInterface[]
   *   An array of text formats.
   */
  protected function getTextFormats(): array {
    if (!isset($this->formats)) {
      $this->formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();
    }
    return $this->formats;
  }

  /**
   * {@inheritdoc}
   */
  public function modifyValue(mixed $value): mixed {
    if ($value instanceof ComponentPropRenderable) {
      return [$value->build()];
    }
    $value = [
      '#type' => 'processed_text',
      '#text' => $value,
      '#format' => $this->configuration['format'],
    ];
    // The #text property needs to be a string and not an array.
    if (isset($value['#text']) && is_array($value['#text'])) {
      $value['#text'] = $value['#text']['value'] ?? '';
    }
    return \Drupal::service('renderer')->render($value);
  }

}
