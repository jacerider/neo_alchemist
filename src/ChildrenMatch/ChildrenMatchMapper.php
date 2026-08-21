<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ChildrenMatch;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\Shape\ChildShapeState;
use Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Match\MatcherField;
use Drupal\neo_alchemist\Match\MatcherReference;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValuePluginTrait;
use Drupal\neo_alchemist\Value\ComponentValuePluginManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Maps entities onto the child shapes of a children-match shape.
 *
 * This is the whole of what the seven producers used to share by mixing in a
 * trait: the Property/Source mapping table, the pseudo fields behind it, the
 * published-entity policy, and the decision between a delta-keyed list and a
 * flat property map. Producers differ only in which entities they resolve and
 * how that is configured, which is
 * \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface.
 *
 * It is a service, so its collaborators are constructor arguments. As a trait
 * they were three undeclared properties every consumer had to remember to
 * assign in a hand-written constructor, and forgetting one produced no error
 * until a particular mapping path ran — which is exactly how the views
 * reference-mapping fatal reached production.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult
 */
class ChildrenMatchMapper {

  use ComponentValuePluginTrait;
  use StringTranslationTrait;

  /**
   * The mapper's own pseudo-field handlers, keyed and ordered by name.
   *
   * A stored `_<name>` child key resolves to the handler named `<name>`. A
   * source may register more through ChildrenMatchFieldSourceInterface, but not
   * shadow these — these take precedence — and anything neither these nor a
   * source claims is a field-matcher key. The order is the order options are
   * offered in.
   *
   * @var \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchHandlerInterface[]
   *
   * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchFieldSourceInterface
   */
  protected array $handlers;

  /**
   * Constructs a ChildrenMatchMapper.
   *
   * @param \Drupal\neo_alchemist\Match\MatcherField $matcherField
   *   The field matcher: reads a value off an entity for a stored field key.
   * @param \Drupal\neo_alchemist\Match\MatcherReference $matcherReference
   *   The reference matcher, handed to the `_reference` handler.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher, handed to the `_event` handler.
   * @param \Drupal\neo_alchemist\Value\ComponentValuePluginManagerInterface $valuePluginManager
   *   The value plugin manager, for labelling copy-mapping sources.
   * @param \Drupal\Core\Field\FormatterPluginManager $fieldFormatterManager
   *   The formatter manager, handed to the `_render` handler.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, handed to the `_render` handler.
   */
  public function __construct(
    protected MatcherField $matcherField,
    MatcherReference $matcherReference,
    EventDispatcherInterface $eventDispatcher,
    protected ComponentValuePluginManagerInterface $valuePluginManager,
    FormatterPluginManager $fieldFormatterManager,
    ModuleHandlerInterface $moduleHandler,
  ) {
    // One handler per pseudo field, in the order their options are offered. The
    // map is the whole extension protocol: the option, the form branch and the
    // fetch are one object each, and a name that is not a key here is not a
    // pseudo field. The collaborators a handler needs are handed to it here,
    // rather than held by the mapper, so the mapper keeps only what it uses
    // itself (the field matcher, for a plain field match).
    $this->handlers = [
      'default' => new ChildrenMatchDefaultHandler(),
      'event' => new ChildrenMatchEventHandler($eventDispatcher),
      'reference' => new ChildrenMatchReferenceHandler($matcherReference),
      'render' => new ChildrenMatchRenderHandler($this->matcherField, $fieldFormatterManager, $moduleHandler),
      'self' => new ChildrenMatchSelfHandler(),
      'raw' => new ChildrenMatchRawHandler(),
      'expand' => new ChildrenMatchExpandHandler(),
    ];
  }

  /**
   * The settings a children-match producer stores on top of its own.
   *
   * Static because ComponentValuePluginBase::__construct() reaches
   * defaultConfiguration() through setConfiguration(), which runs before a
   * producer's own constructor body has assigned anything. An instance method
   * here would make every producer's default configuration depend on the order
   * of two lines in its constructor — the same undeclared-requirement trap the
   * mapper exists to remove.
   */
  public static function defaultConfiguration(): array {
    return [
      'shape_fields' => [],
      'shape_published' => TRUE,
    ];
  }

