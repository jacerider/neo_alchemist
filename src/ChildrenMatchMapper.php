<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\Event\ComponentValueEvent;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValuePluginTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Maps entities onto the child shapes of a children-match shape.
 *
 * This is the whole of what the seven producers used to share by mixing in a
 * trait: the Property/Source mapping table, the pseudo fields behind it, the
 * published-entity policy, and the decision between a delta-keyed list and a
 * flat property map. Producers differ only in which entities they resolve and
 * how that is configured, which is
 * \Drupal\neo_alchemist\ChildrenMatchSourceInterface.
 *
 * It is a service, so its collaborators are constructor arguments. As a trait
 * they were three undeclared properties every consumer had to remember to
 * assign in a hand-written constructor, and forgetting one produced no error
 * until a particular mapping path ran — which is exactly how the views
 * reference-mapping fatal reached production.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatchSourceInterface
 * @see \Drupal\neo_alchemist\ChildrenMatchResult
 */
class ChildrenMatchMapper {

  use ComponentValuePluginTrait;
  use FieldFormatterTrait;
  use StringTranslationTrait;

  /**
   * The underscore choices the mapper resolves itself.
   *
   * Stored as `_<name>` on a child, optionally with a `:suffix` or a `~key`.
   * A source may add more through ChildrenMatchFieldSourceInterface; anything
   * neither this list nor a source claims is a field-matcher key.
   *
   * @see \Drupal\neo_alchemist\ChildrenMatchFieldSourceInterface
   */
  protected const PSEUDO_FIELDS = [
    'default',
    'event',
    'expand',
    'reference',
    'self',
    'render',
    'raw',
  ];

  /**
   * Constructs a ChildrenMatchMapper.
   *
   * @param \Drupal\neo_alchemist\MatcherField $matcherField
   *   The field matcher: reads a value off an entity for a stored field key.
   * @param \Drupal\neo_alchemist\MatcherReference $matcherReference
   *   The reference matcher: resolves and follows entity reference fields.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher, for the `_event` pseudo field.
   * @param \Drupal\neo_alchemist\ComponentValuePluginManagerInterface $valuePluginManager
   *   The value plugin manager, for labelling copy-mapping sources.
   * @param \Drupal\Core\Field\FormatterPluginManager $fieldFormatterManager
   *   The formatter manager, for the `_render` pseudo field's formatter form.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, for formatter third-party settings forms.
   */
  public function __construct(
    protected MatcherField $matcherField,
    protected MatcherReference $matcherReference,
    protected EventDispatcherInterface $eventDispatcher,
    protected ComponentValuePluginManagerInterface $valuePluginManager,
    FormatterPluginManager $fieldFormatterManager,
    ModuleHandlerInterface $moduleHandler,
  ) {
    // FieldFormatterTrait declares these and would otherwise reach for
    // \Drupal::service() the first time a formatter form is built.
    $this->fieldFormatterManager = $fieldFormatterManager;
    $this->moduleHandler = $moduleHandler;
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
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
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
   * @param \Drupal\neo_alchemist\ChildrenMatchSourceInterface $source
   *   The producer.
   * @param \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface $shape
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
   * @param \Drupal\neo_alchemist\ChildrenMatchSourceInterface $source
   *   The producer.
   * @param \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface $shape
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
    return $this->fetchValues($source, $shape->getChildShapeNames(), $shape, $result->entities, $configuration);
  }

