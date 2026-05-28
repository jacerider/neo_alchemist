<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\Event\ComponentValueEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'event',
  label: new TranslatableMarkup('Event'),
  description: new TranslatableMarkup('Fires an event to get value.'),
  group: 'providers',
  weight: -5,
)]
final class EventValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EventDispatcherInterface $event_dispatcher,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->eventDispatcher = $event_dispatcher;
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
      $container->get('event_dispatcher')
    );
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $event = new ComponentValueEvent($this->shape, []);
    $form['info'] = [
      '#markup' => $this->t('This plugin fires an event (%name) via (%class) to get the value. Match on <code>$event-&gt;id()</code> equal to <code>@id</code> in your subscriber to target this prop.', [
        '%name' => ComponentValueEvent::EVENT_NAME,
        '%class' => ComponentValueEvent::class,
        '@id' => $event->id(),
      ]),
    ];

    $structure = $this->getExpectedStructure();
    if ($structure !== NULL) {
      $form['structure'] = [
        '#type' => 'details',
        '#title' => $this->t('Expected return value'),
        '#description' => $this->t('Subscribers must call <code>$event-&gt;setValue()</code> with data shaped like the example below.'),
        '#open' => TRUE,
        'value' => [
          '#type' => 'html_tag',
          '#tag' => 'pre',
          '#value' => is_scalar($structure) ? var_export($structure, TRUE) : Yaml::encode($structure),
        ],
      ];
    }

    return $form;
  }

  /**
   * Build a representative structure for the expected return value.
   *
   * Walks the JSON-Schema for the shape so every available property is
   * surfaced — even ones the author omitted from the schema's examples.
   * Example values are still used as concrete sample data when present.
   *
   * @return mixed
   *   The structure to display, or NULL if nothing useful can be produced.
   */
  protected function getExpectedStructure(): mixed {
    return $this->skeletonFromSchema($this->shape->getSchema());
  }

  /**
   * Synthesize a skeleton value from a JSON-Schema fragment.
   *
   * @param array $schema
   *   The schema fragment.
   *
   * @return mixed
   *   A placeholder value matching the schema's shape.
   */
  protected function skeletonFromSchema(array $schema): mixed {
    $type = $schema['type'] ?? NULL;
    if (is_array($type)) {
      $type = reset($type);
    }
    $example = $this->firstExample($schema);

    switch ($type) {
      case 'object':
        $exampleMap = is_array($example) ? $example : [];
        $properties = $schema['properties'] ?? [];
        // When the schema declares no properties, derive them from the
        // example so authors who only supply `examples:` still get useful
        // output.
        if (!$properties && $exampleMap) {
          return $this->skeletonFromExample($exampleMap);
        }
        $out = [];
        foreach ($properties as $name => $child) {
          if (!is_array($child)) {
            $out[$name] = NULL;
            continue;
          }
          // Promote the parent example's value for this property into the
          // child schema so scalar leaves can show concrete sample data.
          if (array_key_exists($name, $exampleMap) && empty($child['examples'])) {
            $child['examples'] = [$exampleMap[$name]];
          }
          $out[$name] = $this->skeletonFromSchema($child);
        }
        return $out;

      case 'array':
        $items = $schema['items'] ?? NULL;
        if (!is_array($items)) {
          // No items schema — infer from the first example entry.
          if ($example !== NULL) {
            return [$this->skeletonFromExample($example)];
          }
          return [];
        }
        if ($example !== NULL && empty($items['examples'])) {
          $items['examples'] = [$example];
        }
        return [$this->skeletonFromSchema($items)];

      case 'boolean':
        return is_bool($example) ? $example : FALSE;

      case 'integer':
      case 'number':
        return is_numeric($example) ? $example + 0 : 0;

      case 'string':
      default:
        if (is_scalar($example) && $example !== '') {
          return $example;
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
          return $schema['enum'][0];
        }
        $format = $schema['format'] ?? ($schema['ref'] ?? $type ?? 'value');
        return '<' . $format . '>';
    }
  }

  /**
   * Walk raw example data to produce a skeleton.
   *
   * Used when a schema fragment lacks a `properties` or `items` definition
   * but the author supplied example data that conveys the shape.
   *
   * @param mixed $example
   *   The example value.
   *
   * @return mixed
   *   The skeleton.
   */
  protected function skeletonFromExample(mixed $example): mixed {
    if (is_array($example)) {
      // Lists: render one representative item.
      if (array_is_list($example)) {
        if (!$example) {
          return [];
        }
        return [$this->skeletonFromExample($example[0])];
      }
      $out = [];
      foreach ($example as $key => $value) {
        $out[$key] = $this->skeletonFromExample($value);
      }
      return $out;
    }
    return $example;
  }

  /**
   * Return the first usable example value from a schema fragment.
   *
   * @param array $schema
   *   The schema fragment.
   *
   * @return mixed
   *   The first example value, or NULL if none.
   */
  protected function firstExample(array $schema): mixed {
    if (empty($schema['examples'])) {
      return NULL;
    }
    if (!is_array($schema['examples'])) {
      return $schema['examples'];
    }
    if (array_is_list($schema['examples'])) {
      return $schema['examples'][0] ?? NULL;
    }
    return $schema['examples'];
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    $event = new ComponentValueEvent($this->shape, $value);
    $this->eventDispatcher->dispatch($event, ComponentValueEvent::EVENT_NAME);
    $value = $event->getValue();
    $this->shape->addCacheableDependency($event);
    if (!$event->continueProcessing) {
      $this->stopFurtherProcessing();
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

}