  /**
   * A settings-summary line for the mapping.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The shape the producer is bound to.
   * @param array $configuration
   *   The producer's stored settings.
   *
   * @return array
   *   Zero or one translated lines ("6 of 9 fields mapped").
   */
  public function summary(ComponentShapePluginInterface $shape, array $configuration): array {
    if (!$shape instanceof ComponentShapeChildrenMatchPluginInterface) {
      return [];
    }
    $total = count($shape->getChildShapeNames());
    if (!$total) {
      return [];
    }
    $mapped = count(array_filter(
      $configuration['shape_fields'] ?? [],
      static fn ($settings) => !empty($settings['field'])
    ));
    return [
      $this->t('@mapped of @total fields mapped', [
        '@mapped' => $mapped,
        '@total' => $total,
      ]),
    ];
  }

  /**
   * Builds a producer's whole configuration form.
   *
   * The source's own controls go in first and keep their position, then the
   * mapping table and the Advanced group are appended.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface $source
   *   The producer.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface $shape
   *   The shape being configured.
   * @param array $form
   *   The provider subform. Its '#id' must be set: it is the ajax wrapper.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $configuration
   *   The producer's stored settings.
   *
   * @return array
   *   The form.
   */
  public function buildConfigurationForm(ChildrenMatchSourceInterface $source, ComponentShapeChildrenMatchPluginInterface $shape, array $form, FormStateInterface $form_state, array $configuration): array {
    // By reference: the source adds its own controls to $form, and the mapping
    // table is appended to whatever it left, so the source's elements keep
    // their position, their #parents and their ajax wrappers.
    $scope = $source->buildChildrenMatchSourceForm($form, $form_state);
    if (!$scope) {
      return $form;
    }
    return $this->buildMappingForm($source, $shape, $form, $form_state, $scope, $configuration);
  }

  /**
   * Resolves a producer's value.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface $source
   *   The producer.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface $shape
   *   The shape being filled.
   * @param array $configuration
   *   The producer's stored settings.
   * @param mixed $passthrough
   *   The value threaded through the pipeline, returned untouched when the
   *   source cannot run.
   *
   * @return mixed
   *   The mapped value.
   */
  public function getValues(ChildrenMatchSourceInterface $source, ComponentShapeChildrenMatchPluginInterface $shape, array $configuration, mixed $passthrough): mixed {
    $result = $source->getChildrenMatchEntities();
    if ($result->entities === NULL) {
      return $passthrough;
    }
    if (!$result->entities && !$result->mapsWhenEmpty) {
      return [];
    }
    // The published-entity decision is resolved once, here, from the settings
    // stored at the provider root — the one place `shape_published` is ever
    // written — and threaded down as an explicit argument. No mapping level
    // re-reads it from a settings array, because every level below the first is
    // handed a CHILD settings array that cannot carry it.
    $published = !empty($configuration['shape_published']);
    return $this->fetchValues($source, $shape->getChildShapeNames(), $shape, $result->entities, $published, $configuration);
  }