  /**
   * The mapping table, plus the Advanced group behind it.
   */
  protected function buildMappingForm(ChildrenMatchSourceInterface $source, ComponentShapeChildrenMatchPluginInterface $shape, array $form, FormStateInterface $form_state, ChildrenMatchScope $scope, array $configuration): array {
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
   * @param \Drupal\neo_alchemist\ChildrenMatchSourceInterface $source
   *   The producer asking, excluded from its own list.
   * @param \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface $shape
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
  protected function buildChildForm(ChildrenMatchSourceInterface $source, ComponentShapePluginInterface $shape, array $form, FormStateInterface $form_state, ChildrenMatchScope $scope, array $configuration): array {
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
    // Only the choices the entity's own field tree cannot express. Real field
    // matches are deliberately absent: for a scalar child shape they come from
    // the searchable browser below, which is the whole point of using it.
    $options = [
      '- Shape -' => [
        '_default' => $this->t('Use Default'),
        '_event' => $this->t('Use Event'),
      ],
    ];

    // An array child shape is filled by *iterating* a reference field rather
    // than by reading one, so its choices are reference fields and it keeps a
    // plain select. The browser answers "what can supply this value", which for
    // an array shape is every scalar field on the entity — a thousand matches,
    // none of which can fill a list.
    $iterable = $shape->isIterable();
    if ($iterable) {
      $refOptions = $this->matcherReference->getReferencesAsOptions($scope->entityTypeId, $scope->bundle);
      foreach ($refOptions as $group => $refs) {
        foreach ($refs as $refKey => $refLabel) {
          $options[$group]['_reference~' . $refKey] = $refLabel;
        }
      }
    }
    if (in_array($shape->getRef(), ['markup', 'string'])) {
      $options['- Shape -']['_render'] = $this->t('Render with field formatter');
    }
    if ($shape instanceof ComponentShapeMediaPluginInterface && $scope->entityTypeId === 'media') {
      // The iterated entity is itself a media entity a media shape can consume
      // (e.g. a media reference field used as the iteration source). Bundle
      // support is deliberately not checked here — the placeholder bundle is
      // unreliable for multi-bundle references — the strict check happens at
      // fetch time in fetchSelf().
      $options['- Shape -']['_self'] = $this->t('This entity');
    }
    if ($shape->getType() === 'boolean') {
      $options['- Raw -']['_raw:boolean_true'] = $this->t('Boolean: True');
      $options['- Raw -']['_raw:boolean_false'] = $this->t('Boolean: False');
    }
    if ($shape->getType() === 'string') {
      $options['- Raw -']['_raw:string'] = $shape->getFieldOptions() ? $this->t('Option') : $this->t('String');
    }
    if ($shape->isExpandable()) {
      $options['- Shape -']['_expand'] = $this->t('Expand to configure child shapes');
    }
    if ($source instanceof ChildrenMatchFieldSourceInterface) {
      $source->alterChildrenMatchOptions($options, $shape, $form_state);
    }

    $field = $configuration['field'] ?? NULL;
    $flatOptions = $this->flattenArray($options);
    // Requiredness is deliberately not enforced on this control. An unbound
    // child shape is a legal and common state — it simply hides the child — and
    // the group-then-field pair this replaces only ever raised the error once a
    // group had been picked. Enforcing it now would make every already-saved
    // component with an unbound required child unsavable.
    $emptyOption = $shape->isRequired() ? $this->t('- Select -') : $this->t('- None -');
    $ajax = [
      'callback' => [self::class, 'refreshChildAjax'],
      'wrapper' => $wrapperId,
    ];

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
      $find = explode('~', $field)[0];
      switch ($find) {
        case '_render':
          $renderFieldId = $configuration['render_field'] ?? NULL;
          $form['render_field'] = [
            '#type' => 'neo_field_select',
            '#title' => $this->t('Field to render'),
            '#required' => TRUE,
            '#component' => $shape->getComponent()->id(),
            '#prop' => $shape->getRootShape()->getName(),
            '#shape' => $shape->id(),
            '#all' => TRUE,
            '#entity_type' => $scope->entityTypeId,
            '#bundle' => $scope->bundle,
            '#empty_option' => $this->t('- Select -'),
            '#default_value' => $renderFieldId,
            '#ajax' => $ajax,
          ];
          if ($renderFieldId) {
            $renderField = $this->matcherField->getFieldDefinition($shape, $renderFieldId, $scope->entityTypeId, $scope->bundle, NULL, TRUE);
            if ($renderField) {
              $form['render_field_format'] = [
                '#type' => 'fieldset',
                '#title' => $this->t('Formatter'),
              ];
              $renderFieldFormatConfiguration = $configuration['render_field_format'] ?? [];
              $form['render_field_format'] = $this->formatterConfigurationForm($form['render_field_format'], $form_state, $renderField, $renderFieldFormatConfiguration, $ajax);
            }
          }
          break;

        case '_event':
          $form['info'] = [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#attributes' => ['class' => ['messages', 'messages--warning']],
            '#value' => $this->t('Will call the <em>\Drupal\neo_alchemist\Event\ComponentValueEvent</em> to get the value.'),
          ];
          break;

        case '_expand':
          if ($shape instanceof ComponentShapeChildrenMatchPluginInterface) {
            foreach ($shape->getChildShapes() as $childShapeName => $childShape) {
              $form['shape_fields'][$childShapeName] = [
                '#id' => $wrapperId . '-' . $childShapeName,
                '#parents' => array_merge($form['#parents'], [
                  'shape_fields',
                  $childShapeName,
                ]),
              ];
              $form['shape_fields'][$childShapeName] = $this->buildChildForm($source, $childShape, $form['shape_fields'][$childShapeName], $form_state, $scope, $configuration['shape_fields'][$childShapeName] ?? []);
            }
          }
          break;

        case '_reference':
          $parts = explode('~', $field);
          $entityKey = $parts[1];
          $referenceEntity = $this->matcherReference->getReferenceEntityByEntityType($scope->entityTypeId, $entityKey);
          if ($referenceEntity) {
            // The nested level maps a DIFFERENT entity, so it gets its own
            // scope. Reusing the outer one offered the wrong entity's fields.
            $referenceScope = new ChildrenMatchScope($referenceEntity->getEntityTypeId(), $referenceEntity->bundle());
            $form = $this->buildMappingForm($source, $shape, $form, $form_state, $referenceScope, $configuration);
          }
          $form['#type'] = 'details';
          break;

        case '_raw:string':
          if ($stringOptions = $shape->getFieldOptions()) {
            $form['string'] = [
              '#type' => 'select',
              '#title' => $this->t('Value'),
              '#options' => $stringOptions,
              '#default_value' => $configuration['string'] ?? '',
            ];
            break;
          }
          else {
            $form['string'] = [
              '#type' => 'textfield',
              '#title' => $this->t('Value'),
              '#default_value' => $configuration['string'] ?? '',
            ];
          }
          break;

        default:
          $pluginDefaults = $configuration['plugins'] ?? [];
          $form = $this->buildPluginConfigurationForm($shape, $pluginDefaults, $form, $form_state);
          break;
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
   * @param \Drupal\neo_alchemist\ChildrenMatchSourceInterface $source
   *   The producer, consulted for its own field choices.
   * @param array $shapeNames
   *   The child shape names to fill.
   * @param \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface $shape
   *   The ROOT children-match shape. It stays the root through every recursion
   *   because the child-state calls below (hide/default/enable-plugin) are all
   *   keyed by a chained shape id the root owns.
   * @param array $entities
   *   The entities to read the values from.
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
  protected function fetchValues(ChildrenMatchSourceInterface $source, array $shapeNames, ComponentShapeChildrenMatchPluginInterface $shape, array $entities, array $configuration = [], ?string $parentId = NULL, ?bool $iterable = NULL): mixed {
    /** @var \Drupal\Core\Entity\ContentEntityInterface[] $entities */
    $values = [];
    $delta = 0;
    $iterable = $iterable ?? $shape->isIterable();
    $published = !empty($configuration['shape_published']);
    $fieldSource = $source instanceof ChildrenMatchFieldSourceInterface ? $source : NULL;
    $sourcePrefixes = $fieldSource?->getChildrenMatchFieldPrefixes() ?? [];
    if ($entities) {
      $parentId = $parentId ?? $shape->id();
      foreach (array_filter($entities) as $entity) {
        // The one place the published policy is implemented. A source may
        // narrow its own query with the same flag so unpublished rows do not
        // consume slots in a range window, but this is the decision that
        // stands — no producer applies a policy of its own any more.
        //
        // It governs the level being filled. Nested _expand/_reference levels
        // recurse with the CHILD's settings, which never carry
        // shape_published, so they do not filter — the trait behaved the same
        // way and changing it would drop content from already-configured
        // components. Recorded on ticket 04 for a follow-up.
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
            if (str_starts_with($field, '_')) {
              $parts = explode(':', $field);
              $parts = explode('~', $parts[0]);
              $name = substr($parts[0], 1);
              $context = new ChildrenMatchField($shapeId, $shapeName, $delta, $shape, $entity, $settings);
              // Anything not listed here — most often `_entity:*` — is a key
              // the field matcher understands, and must fall through to it
              // rather than be swallowed. A source only gets the prefixes it
              // declared.
              $handled = in_array($name, self::PSEUDO_FIELDS, TRUE)
                || ($fieldSource && in_array($name, $sourcePrefixes, TRUE));
              if ($handled) {
                $value = match ($name) {
                  'default' => $this->fetchDefault($context),
                  'event' => $this->fetchEvent($context),
                  'expand' => $this->fetchExpand($source, $context),
                  'reference' => $this->fetchReference($source, $context),
                  'self' => $this->fetchSelf($context),
                  'render' => $this->fetchRender($context),
                  'raw' => $this->fetchRaw($context),
                  default => $fieldSource->fetchChildrenMatchField($name, $context),
                };
                if (!is_null($value)) {
                  $values[$delta][$shapeName] = $value;
                }
                elseif ($name === 'default') {
                  // "Use Default" means this provider contributes NOTHING to
                  // the child, so its key is removed rather than left as the []
                  // it was seeded with above.
                  //
                  // An empty array IS a value to the `??` chain in
                  // Object/ArrayShape::loadChildSchema(), which distributes
                  // this value onto the child schemas. Leaving [] therefore
                  // overwrote the child's own `examples`, and the child then
                  // dutifully "used the default" — of nothing. Absent, the
                  // chain falls through to the SDC example, which is precisely
                  // what the setting names.
                  //
                  // Deliberately NOT applied to every NULL-returning handler: a
                  // child bound to a real field that resolved empty must keep
                  // its [] so the prop renders nothing, rather than falling
                  // back to the component's placeholder content.
                  unset($values[$delta][$shapeName]);
                }
                continue;
              }
            }
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
   * Handles `_default`: contribute nothing, so the child keeps its own example.
   */
  protected function fetchDefault(ChildrenMatchField $field): mixed {
    $field->shape->getChildShapeState()->setFlag($field->shapeId, ChildShapeState::USE_DEFAULT, TRUE);
    return NULL;
  }

  /**
   * Handles `_event`: ask a subscriber for the value.
   */
  protected function fetchEvent(ChildrenMatchField $field): mixed {
    $event = new ComponentValueEvent($field->shape, [], $field->entity, $field->delta, $field->shapeId);
    $this->eventDispatcher->dispatch($event, ComponentValueEvent::EVENT_NAME);
    $value = $event->getValue();
    $field->shape->addCacheableDependency($event);
    return $value;
  }

  /**
   * Handles `_expand`: map this same entity onto the child's own children.
   */
  protected function fetchExpand(ChildrenMatchSourceInterface $source, ChildrenMatchField $field): mixed {
    if ($field->settings['shape_fields'] ?? []) {
      $childShapeNames = array_keys($field->settings['shape_fields']);
      return $this->fetchValues($source, $childShapeNames, $field->shape, [$field->entity], $field->settings, $field->shapeId, $this->isChildIterable($field->shape, $field->shapeId));
    }
    return NULL;
  }

  /**
   * Handles `_reference~key`: map the entities that reference field points at.
   */
  protected function fetchReference(ChildrenMatchSourceInterface $source, ChildrenMatchField $field): mixed {
    if ($field->settings['shape_fields'] ?? []) {
      $entityKey = explode('~', $field->settings['field'])[1];
      $referenceField = $this->matcherReference->getReferenceField($field->entity, $entityKey, $field->shape->getCacheableMetadata());
      if ($referenceField) {
        $childShapeNames = array_keys($field->settings['shape_fields']);
        return $this->fetchValues($source, $childShapeNames, $field->shape, $referenceField->referencedEntities(), $field->settings, $field->shapeId, $this->isChildIterable($field->shape, $field->shapeId));
      }
    }
    return NULL;
  }

  /**
   * Handles `_self`: the iterated entity IS the media the child wants.
   *
   * Used when the iteration source yields entities a child shape can consume
   * directly — a media reference field iterated by entity_reference, a media
   * entity query, etc. The media shape's own converter builds the value, so
   * the child receives the same structure a stored media reference would
   * produce.
   */
  protected function fetchSelf(ChildrenMatchField $field): mixed {
    if (!$field->entity instanceof MediaInterface) {
      return NULL;
    }
    $childShape = $this->getChildShapeById($field->shape, $field->shapeId);
    if (!$childShape instanceof ComponentShapeMediaPluginInterface || !in_array($field->entity->bundle(), $childShape->getSupportedMediaTypes(), TRUE)) {
      return NULL;
    }
    return $childShape->getValueFromMedia($field->entity) ?: NULL;
  }

  /**
   * Handles `_render`: run a field through a formatter, keep the render array.
   *
   * The published flag is read from the CHILD's settings, where it is never
   * written — `shape_published` lives at the provider root — so this resolves
   * FALSE every time and the matcher walk does not drop unpublished
   * intermediate entities. That is what the trait did, bug and all, and it is
   * preserved deliberately: reading the provider's flag here instead would
   * quietly change what already-configured `_render` mappings put on a page,
   * which is a behaviour change this refactor has no mandate to make. Recorded
   * on ticket 04 for a follow-up that can fix it with its own test.
   */
  protected function fetchRender(ChildrenMatchField $field): mixed {
    if (!empty($field->settings['render_field'])) {
      $published = !empty($field->settings['shape_published']);
      $item = $this->matcherField->getEntityField($field->entity, $field->settings['render_field'], $published, $field->shape->getCacheableMetadata());
      if ($item && !$item->isEmpty() && !empty($field->settings['render_field_format']['field_plugin'])) {
        $build = $item->view([
          'type' => $field->settings['render_field_format']['field_plugin'],
          'label' => $field->settings['render_field_format']['field_label'] ?? 'hidden',
          'settings' => $field->settings['render_field_format']['field_settings'] ?? [],
        ]);
        $cacheableMetadata = CacheableMetadata::createFromRenderArray($build);
        $field->shape->addCacheableDependency($cacheableMetadata);
        return ComponentPropRenderable::create($build);
      }
    }
    return NULL;
  }

  /**
   * Handles `_raw:*`: a literal the site builder typed.
   */
  protected function fetchRaw(ChildrenMatchField $field): mixed {
    $fieldName = substr($field->settings['field'], 5);
    return match ($fieldName) {
      'boolean_true' => TRUE,
      'boolean_false' => FALSE,
      'string' => $field->settings['string'] ?? '',
      default => NULL,
    };
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
   * @param \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface $root
   *   The root children-match shape.
   * @param string $shapeId
   *   The chained shape id.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface|null
   *   The uninitialized child shape, or NULL if the path does not resolve.
   */
  protected function getChildShapeById(ComponentShapeChildrenMatchPluginInterface $root, string $shapeId): ?ComponentShapePluginInterface {
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
  protected function isChildIterable(ComponentShapeChildrenMatchPluginInterface $shape, string $shapeId): bool {
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
