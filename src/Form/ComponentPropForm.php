<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\neo_alchemist\Ajax\ComponentAjaxFormHelperTrait;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\Shape\ComponentShapePluginCollection;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValueGroupPluginManager;
use Drupal\neo_alchemist\Value\ComponentValuePluginInterface;
use Drupal\neo_icon\IconTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component prop form.
 *
 * Organized prop-first: one vertical tab per plugin-bearing shape (the prop
 * itself, then each expanded child), with the four value groups stacked inside
 * each tab as collapsed, badged sections. Multi-plugin groups render a
 * list↔edit state machine — only ACTIVE providers are listed, as summary rows,
 * and one provider's settings form is open at a time — instead of a table of
 * every applicable plugin with its settings inline.
 *
 * The working state between AJAX interactions is the form object's own entity:
 * every mutation runs through ::validateForm() and is serialized onto the
 * (cached, unsaved) entity via setPropShapeSettings(). Nothing persists until
 * Save. That is the staged plugin list mold, which this form and
 * ComponentSlotForm are the two adapters of; an item here is addressed by
 * plugin id within a shape × group section, since a shape holds each provider
 * at most once and one form carries many sections.
 *
 * @see \Drupal\neo_alchemist\Form\StagedPluginListInterface
 */
final class ComponentPropForm extends EntityForm implements StagedPluginListInterface {

  use ComponentAjaxFormHelperTrait;
  use IconTrait;
  use StagedPluginListTrait;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The group manager.
   *
   * @var \Drupal\neo_alchemist\Value\ComponentValueGroupPluginManager
   */
  protected $groupManager;