  /**
   * The mapping table, plus the Advanced group behind it.
   */
  public function buildMappingForm(ChildrenMatchSourceInterface $source, ComponentShapeChildrenMatchPluginInterface $shape, array $form, FormStateInterface $form_state, ChildrenMatchScope $scope, array $configuration): array {
    $wrapperId = $form['#id'];
    $childShapes = $shape->getChildShapes();
    if (!$childShapes) {
      $form['message'] = [
        '#type' => 'item',
        '#title' => $this->t('Shapes'),
        '#markup' => $this->t('No child shapes available.'),
      ];
      return $form;
    }

    // A mapping table: one row per child shape, its title on the left and
    // the source control (plus any branch extras and inline value plugins)
    // on the right — in place of a fieldset per child.
    $form['shape_fields'] = [
      '#type' => 'table',
      '#header' => [
        'label' => $this->t('Property'),
        'content' => $this->t('Source'),
      ],
      '#element_validate' => [[self::class, 'validateMappingForm']],
    ];
    foreach ($childShapes as $shapeName => $childShape) {
      $mapped = !empty($configuration['shape_fields'][$shapeName]['field']);
      $form['shape_fields'][$shapeName]['label'] = [
        '#type' => 'inline_template',
        '#template' => '<strong>{{ title }}</strong><br /><small class="description">{{ type }}</small>{% if not mapped %}<br /><small class="description">{{ "Not mapped"|t }}</small>{% endif %}',
        '#context' => [
          'title' => $childShape->getTitle(),
          'type' => $childShape->getType(),
          'mapped' => $mapped,
        ],
      ];
      $form['shape_fields'][$shapeName]['content'] = [
        '#type' => 'container',
        '#compact' => TRUE,
        '#id' => $wrapperId . '-' . $shapeName,
        '#attributes' => [
          'id' => $wrapperId . '-' . $shapeName,
        ],
        // Explicit parents keep the stored value tree exactly as before the
        // table layout — the row and cell levels never reach config.
        '#parents' => array_merge($form['#parents'], [
          'shape_fields',
          $shapeName,
        ]),
      ];
      $form['shape_fields'][$shapeName]['content'] = $this->buildChildForm($source, $childShape, $form['shape_fields'][$shapeName]['content'], $form_state, $scope, $configuration['shape_fields'][$shapeName] ?? []);
    }

    // The rarely-touched controls live behind Advanced. Their #parents stay
    // where they always were (shape_published at the provider root, _copy
    // under shape_fields), so stored config and the copy-mapping submit
    // paths are untouched by the visual move.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
      '#neo_size' => 'xs',
    ];
    // Chained providers on one shape (a primary entity_reference above an
    // entity_query fallback) almost always want the same mapping, and until
    // now each had to be filled in by hand — every chained pair in this
    // site's config carries the same shape_fields twice, verbatim. Offer a
    // one-click copy from any sibling provider that has a mapping. Form
    // convenience only: it prefills this form's input and rebuilds; nothing
    // is stored until the whole form is saved.
    if ($copySources = $this->copyMappingSources($source, $shape)) {
      $copyParents = array_merge($form['#parents'], ['shape_fields', '_copy']);
      $form['advanced']['_copy'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['form--inline', 'form--inline-min', 'items-end'],
        ],
      ];
      $form['advanced']['_copy']['source'] = [
        '#type' => 'select',
        '#title' => $this->t('Copy field mapping from'),
        '#options' => array_map(static fn (array $item) => $item['label'], $copySources),
        '#empty_option' => $this->t('- Select provider -'),
        '#parents' => array_merge($copyParents, ['source']),
        '#neo_size' => 'xs',
      ];
      $form['advanced']['_copy']['apply'] = [
        '#type' => 'submit',
        '#value' => $this->t('Copy mapping'),
        // Buttons are told apart by name; there can be one of these per
        // provider on the page.
        '#name' => $wrapperId . '-copy-mapping',
        '#parents' => array_merge($copyParents, ['apply']),
        '#copy_map' => array_map(static fn (array $item) => $item['shape_fields'], $copySources),
        '#limit_validation_errors' => [],
        '#submit' => [[self::class, 'copyMappingSubmit']],
        '#ajax' => [
          'callback' => [self::class, 'copyMappingAjax'],
          'wrapper' => $wrapperId,
        ],
        '#neo_size' => 'xs',
      ];
    }
    $form['advanced']['shape_published'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Only use published entities'),
      '#description' => $this->t('If checked, only published entities will be used. This is only applicable for entities that have a "status" entity key.'),
      '#default_value' => $configuration['shape_published'] ?? TRUE,
      '#parents' => array_merge($form['#parents'], ['shape_published']),
    ];

    return $form;
  }

  /**
   * Validates the mapping table.
   */
  public static function validateMappingForm(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    // A limited-validation submission (Cancel and friends) may carry no value
    // at all for this subtree.
    $values = $form_state->getValue($element['#parents']) ?? [];
    // The copy-mapping control lives inside this fieldset for layout but is
    // pure form chrome — it must never reach the plugin's stored settings.
    unset($values['_copy']);
    $values = array_filter($values);
    $form_state->setValue($element['#parents'], $values);
  }

  /**
   * Sibling providers on the same shape whose mapping can be copied.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface $source
   *   The producer asking, excluded from its own list.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface $shape
   *   The shape whose stored plugins to scan.
   *
   * @return array
   *   Keyed by plugin id: ['label' => …, 'shape_fields' => …].
   */
  protected function copyMappingSources(ChildrenMatchSourceInterface $source, ComponentShapeChildrenMatchPluginInterface $shape): array {
    $sources = [];
    $stored = $shape->getPlugins()[$shape->id()] ?? [];
    foreach ($stored as $pluginId => $instance) {
      if ($pluginId === $source->getPluginId() || empty($instance['settings']['shape_fields'])) {
        continue;
      }
      $definition = $this->valuePluginManager->getDefinition($pluginId, FALSE);
      $sources[$pluginId] = [
        'label' => (string) ($definition['label'] ?? $pluginId),
        'shape_fields' => $instance['settings']['shape_fields'],
      ];
    }
    return $sources;
  }

  /**
   * Submit handler: prefill this provider's mapping from a sibling's.
   *
   * Writes the chosen source's shape_fields into the raw user input under
   * this provider's own shape_fields parents and rebuilds, so every child
   * element re-renders with the copied selection. The values only persist
   * when the form is saved normally.
   */
  public static function copyMappingSubmit(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    // The apply button sits at [..., 'shape_fields', '_copy', 'apply'].
    $copyParents = array_slice($trigger['#parents'], 0, -1);
    $targetParents = array_slice($trigger['#parents'], 0, -2);
    $input = $form_state->getUserInput();
    $sourceId = NestedArray::getValue($input, array_merge($copyParents, ['source']));
    $mapping = $trigger['#copy_map'][$sourceId] ?? NULL;
    if ($mapping !== NULL) {
      // Keep the select's own input (so the user sees what was copied) while
      // replacing every child mapping wholesale.
      $copyInput = NestedArray::getValue($input, $copyParents);
      NestedArray::setValue($input, $targetParents, self::mappingToInput($mapping) + ['_copy' => $copyInput]);
      $form_state->setUserInput($input);
    }
    $form_state->setRebuild();
  }

  /**
   * Converts a stored shape_fields subtree to raw form-input format.
   *
   * Stored leaves are scalars and booleans; input wants strings, and an
   * unchecked checkbox is the absence of a key rather than FALSE.
   *
   * @param array $mapping
   *   The stored shape_fields subtree.
   *
   * @return array
   *   The same subtree as raw input.
   */
  protected static function mappingToInput(array $mapping): array {
    $input = [];
    foreach ($mapping as $key => $value) {
      if (is_array($value)) {
        $input[$key] = self::mappingToInput($value);
      }
      elseif (is_bool($value)) {
        if ($value) {
          $input[$key] = '1';
        }
      }
      else {
        $input[$key] = (string) $value;
      }
    }
    return $input;
  }

  /**
   * Ajax callback for the copy-mapping button: the whole provider subform.
   */
  public static function copyMappingAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    // From apply → _copy → shape_fields up to the provider subform, whose
    // #id is the ajax wrapper.
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -3));
  }

  /**
   * Ajax callback: the child row that changed.
   */
  public static function refreshChildAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
  }

  /**
   * Builds one child shape's row in the mapping table.
   */
  public function buildChildForm(ChildrenMatchSourceInterface $source, ComponentShapePluginInterface $shape, array $form, FormStateInterface $form_state, ChildrenMatchScope $scope, array $configuration): array {
    $wrapperId = $form['#id'];
    $form += [
      '#type' => 'fieldset',
      '#neo_size' => 'sm',
      '#title' => $shape->getTitle(),
      '#neo_region' => [
        'legend_end' => [
          '#markup' => '<div class="text-xs text-base-400">' . $this->t('Type: %type', [
            '%type' => $shape->getType(),
          ]) . '</div>',
        ],
      ],
      '#description_display' => 'before',
    ];
    $form['#element_validate'][] = [self::class, 'validateChildForm'];
    // An array child shape is filled by *iterating* a reference field rather
    // than by reading one, so it keeps a plain select; a scalar child uses the
    // searchable browser below. The reference options for the former are one of
    // the handlers, gated on the same iterability.
    $iterable = $shape->isIterable();
    $ajax = [
      'callback' => [self::class, 'refreshChildAjax'],
      'wrapper' => $wrapperId,
    ];
    $context = new ChildrenMatchFormContext($this, $source, $shape, $scope, $form_state, $ajax);

    // Each handler offers only the choices the entity's own field tree cannot
    // express — Use Default, Expand, a reference to iterate, a raw literal —
    // in registration order, so the grouped option array is stable. Real field
    // matches are deliberately absent: for a scalar child they come from the
    // searchable browser below, which is the whole point of using it. A field
    // source (views) registers its own handlers into the same list, after the
    // mapper's own and unable to shadow them.
    $options = [];
    foreach ($this->handlerList($source) as $handler) {
      $handler->setStringTranslation($this->getStringTranslation());
      $handler->addOptions($options, $context);
    }

    $field = $configuration['field'] ?? NULL;
    $flatOptions = $this->flattenArray($options);
    // Requiredness is deliberately not enforced on this control. An unbound
    // child shape is a legal and common state — it simply hides the child — and
    // the group-then-field pair this replaces only ever raised the error once a
    // group had been picked. Enforcing it now would make every already-saved
    // component with an unbound required child unsavable.
    $emptyOption = $shape->isRequired() ? $this->t('- Select -') : $this->t('- None -');

    // Inside the mapping table the row already names the child, so the
    // control's own label is for screen readers only.
    $compact = !empty($form['#compact']);
    $fieldTitle = $compact ? $shape->getTitle() : $this->t('Field');

    if ($iterable) {
      // A key that is no longer offered — the reference field was deleted, or
      // the iteration source changed under it — must not be handed back to the
      // select, which would render it as an illegal choice. The browsable
      // control below needs no such guard: it renders a stale key flagged as
      // missing rather than silently blanking it.
      $field = isset($flatOptions[$field]) ? $field : NULL;
      $form['field'] = [
        '#type' => 'select',
        '#title' => $fieldTitle,
        '#title_display' => $compact ? 'invisible' : 'before',
        '#description' => $shape->getDescription(),
        '#options' => $options,
        '#empty_option' => $emptyOption,
        '#default_value' => $field,
        '#ajax' => $ajax,
      ];
    }
    else {
      // One searchable, browsable control in place of the group-then-field pair
      // of selects, the same one the single-value providers use. The group step
      // existed only to keep the option count down — the match list for a child
      // shape runs to hundreds of entries once the iterated entity's references
      // are walked — and cost the ability to find a field by name without
      // already knowing which reference path reaches it. Everything above rides
      // along as a pinned extra, since none of it is a field on the tree.
      $form['field'] = [
        '#type' => 'neo_field_select',
        '#title' => $fieldTitle,
        '#title_display' => $compact ? 'invisible' : 'before',
        '#description' => $shape->getDescription(),
        '#component' => $shape->getComponent()->id(),
        '#prop' => $shape->getRootShape()->getName(),
        '#shape' => $shape->id(),
        // The fields belong to the entity being iterated, not to the one the
        // component is attached to.
        '#entity_type' => $scope->entityTypeId,
        '#bundle' => $scope->bundle,
        '#extra' => $flatOptions,
        '#empty_option' => $emptyOption,
        '#default_value' => $field,
        '#ajax' => $ajax,
      ];
    }

    if ($field) {
      // The chosen field's handler owns its configuration branch.
      $name = $this->handlerName($field);
      $handler = $name !== NULL ? $this->resolveHandler($name, $source) : NULL;
      $branch = NULL;
      if ($handler) {
        $handler->setStringTranslation($this->getStringTranslation());
        $branch = $handler->buildForm($form, $configuration, $context);
      }
      if ($branch !== NULL) {
        $form = $branch;
      }
      else {
        // A handler with no branch of its own for this choice — a raw boolean,
        // "This entity", Use Default — and every plain field match fall through
        // to the inline value-plugin form.
        $pluginDefaults = $configuration['plugins'] ?? [];
        $form = $this->buildPluginConfigurationForm($shape, $pluginDefaults, $form, $form_state);
      }
    }
    return $form;
  }

  /**
   * Validates one child row.
   */
  public static function validateChildForm(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    $values = $form_state->getValue($element['#parents']) ?? [];
    // Store plugin IDs for use in schema.
    $values['plugins'] = $values['plugins'] ?? [];
    foreach ($values['plugins'] as $pluginId => &$plugin) {
      if (empty($plugin['status'])) {
        unset($values['plugins'][$pluginId]);
        continue;
      }
      $plugin['plugin_id'] = $pluginId;
    }
    if (empty($values['plugins'])) {
      unset($values['plugins']);
    }
    if (empty($values['field'])) {
      $values = [];
    }
    $form_state->setValue($element['#parents'], $values);
  }

  /**
   * Recursively fills child shapes from a list of entities.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface $source
   *   The producer, consulted for its own field choices.
   * @param array $shapeNames
   *   The child shape names to fill.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface $shape
   *   The ROOT children-match shape. It stays the root through every recursion
   *   because the child-state calls below (hide/default/enable-plugin) are all
   *   keyed by a chained shape id the root owns.
   * @param array $entities
   *   The entities to read the values from.
   * @param bool $published
   *   The resolved published-entity decision, threaded down from the provider
   *   root by getValues() and by every recursing handler. It governs the level
   *   being filled AND is handed on to the handlers so nested levels filter the
   *   same way. It is never re-derived from $configuration, which below the
   *   first level is a CHILD settings array that does not carry the flag.
   * @param array $configuration
   *   The configuration for this level.
   * @param string|null $parentId
   *   The chained shape id of the level being filled.
   * @param bool|null $iterable
   *   Whether the shape being filled takes a delta-keyed LIST. Defaults to the
   *   root's own iterability, which is only correct for the outermost call:
   *   a nested level fills a CHILD shape, and that child decides the shape of
   *   the value, not the root. Getting this from the root collapsed an array
   *   child (`links`) to the bare property map of its first item, so the
   *   authored list came back as `['link' => …, 'button_style' => …]` instead
   *   of `[0 => ['link' => …, 'button_style' => …]]` — a value ArrayShape
   *   cannot read (it keeps integer deltas only), and one that made
   *   ArrayShape::getDefaultSchemaValue() fatal as soon as a scalar child
   *   landed at a string key.
   *
   * @return mixed
   *   The values.
   */
  public function fetchValues(ChildrenMatchSourceInterface $source, array $shapeNames, ComponentShapeChildrenMatchPluginInterface $shape, array $entities, bool $published, array $configuration = [], ?string $parentId = NULL, ?bool $iterable = NULL): mixed {
    /** @var \Drupal\Core\Entity\ContentEntityInterface[] $entities */
    $values = [];
    $delta = 0;
    $iterable = $iterable ?? $shape->isIterable();
    // The pseudo-field handlers active for this mapping: the mapper's own, plus
    // any the source registers. A source's cannot shadow a built-in.
    $handlerMap = $this->handlerMap($source);
    if ($entities) {
      $parentId = $parentId ?? $shape->id();
      foreach (array_filter($entities) as $entity) {
        // The published policy, applied against the decision threaded in from
        // the provider root. A source may narrow its own query with the same
        // flag so unpublished rows do not consume slots in a range window, but
        // this is the decision that stands — no producer applies a policy of
        // its own any more. The same $published reaches every nested level,
        // through the handlers below, so a followed reference or an expanded
        // child filters exactly as this level does rather than walking on to
        // unpublished entities unchecked.
        if ($published && $entity instanceof EntityPublishedInterface && !$entity->isPublished()) {
          continue;
        }
        $shape->addCacheableDependency($entity);
        foreach ($shapeNames as $shapeName) {
          $shapeId = implode('~', [$parentId, $shapeName]);
          $values[$delta][$shapeName] = [];
          $settings = $configuration['shape_fields'][$shapeName] ?? [];
          $field = $settings['field'] ?? NULL;
          // Queue child shape plugins.
          if (!empty($settings['plugins'])) {
            foreach ($settings['plugins'] as $pluginId => $pluginSettings) {
              if ($pluginSettings['status'] ?? FALSE) {
                $shape->getChildShapeState()->enablePlugin($shapeId, $pluginId, $pluginSettings['settings'] ?? []);
              }
              else {
                $shape->getChildShapeState()->disablePlugin($shapeId, $pluginId);
              }
            }
          }
          if ($field) {
            $name = $this->handlerName($field);
            $handler = $name !== NULL ? ($handlerMap[$name] ?? NULL) : NULL;
            if ($handler) {
              $context = new ChildrenMatchField($shapeId, $shapeName, $delta, $shape, $entity, $settings, $published);
              $value = $handler->fetch($context, $this, $source);
              if (!is_null($value)) {
                $values[$delta][$shapeName] = $value;
              }
              elseif ($handler->removeChildWhenAbsent()) {
                // "Use Default" means this provider contributes NOTHING to the
                // child, so its key is removed rather than left as the [] it
                // was seeded with above.
                //
                // An empty array IS a value to the `??` chain in
                // Object/ArrayShape::loadChildSchema(), which distributes this
                // value onto the child schemas. Leaving [] therefore overwrote
                // the child's own `examples`, and the child then dutifully
                // "used the default" — of nothing. Absent, the chain falls
                // through to the SDC example, which is what the setting names.
                //
                // Every other handler keeps its []: a child bound to a real
                // source that resolved empty must render nothing, rather than
                // fall back to the component's placeholder content.
                unset($values[$delta][$shapeName]);
              }
              continue;
            }
            // A key the field matcher understands — most often `_entity:*`, or
            // a plain field — has no handler and is read straight off the
            // entity rather than being swallowed.
            $values[$delta][$shapeName] = $this->matcherField->getEntityValue(
              entity: $entity,
              key: $field,
              default: [],
              published: $published,
              cacheableMetadata: $shape->getCacheableMetadata()
            );
          }
          else {
            // Hide the shape if no field is selected.
            $shape->getChildShapeState()->setFlag($shapeId, ChildShapeState::HIDDEN);
          }
        }
        // Remove values that are completely empty.
        if (!array_filter($values[$delta])) {
          unset($values[$delta]);
        }
        $delta++;
      }
    }
    elseif (!$iterable) {
      // When we have no entities, we return empty values for each shape so that
      // the shape will not be shown.
      foreach ($shapeNames as $shapeName) {
        $values[$delta][$shapeName] = [];
        $shape->getChildShapeState()->setFlag($shapeName, ChildShapeState::HIDDEN);
      }
    }
    if (!$iterable) {
      $values = reset($values) ?: [];
    }
    return $values;
  }

  /**
   * The handler name a stored field key resolves to, or NULL for a real field.
   *
   * Strips a `:suffix` then a `~key` then the leading underscore, so
   * `_raw:string` and `_reference~field_ref` both name their handler. A key
   * that is not underscore-prefixed is a plain field and names no handler.
   *
   * @param string $field
   *   The stored field key.
   *
   * @return string|null
   *   The handler name, or NULL.
   */
  protected function handlerName(string $field): ?string {
    if (!str_starts_with($field, '_')) {
      return NULL;
    }
    $head = explode(':', $field)[0];
    $head = explode('~', $head)[0];
    return substr($head, 1);
  }

  /**
   * The handlers active for a mapping, keyed by name, built-ins winning.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface $source
   *   The producer whose contributed handlers to merge in.
   *
   * @return \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchHandlerInterface[]
   *   Handlers keyed by name.
   */
  protected function handlerMap(ChildrenMatchSourceInterface $source): array {
    $map = $this->handlers;
    foreach ($this->sourceHandlers($source) as $handler) {
      // Union keeps a built-in of the same name — a source cannot shadow one.
      $map += [$handler->getName() => $handler];
    }
    return $map;
  }

  /**
   * The one handler that owns a field key, built-ins winning, or NULL.
   *
   * @param string $name
   *   The handler name from handlerName().
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface $source
   *   The producer whose contributed handlers to consider.
   *
   * @return \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchHandlerInterface|null
   *   The handler, or NULL when the key is a field-matcher key.
   */
  protected function resolveHandler(string $name, ChildrenMatchSourceInterface $source): ?ChildrenMatchHandlerInterface {
    return $this->handlerMap($source)[$name] ?? NULL;
  }

  /**
   * Every handler, in option order: the mapper's own then the source's.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface $source
   *   The producer whose contributed handlers to append.
   *
   * @return \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchHandlerInterface[]
   *   The handlers, in the order their options are offered.
   */
  protected function handlerList(ChildrenMatchSourceInterface $source): array {
    return array_merge(array_values($this->handlers), $this->sourceHandlers($source));
  }

  /**
   * The handlers a source contributes, or none.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface $source
   *   The producer.
   *
   * @return \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchHandlerInterface[]
   *   The contributed handlers.
   */
  protected function sourceHandlers(ChildrenMatchSourceInterface $source): array {
    return $source instanceof ChildrenMatchFieldSourceInterface ? $source->getChildrenMatchHandlers() : [];
  }

  /**
   * Resolves a child shape from a chained children-match shape id.
   *
   * Handlers always receive the ROOT children-match shape while the shape id
   * chains as `rootId~child~grandchild` through nested _expand/_reference
   * levels. Walks each segment via getValueResolverShape() — the accessor
   * that is safe while getDefaultValue() is being memoized (getChildShapes()
   * would recurse).
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface $root
   *   The root children-match shape.
   * @param string $shapeId
   *   The chained shape id.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface|null
   *   The uninitialized child shape, or NULL if the path does not resolve.
   */
  public function getChildShapeById(ComponentShapeChildrenMatchPluginInterface $root, string $shapeId): ?ComponentShapePluginInterface {
    $path = $shapeId;
    if (str_starts_with($path, $root->id() . '~')) {
      $path = substr($path, strlen($root->id()) + 1);
    }
    $current = $root;
    foreach (explode('~', $path) as $segment) {
      if (!$current instanceof ComponentShapeChildrenMatchPluginInterface) {
        return NULL;
      }
      $current = $current->getValueResolverShape($segment);
      if (!$current) {
        return NULL;
      }
    }
    return $current;
  }

  /**
   * Whether the child shape at a chained shape id takes a delta-keyed list.
   *
   * Falls back to FALSE when the path does not resolve — an unresolvable child
   * cannot be an array shape, and the flat form is what every non-array child
   * (the common case) wants.
   */
  public function isChildIterable(ComponentShapeChildrenMatchPluginInterface $shape, string $shapeId): bool {
    return (bool) $this->getChildShapeById($shape, $shapeId)?->isIterable();
  }

  /**
   * Flattens a multi-dimensional array to a single-dimensional array.
   */
  protected function flattenArray(array $array): array {
    $result = [];
    array_walk_recursive($array, function ($value, $key) use (&$result) {
      $result[$key] = $value;
    });
    return $result;
  }

}