  /**
   * The shape.
   *
   * @var \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
   */
  protected $shape;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.neo_component_value_group'),
    );
  }

  /**
   * ComponentPropForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager service.
   * @param \Drupal\neo_alchemist\Value\ComponentValueGroupPluginManager $groupManager
   *   The group manager.
   */
  public function __construct(EntityTypeBundleInfoInterface $entity_type_bundle_info, EntityTypeManagerInterface $entity_type_manager, ComponentValueGroupPluginManager $groupManager) {
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityTypeManager = $entity_type_manager;
    $this->groupManager = $groupManager;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $prop = NULL) {
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $entity = $this->entity;
    $form_state->set('neo_component_manage_id', ComponentManageHelper::getId($this->entity));
    $this->shape = $entity->getPropShape($prop);
    $form['#title'] = $this->t('Edit %prop_label from %label', [
      '%prop_label' => $this->shape->getTitle(),
      '%label' => $entity->label(),
    ]);
    return parent::buildForm($form, $form_state);
  }

  /**
   * Get plugin shapes.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface[]
   *   The plugin shapes.
   */
  protected function getPluginShapes() {
    $expanded = $this->shape->getExpanded();
    if ($expanded) {
      // Iterable roots stay configurable while expanded (see
      // ComponentShapePluginBase::allowConfigurablePlugins()), so the root is
      // already returned here as the "Base" group alongside its expanded child
      // shapes.
      return $this->shape->getPluginShapes(TRUE);
    }
    if ($this->shape->allowConfigurablePlugins()) {
      return [$this->shape->id() => $this->shape];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $entity = $this->entity;
    $form = parent::form($form, $form_state);
    $wrapperId = Html::getId('alchemist-component-prop-form');
    $form['#id'] = $wrapperId;
    $shape = $this->shape;
    $expanded = $shape->getExpanded();
    $isExpanded = !empty($expanded);
    $pluginShapes = $this->getPluginShapes();
    $expandableShapes = $shape->getExpandedableShapes(TRUE);
    $isActive = $shape->isActive();

    if (!$form_state->get('original_prop')) {
      // Serialize the untouched shape once so the baseline is in the same
      // normalized form (key order, merged plugin defaults) every later
      // setPropShapeSettings() call produces — a raw-vs-normalized comparison
      // would flag unsaved changes on a freshly opened form. The entity is the
      // form's own unsaved copy; nothing persists here.
      $entity->setPropShapeSettings($this->shape);
      $props = $entity->getSetting('props', []);
      $form_state->set('original_prop', $props[$this->shape->getName()] ?? []);
    }

    // The prop's own options, up top where they frame everything below. The
    // toggles share one inline row; the sub-properties checkboxes get their
    // own row below (a multi-line group dangling off the toggle row reads as
    // misalignment).
    $form['options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Property options'),
      '#neo_size' => 'sm',
    ];
    $form['options']['toggles'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['form--inline', 'form--inline-min', 'items-center'],
      ],
    ];
    $form['options']['toggles']['active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Active'),
      '#description' => $this->t('Enable this property. If disabled, the property will not be available on this component.'),
      '#default_value' => $isActive,
      '#parents' => ['active'],
      '#ajax' => [
        'callback' => '::refreshFormAjax',
        'wrapper' => $wrapperId,
      ],
    ];
    if ($isActive) {
      $form['options']['toggles']['editable'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Allow Edit'),
        // The checkbox disables itself whenever the shape is locked. Say why
        // when the cause is the component-wide mode, since that is the one
        // cause the site builder can act on from here.
        '#description' => match ($entity->getPropEditability()) {
          ComponentInterface::PROP_EDITABILITY_LOCKED => $this->t('This component is set to <em>Locked</em>, so no property is editable. This setting is kept and takes effect again when the component leaves that mode.'),
          ComponentInterface::PROP_EDITABILITY_GUARDED => $this->t('Allow the default value of this property to be changed per component instance. This component is <em>Guarded</em>, so properties added to its template later default to non-editable.'),
          default => $this->t('Allow the default value of this property to be changed per component instance.'),
        },
        '#default_value' => $this->shape->getEditable(),
        '#disabled' => $this->shape->isLocked(),
        '#parents' => ['editable'],
      ];
      $form['options']['toggles']['required'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Required'),
        '#description' => $this->t('Require this property to be set for all component instances.'),
        '#default_value' => $this->shape->isRequired(),
        '#access' => $this->shape->allowRequired(),
        '#parents' => ['required'],
      ];
      if ($expandableShapes) {
        $parentShapeOptions = array_map(fn (ComponentShapePluginInterface $shape) => ($shape->isNested() ? $shape->getNestedTitle(TRUE) : $this->t('Root')), $expandableShapes);
        $form['options']['expanded'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Configure sub-properties'),
          '#description' => $this->t('Give the checked properties their own configuration tab, so each nested property can have its own value providers, default and modifiers.'),
          '#default_value' => $expanded,
          '#options' => $parentShapeOptions,
          '#parents' => ['expanded'],
          // The option items flow inline; only the group's title sits above.
          '#neo_style' => 'inline_elements',
          '#neo_size' => 'md',
          '#ajax' => [
            'callback' => '::refreshFormAjax',
            'wrapper' => $wrapperId,
          ],
        ];
      }
    }

    // Everything in this form only takes effect on Save; the ops below (add,
    // edit, remove, reorder) stage their changes on the unsaved entity.
    if ($this->propHasUnsavedChanges($form_state)) {
      $form['unsaved'] = [
        '#type' => 'status_message',
        '#style' => 'warning',
        '#value' => $this->t('Your changes are staged and take effect when you press %save.', ['%save' => $this->t('Save')]),
        '#weight' => -5,
      ];
    }

    if ($isActive && $pluginShapes) {
      $groups = $this->groupManager->getDefinitions();
      uasort($groups, [SortArray::class, 'sortByWeightElement']);

      $form['tabs'] = [
        '#type' => 'vertical_tabs',
      ];
      foreach ($pluginShapes as $pluginShape) {
        $form = $this->buildShapeForm($form, $form_state, $pluginShape, $groups, $isExpanded);
      }
    }

    return $form;
  }

  /**
   * Whether the prop's staged settings differ from the saved ones.
   */
  protected function propHasUnsavedChanges(FormStateInterface $form_state): bool {
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $entity = $this->entity;
    $props = $entity->getSetting('props', []);
    $current = $props[$this->shape->getName()] ?? [];
    return $current !== ($form_state->get('original_prop') ?? []);
  }

  /**
   * Builds one shape's tab: the value groups stacked as badged sections.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The plugin-bearing shape.
   * @param array $groups
   *   The value group definitions, sorted.
   * @param bool $isExpanded
   *   Whether the prop renders one tab per shape.
   *
   * @return array
   *   The form.
   */
  protected function buildShapeForm(array $form, FormStateInterface $form_state, ComponentShapePluginInterface $shape, array $groups, bool $isExpanded): array {
    $shapeId = $shape->id();
    $shapeKey = 'shape_' . $shapeId;

    if ($isExpanded) {
      $form[$shapeKey] = [
        '#type' => 'details',
        '#group' => 'tabs',
        // Vertical-tab menu titles must stay plain text — the tab menu clones
        // them — so the shape tab carries no badge; the group sections do.
        '#title' => $shape->isNested() ? $shape->getNestedTitle(FALSE) : $this->rootShapeTitle(),
        // A tab pane needs a STABLE id: the vertical-tabs JS restores the
        // active tab by matching the id stored in tabs__active_tab, and
        // Html::getUniqueId() appends a random suffix on every AJAX rebuild —
        // any AJAX interaction would snap the form back to the first tab.
        '#id' => Html::getId('edit-' . $shapeKey),
      ];
      $container = &$form[$shapeKey];
    }
    else {
      // A single un-expanded prop needs no per-shape tab level: each group is
      // its own tab, as before.
      $container = &$form;
    }

    foreach ($groups as $group) {
      $groupId = $group['id'];
      $collection = $shape->getValueCollection();
      $instances = array_filter(
        $collection->getInstancesByGroup($groupId),
        static fn (ComponentValuePluginInterface $instance) => $instance->isAllowed('manage')
      );
      if (!$instances) {
        continue;
      }
      $key = $groupId . '_' . $shapeId;
      $activeCount = count(array_intersect_key($instances, $collection->getActiveInstances($groupId)));
      $isOpen = $activeCount > 0 || $this->isOpKey($form_state, $shapeId, $groupId);

      if ($isExpanded) {
        $container[$key] = [
          '#type' => 'details',
          '#title' => $group['label'] . $this->sectionBadge($activeCount),
          '#open' => $isOpen,
          '#neo_size' => 'sm',
        ];
      }
      else {
        // The group sections ARE the vertical tabs here, and tab menu titles
        // must stay plain text — no badge markup. The stable #id keeps the
        // active tab selected across AJAX rebuilds (see the shape tab above).
        $container[$key] = [
          '#type' => 'details',
          '#title' => $group['label'],
          '#group' => 'tabs',
          '#id' => Html::getId('edit-' . $key),
        ];
      }

      if (count($instances) > 1) {
        $container[$key] = $this->buildPluginListForm($container[$key], $form_state, $shape, $groupId, $key, $instances);
      }
      else {
        // A group with a single possible plugin (the terminal Default, the
        // Widget settings) reads better inline than as a one-row list.
        $instance = reset($instances);
        $container[$key]['inline'][$instance->getPluginId()] = $this->buildInlineInstanceForm($form_state, $shape, $key, $instance, $collection->getStatus($instance->getPluginId()));
      }
    }
    return $form;
  }

  /**
   * The title of the root shape's tab.
   */
  protected function rootShapeTitle(): string {
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $entity = $this->entity;
    if ($entity->isAggregate() && $this->shape->getName() === '_aggregate') {
      return (string) $this->t('All properties');
    }
    return $this->shape->getTitle() ?: (string) $this->t('Base');
  }

  /**
   * A badge for a group section title.
   *
   * @param int $activeCount
   *   The number of active instances in the group.
   *
   * @return string
   *   Markup appended to the details title (the neo details template renders
   *   titles unescaped, the same idiom InstanceComponentForm uses for its
   *   value badges).
   */
  protected function sectionBadge(int $activeCount): string {
    if ($activeCount === 0) {
      return ' <small class="description">' . $this->t('Not configured') . '</small>';
    }
    return ' <div class="inline-block badge bg-primary text-primary-content leading-tight">' . $this->formatPlural($activeCount, '1 active', '@count active') . '</div>';
  }

  /**
   * Whether the current op targets a shape × group section.
   */
  protected function isOpKey(FormStateInterface $form_state, string $shapeId, string $groupId): bool {
    return $form_state->get('op') === self::OP_EDIT
      && $form_state->get('op_shape') === $shapeId
      && $form_state->get('op_group') === $groupId;
  }

  /**
   * Builds the list↔edit UI for a multi-plugin group.
   *
   * @param array $form
   *   The section container.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The plugin-bearing shape.
   * @param string $groupId
   *   The value group id.
   * @param string $key
   *   The section key ("{group}_{shapeId}").
   * @param \Drupal\neo_alchemist\Value\ComponentValuePluginInterface[] $instances
   *   The manageable instances in the group.
   *
   * @return array
   *   The section container.
   */
  protected function buildPluginListForm(array $form, FormStateInterface $form_state, ComponentShapePluginInterface $shape, string $groupId, string $key, array $instances): array {
    $collection = $shape->getValueCollection();
    $shapeId = $shape->id();
    $ajax = $this->refreshFormAjaxDefinition();

    // Edit state: one instance's settings form replaces the list.
    if ($this->isOpKey($form_state, $shapeId, $groupId)) {
      $pluginId = $form_state->get('op_plugin');
      if (isset($instances[$pluginId])) {
        return $this->buildPluginEditForm($form, $form_state, $key, $instances[$pluginId], (bool) $form_state->get('op_new'));
      }
    }

    $activeInstances = array_intersect_key($instances, $collection->getActiveInstances($groupId));
    $form['list'] = [
      '#type' => 'table',
      '#header' => [
        'label' => $this->t('Provider'),
        'ops' => '',
        'weight' => $this->t('Weight'),
      ],
      '#access' => !empty($activeInstances),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'table-sort-weight',
        ],
      ],
      '#parents' => [$key, 'list'],
    ];
    $weight = 0;
    foreach ($activeInstances as $pluginId => $instance) {
      $definition = $instance->getPluginDefinition();
      $row = [
        '#attributes' => ['class' => ['draggable']],
      ];
      $row['label'] = [
        '#type' => 'inline_template',
        '#template' => '{% if lock %}{{ lock }} {% endif %}<strong>{{ label }}</strong>{% for line in summary %}<br /><small class="description">{{ line }}</small>{% endfor %}{% if mode %}<br /><small class="description">&#9656; {{ mode }}</small>{% endif %}',
        '#context' => [
          'label' => $instance->label(),
          'summary' => $instance->settingsSummary(),
          'mode' => method_exists($instance, 'processingModeSummary') ? $instance->processingModeSummary() : NULL,
          'lock' => !empty($definition['status_lock']) ? $this->icon($this->t('Locked'), 'lock')->iconOnly() : NULL,
        ],
      ];
      $meta = [
        '#op_shape' => $shapeId,
        '#op_group' => $groupId,
        '#op_plugin' => $pluginId,
      ];
      $row['ops'] = [
        '#neo_size' => 'min',
      ];
      $row['ops']['edit'] = $this->stagedOpButton(self::OP_EDIT, 'edit-' . $key . '-' . $pluginId, $this->t('Edit'), $meta, $ajax);
      $row['ops']['remove'] = $this->stagedOpButton(self::OP_REMOVE, 'remove-' . $key . '-' . $pluginId, $this->t('Remove'), $meta, $ajax) + [
        '#access' => empty($definition['status_lock']),
      ];
      $row['weight'] = $this->stagedWeightCell($this->t('Weight for @title', ['@title' => $instance->label()]), $weight, 'table-sort-weight');
      $weight++;
      $form['list'][$pluginId] = $row;
    }

    $addOptions = [];
    foreach ($instances as $pluginId => $instance) {
      $definition = $instance->getPluginDefinition();
      if (isset($activeInstances[$pluginId]) || !empty($definition['status_lock'])) {
        continue;
      }
      $addOptions[$pluginId] = $instance->label();
    }
    if ($addOptions) {
      $form['add'] = $this->stagedAddSelect($this->t('Add provider'), $addOptions, [
        '#op_shape' => $shapeId,
        '#op_group' => $groupId,
        '#parents' => [$key, 'add'],
        '#neo_size' => 'xs',
      ], $ajax);
    }
    return $form;
  }

  /**
   * Builds the single-instance edit pane that replaces a section's list.
   *
   * @param array $form
   *   The section container.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $key
   *   The section key.
   * @param \Drupal\neo_alchemist\Value\ComponentValuePluginInterface $instance
   *   The instance being edited.
   * @param bool $isNew
   *   Whether the instance was just added (Cancel then reverts it).
   *
   * @return array
   *   The section container.
   */
  protected function buildPluginEditForm(array $form, FormStateInterface $form_state, string $key, ComponentValuePluginInterface $instance, bool $isNew): array {
    $definition = $instance->getPluginDefinition();
    $form['edit'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('@op %label', [
        '@op' => $isNew ? $this->t('Add') : $this->t('Edit'),
        '%label' => $instance->label(),
      ]),
      '#description' => $definition['description'],
      '#description_display' => 'before',
    ];
    $form['edit']['settings'] = [
      '#type' => 'container',
      // #tree only inherits downward; without it the plugin subform's inputs
      // flatten to bare, colliding names ("entity", "field", "status").
      '#tree' => TRUE,
      '#parents' => [$key, 'edit', 'settings'],
    ];
    $subformState = SubformState::createForSubform($form['edit']['settings'], $form, $form_state);
    $form['edit']['settings'] = $instance->buildConfigurationForm($form['edit']['settings'], $subformState, $form);

    // Always "Update", never "Create": a provider is added by the select
    // above, so this pane is never the thing that creates one — which is why
    // $isNew is FALSE here even when the pane opened on a just-added
    // provider. What Cancel does about that provider is OP_CANCEL's job.
    //
    // Cancel must not commit the pane it is closing, which is why the mold
    // gives it limited validation and Update none.
    $form['edit']['actions'] = $this->stagedEditActions($key, FALSE, [
      '#op_shape' => $form_state->get('op_shape'),
      '#op_group' => $form_state->get('op_group'),
      '#op_plugin' => $instance->getPluginId(),
    ], $this->refreshFormAjaxDefinition());
    return $form;
  }

  /**
   * Builds the inline form for a single-plugin group.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The plugin-bearing shape.
   * @param string $key
   *   The section key.
   * @param \Drupal\neo_alchemist\Value\ComponentValuePluginInterface $instance
   *   The instance.
   * @param bool $status
   *   Whether the instance is active.
   *
   * @return array
   *   The instance sub-form.
   */
  protected function buildInlineInstanceForm(FormStateInterface $form_state, ComponentShapePluginInterface $shape, string $key, ComponentValuePluginInterface $instance, bool $status): array {
    $definition = $instance->getPluginDefinition();
    $pluginId = $instance->getPluginId();
    $form = [
      '#type' => 'container',
      // Without #tree the status checkbox would flatten to a bare "status"
      // name shared by every inline instance on the page.
      '#tree' => TRUE,
      '#parents' => [$key, $pluginId],
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use @label', ['@label' => $instance->label()]),
      '#description' => $definition['description'],
      '#default_value' => $status,
      '#disabled' => !empty($definition['status_lock']),
      '#ajax' => $this->refreshFormAjaxDefinition(),
    ];
    if ($status) {
      $form['settings'] = [
        '#type' => 'container',
        '#parents' => array_merge($form['#parents'], ['settings']),
      ];
      $subformState = SubformState::createForSubform($form['settings'], $form, $form_state);
      $form['settings'] = $instance->buildConfigurationForm($form['settings'], $subformState, $form);
    }
    return $form;
  }

  /**
   * The whole-form AJAX wrapper id.
   */
  protected function formWrapperId(): string {
    return Html::getId('alchemist-component-prop-form');
  }

  /**
   * The '#ajax' definition every control on this form rebuilds through.
   *
   * @return array
   *   An '#ajax' definition.
   */
  protected function refreshFormAjaxDefinition(): array {
    return [
      'callback' => '::refreshFormAjax',
      'wrapper' => $this->formWrapperId(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $entity = $this->entity;
    parent::validateForm($form, $form_state);

    $trigger = $form_state->getTriggeringElement();
    // Buttons with limited validation (Edit/Remove/Cancel) submit no usable
    // values: only run their op transition, never the commit paths below.
    $limited = $this->isLimitedSubmission($form_state);
    $pluginShapes = $this->getPluginShapes();
    $shape = $this->shape;

    // Where an edit pane was open while this request was built. Captured
    // before any transition so Update/Save know which subform to commit.
    $openShape = $form_state->get('op') === self::OP_EDIT ? $form_state->get('op_shape') : NULL;
    $openGroup = $form_state->get('op_group');
    $openPlugin = $form_state->get('op_plugin');

    $op = $trigger['#op'] ?? NULL;
    if ($op) {
      $opShape = $trigger['#op_shape'] ?? NULL;
      $opGroup = $trigger['#op_group'] ?? NULL;
      $opPlugin = $trigger['#op_plugin'] ?? NULL;
      $opCollection = isset($pluginShapes[$opShape]) ? $pluginShapes[$opShape]->getValueCollection() : NULL;
      switch ($op) {
        case self::OP_EDIT:
          $this->setOpState($form_state, $opShape, $opGroup, $opPlugin, FALSE);
          break;

        case self::OP_ADD:
          // The select's chosen plugin id rides in the input directly — the
          // select itself is the trigger.
          $addKey = $opGroup . '_' . $opShape;
          $input = $form_state->getUserInput();
          $opPlugin = NestedArray::getValue($input, [$addKey, 'add']);
          if ($opPlugin && $opCollection) {
            $opCollection->setStatus($opPlugin, TRUE);
            $this->restorePluginSettings($form_state, $opShape, $opPlugin, $opCollection);
            $this->setOpState($form_state, $opShape, $opGroup, $opPlugin, TRUE);
            // Do not leave the pick sticky in the re-rendered select.
            NestedArray::unsetValue($input, [$addKey, 'add']);
            $form_state->setUserInput($input);
          }
          break;

        case self::OP_REMOVE:
          if ($opCollection && $opPlugin) {
            $opCollection->setStatus($opPlugin, FALSE);
            if ($openShape === $opShape && $openGroup === $opGroup && $openPlugin === $opPlugin) {
              $this->setOpState($form_state, NULL, NULL, NULL, FALSE);
              $openShape = NULL;
            }
            $entity->setPropShapeSettings($shape);
          }
          break;

        case self::OP_CANCEL:
          if ($form_state->get('op_new') && $opCollection && $opPlugin) {
            // A just-added provider reverts on cancel.
            $opCollection->setStatus($opPlugin, FALSE);
            $entity->setPropShapeSettings($shape);
          }
          $this->setOpState($form_state, NULL, NULL, NULL, FALSE);
          $openShape = NULL;
          break;
      }
    }

    if ($limited) {
      return;
    }

    // Full-validation path: apply the prop options, commit whatever subforms
    // are rendered, and stage everything on the unsaved entity.
    $shape->setExpanded(array_values(array_filter($form_state->getValue(['expanded'], []))));
    $shape->setActive(!empty($form_state->getValue(['active'])));
    $shape->setEditable(!empty($form_state->getValue(['editable'])));
    $shape->setRequired(!empty($form_state->getValue(['required'])));

    if ($form_state->getErrors()) {
      return;
    }

    // Commit the open edit pane (Update, or Save while editing).
    if ($openShape !== NULL && isset($pluginShapes[$openShape])) {
      $key = $openGroup . '_' . $openShape;
      $editForm = $this->findSectionElement($form, $key);
      if ($editForm && isset($editForm['edit']['settings'])) {
        $collection = $pluginShapes[$openShape]->getValueCollection();
        $instance = $collection->getInstances()[$openPlugin] ?? NULL;
        if ($instance) {
          $subformState = SubformState::createForSubform($editForm['edit']['settings'], $form, $form_state);
          $instance->validateConfigurationForm($editForm['edit']['settings'], $subformState);
          if (!$form_state->getErrors()) {
            $original = $this->originalPluginSettings($form_state, $openShape, $openPlugin);
            $settings = $instance->massageFormValue($subformState->getValues(), $editForm['edit']['settings'], $subformState) ?: $original ?? [];
            $collection->setStatus($openPlugin, TRUE);
            $instance->setConfiguration($settings);
            $form_state->set(['staged_plugin_settings', $openShape, $openPlugin], $settings);
            if ($op === self::OP_UPDATE) {
              $this->setOpState($form_state, NULL, NULL, NULL, FALSE);
            }
          }
        }
      }
    }

    if ($form_state->getErrors()) {
      return;
    }

    // Commit inline (single-plugin group) subforms and apply drag order.
    foreach ($pluginShapes as $pluginShape) {
      $shapeId = $pluginShape->id();
      $collection = $pluginShape->getValueCollection();
      $weightsByGroup = [];
      foreach ($collection->getInstances() as $pluginId => $instance) {
        $groupId = $instance->getGroup();
        $key = $groupId . '_' . $shapeId;
        $inline = $this->findSectionElement($form, $key);
        if ($inline && isset($inline['inline'][$pluginId])) {
          $value = $form_state->getValue([$key, $pluginId], []);
          $collection->setStatus($pluginId, !empty($value['status']));
          if (!empty($value['status']) && isset($inline['inline'][$pluginId]['settings'])) {
            $subformState = SubformState::createForSubform($inline['inline'][$pluginId]['settings'], $form, $form_state);
            $instance->validateConfigurationForm($inline['inline'][$pluginId]['settings'], $subformState);
            if (!$form_state->getErrors()) {
              $original = $this->originalPluginSettings($form_state, $shapeId, $pluginId);
              $settings = $instance->massageFormValue($subformState->getValues(), $inline['inline'][$pluginId]['settings'], $subformState) ?: $original ?? [];
              $instance->setConfiguration($settings);
              $form_state->set(['staged_plugin_settings', $shapeId, $pluginId], $settings);
            }
          }
          elseif (!empty($value['status'])) {
            // Just toggled on: no settings subform was rendered yet, so the
            // last known settings come back instead of serializing empties.
            $this->restorePluginSettings($form_state, $shapeId, $pluginId, $collection);
          }
        }
        $submittedWeight = $form_state->getValue([$key, 'list', $pluginId, 'weight']);
        if ($submittedWeight !== NULL) {
          $weightsByGroup[$groupId][$pluginId] = (int) $submittedWeight;
        }
      }
      // Apply the drag-and-drop order. Each group lists only its own rows, so
      // collect the submitted weights per group, order each group, then
      // reorder the collection so setPropShapeSettings() serializes the order.
      $orderedInstanceIds = [];
      foreach ($weightsByGroup as $weights) {
        asort($weights);
        $orderedInstanceIds = array_merge($orderedInstanceIds, array_keys($weights));
      }
      if ($orderedInstanceIds) {
        $collection->setInstanceOrder($orderedInstanceIds);
      }
    }

    if (!$form_state->getErrors()) {
      $entity->setPropShapeSettings($shape);
    }
  }

  /**
   * Restores a re-enabled instance's settings from the last known copy.
   *
   * Serialization drops a deactivated instance's settings from the staged
   * entity, so a plugin toggled back on (or re-added from the Add select)
   * would come back blank over what the site builder had configured. Only
   * restores when the staged entity carries nothing for the instance — a
   * rendered subform's own commit always wins.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $shapeId
   *   The plugin-bearing shape id.
   * @param string $pluginId
   *   The plugin id.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginCollection $collection
   *   The shape's value collection.
   */
  protected function restorePluginSettings(FormStateInterface $form_state, string $shapeId, string $pluginId, ComponentShapePluginCollection $collection): void {
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $entity = $this->entity;
    $props = $entity->getSetting('props', []);
    $staged = $props[$this->shape->getName()]['plugins'][$shapeId][$pluginId]['settings'] ?? NULL;
    if ($staged !== NULL) {
      return;
    }
    $restored = $this->originalPluginSettings($form_state, $shapeId, $pluginId);
    if ($restored !== NULL) {
      $instance = $collection->getInstances()[$pluginId] ?? NULL;
      $instance?->setConfiguration($restored);
    }
  }

  /**
   * The last staged (or originally saved) settings for a plugin instance.
   *
   * The massage fallback: a massage that returns nothing keeps what was there
   * rather than wiping the stored settings.
   */
  protected function originalPluginSettings(FormStateInterface $form_state, string $shapeId, string $pluginId): ?array {
    $staged = $form_state->get(['staged_plugin_settings', $shapeId, $pluginId]);
    if ($staged !== NULL) {
      return $staged;
    }
    $original = $form_state->get('original_prop') ?? [];
    return $original['plugins'][$shapeId][$pluginId]['settings'] ?? NULL;
  }

  /**
   * Finds a section container by key, whether tabbed per shape or flat.
   *
   * Expanded props nest sections inside per-shape tabs ("shape_<id>"); a
   * single un-expanded prop keeps them at the top level.
   *
   * @param array $form
   *   The complete form.
   * @param string $key
   *   The section key ("{group}_{shapeId}").
   *
   * @return array|null
   *   The section container, or NULL when not rendered.
   */
  protected function findSectionElement(array $form, string $key): ?array {
    if (isset($form[$key])) {
      return $form[$key];
    }
    foreach ($form as $elementKey => $element) {
      if (is_array($element) && str_starts_with((string) $elementKey, 'shape_') && isset($element[$key])) {
        return $element[$key];
      }
    }
    return NULL;
  }

  /**
   * Records which section/instance the form is editing.
   */
  protected function setOpState(FormStateInterface $form_state, ?string $shapeId, ?string $groupId, ?string $pluginId, bool $new): void {
    $form_state->set('op', $shapeId === NULL ? NULL : self::OP_EDIT);
    $form_state->set('op_shape', $shapeId);
    $form_state->set('op_group', $groupId);
    $form_state->set('op_plugin', $pluginId);
    $form_state->set('op_new', $new);
  }

  /**
   * Ajax callback.
   */
  public static function refreshFormAjax(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#submit' => ['::submitForm', '::save'],
    ];
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $entity = $this->entity;
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Updated prop %label.', ['%label' => $entity->label()]));
    $form_state->setRedirectUrl($entity->toUrl());
    return $result;
  }

}
