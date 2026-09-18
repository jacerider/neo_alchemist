<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;
use Drupal\neo_alchemist\Shape\ComponentShapeStylePluginInterface;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageIframeCommand;
use Drupal\neo_alchemist\Ajax\ComponentAjaxFormHelperTrait;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\ComponentPropValueHarvester;
use Drupal\neo_alchemist\EditorState\DraftConflictException;
use Drupal\neo_alchemist\EditorState\EditorScratchStore;
use Drupal\neo_alchemist\Value\ComponentValuePanelBuilder;
use Drupal\neo_icon\IconTrait;
use Drupal\neo_tooltip\Tooltip;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class InstanceComponentForm extends ContentEntityForm {

  use ComponentAjaxFormHelperTrait;
  use DraftConflictMessageTrait;
  use IconTrait;

  /**
   * How many state chips the header shows before collapsing to "+N more".
   *
   * The panel is 30rem wide; a component with six style props would otherwise
   * push the tab strip off the bottom of the header.
   */
  private const CHIP_LIMIT = 4;

  /**
   * The per-user scratch store holding the live form buffer.
   *
   * Must be protected and non-promoted, like every service held by a form
   * object here: DependencySerializationTrait swaps services for their ids
   * from FormBase's scope, where a private property declared on this class
   * would be invisible and would be serialized whole into the form cache.
   *
   * @var \Drupal\neo_alchemist\EditorState\EditorScratchStore
   */
  protected $scratchStore;

  /**
   * Component.
   *
   * @var \Drupal\neo_alchemist\ComponentInstanceInterface
   */
  protected $instance;

  /**
   * Parent UUID.
   *
   * @var string|null
   */
  protected $parent;

  /**
   * Before.
   *
   * @var string|null
   */
  protected $before;

  /**
   * After.
   *
   * @var string|null
   */
  protected $after;

  /**
   * The value panel builder.
   *
   * Must be protected and non-promoted, like every service held by a form
   * object here: DependencySerializationTrait swaps services for their ids
   * from FormBase's scope, where a private property declared on this class
   * would be invisible and would be serialized whole into the form cache.
   *
   * @var \Drupal\neo_alchemist\Value\ComponentValuePanelBuilder
   */
  protected $valuePanelBuilder;

  /**
   * The prop value harvester.
   *
   * @var \Drupal\neo_alchemist\ComponentPropValueHarvester
   */
  protected $propValueHarvester;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('neo_alchemist.editor_scratch_store'),
      $container->get('neo_alchemist.value_panel_builder'),
      $container->get('neo_alchemist.prop_value_harvester'),
    );
  }

  /**
   * PatternEditForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\neo_alchemist\EditorState\EditorScratchStore $scratch_store
   *   The per-user scratch store holding the live form buffer.
   * @param \Drupal\neo_alchemist\Value\ComponentValuePanelBuilder $value_panel_builder
   *   The value panel builder.
   * @param \Drupal\neo_alchemist\ComponentPropValueHarvester $prop_value_harvester
   *   The prop value harvester.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time, EditorScratchStore $scratch_store, ComponentValuePanelBuilder $value_panel_builder, ComponentPropValueHarvester $prop_value_harvester) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->scratchStore = $scratch_store;
    $this->valuePanelBuilder = $value_panel_builder;
    $this->propValueHarvester = $prop_value_harvester;
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseFormId() {
    $base_form_id = 'neo_component_' . $this->entity->getEntityTypeId() . '_form';
    if ($base_form_id == $this->getFormId()) {
      $base_form_id = NULL;
    }
    return $base_form_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    $form_id = 'neo_component_' . $this->entity->getEntityTypeId();
    if ($this->entity->getEntityType()->hasKey('bundle')) {
      $form_id .= '_' . $this->entity->bundle();
    }
    return $form_id . '_form';
  }

  /**
   * Initialize the form state and the entity before the first form build.
   */
  protected function init(FormStateInterface $form_state) {
    parent::init($form_state);
    $this->instance = $form_state->get('neo_component_instance');
    $this->parent = $form_state->get('parent');
    $this->before = $form_state->get('before');
    $this->after = $form_state->get('after');
    $form_state->set('neo_component_form', TRUE);

    // This form is only shown when previewing or managing a component.
    $this->instance->setPreview(TRUE);

    $form_state->set('neo_component_manage_id', ComponentManageHelper::getId($this->instance->getFieldItem()));
    $form_state->set('original_values', $this->instance->getValues());
    // Opening the form clears any stale live buffer for this instance; the
    // scratch store invalidates the preview's cache tag as part of the delete.
    $this->scratchStore->delete($this->instance->getFieldItem(), $this->instance->uuid());
    $form_state->set('neo_component_uuid', $this->instance->uuid());
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $form['#attributes']['class'][] = 'neo-alchemist--component-form';
    $form['#attached']['library'][] = 'neo_alchemist/component.form';

    // Claimed in an #after_build because neo_back sets `#theme` from a THEME
    // form alter, which runs after every module alter — there is no alter this
    // module could implement that would still be holding the hook by the time
    // the form renders. #after_build runs later than both.
    $form['#after_build'][] = [static::class, 'claimFormTheme'];

    // Pinned by the layout rather than by `position: sticky`: the header and
    // footer are bands outside the scrolling region now, so there is nothing
    // to stick to. See neo-alchemist-component-form.html.twig.
    $form['footer'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['neo-alchemist--form-footer', 'bg-base-0 z-10'],
      ],
    ];

    if ($form['values']['#access'] ?: $form['filters']['#access'] ?? FALSE) {
      $form['footer']['#attributes']['class'][] = 'mb-0 py-3 border-t';
    }
    else {
      $form['footer']['#attributes']['class'][] = '!mt-0';
    }

    $form['footer']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#neo_size' => 'xs',
      '#default_value' => $this->instance->isPublished(),
    ];

    $form['footer']['refresh'] = $this->valuePanelBuilder->buildRefresh();

    $form['actions']['#weight'] = 1000;
    $form['actions']['#attributes']['class'][] = 'mt-0';
    $form['footer']['actions'] = $form['actions'];
    unset($form['actions']);

    // This is a content entity form, so field_group attaches the entity's form
    // display groups to it, even though the form display is never rendered
    // here. Without opting out, field_group pulls any element whose key
    // matches a grouped field name into that group - 'description' being the
    // common collision - and renders a stray tab around it.
    foreach (Element::children($form) as $key) {
      $form[$key]['#field_group_ignore'] = TRUE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form['#parents'] = [];
    $form['#id'] = ComponentValuePanelBuilder::FORM_ID;
    $form['#attributes']['class'][] = ComponentValuePanelBuilder::FORM_ID;
    $form['#neo_style'] = 'default';
    $form['#neo_size'] = 'sm';

    $form['#process'][] = '::processForm';
    $this->valuePanelBuilder->attachClient($form);

    $form['uuid'] = [
      '#type' => 'hidden',
      '#default_value' => $this->instance->uuid(),
    ];

    // The shared draft's version at the moment this form opened, round-tripped
    // so a save against a draft a colleague has since moved past is refused —
    // optimistic conflict detection, caught at edit time.
    $form['draft_version'] = $this->draftVersionField($this->instance->getFieldItem());

    // Assigned in this order because top-level render order is array order and
    // the Sources accordion belongs between the two panel elements.
    // Styles do not collapse here: they have a tab of their own, so an
    // accordion would only hide the values the header chips advertise.
    $panel = $this->valuePanelBuilder->build(
      $this->instance,
      $form,
      $form_state,
      describeStyles: FALSE,
      collapsibleStyles: FALSE,
    );
    // Size the two holders, not the props inside them. neoSize() is registered
    // on container/fieldset/details/accordion but *not* on `form`, so the
    // `#neo_size` this form sets sizes the <form> element and stops there. A
    // holder with no size of its own defaults to 'md', which is what left the
    // content prop cards at 16px/bold beside 12px style cards — and, since
    // 'md' is the one size emitting no `--spacing-form-item`, gave their state
    // chips a 1rem gap rather than 0.5rem.
    $form['styles'] = $panel['styles'];
    $form['styles']['#neo_size'] = 'xs';

    // "Sources" rather than "Context": a content builder has no reason to know
    // what a context is, and the section holds any number of typed filters.
    $form['filters'] = [
      '#type' => 'container',
      '#access' => FALSE,
    ];

    $form['values'] = $panel['values'];
    $form['values']['#neo_size'] = 'xs';

    foreach ($this->instance->getFilters() as $uuid => $filter) {
      if (!$filter->isEditable()) {
        continue;
      }
      $id = Html::getId('filter-' . $uuid);
      $form['filters']['#access'] = TRUE;

      $allowDefault = $filter->allowDefault();
      $hasOverrideValue = $filter->hasOverrideValue();

      // A fieldset, not a details: the Sources tab shows one card per filter
      // and there is nothing to gain from collapsing them.
      $subform = [
        '#type' => 'fieldset',
        '#title' => $filter->label(),
        '#group' => 'filters',
        '#tree' => TRUE,
        '#required' => $filter->isRequired(),
        // The container holding these does not propagate #neo_size the way the
        // accordion did, so each card states its own.
        '#neo_size' => 'xs',
        '#attributes' => [
          'id' => $id,
        ],
      ];
      if ($summary = $filter->valueSummary()) {
        $subform['#title'] .= '<div class="inline-block badge bg-primary text-primary-content leading-tight ml-1.5">' . $summary . '</div>';
      }
      $subform['value'] = [
        '#type' => 'container',
        '#parents' => ['filters', $uuid, 'value'],
      ];
      $subform_state = SubformState::createForSubform($subform['value'], $form, $form_state);
      $subform['value'] = $filter->buildForm($subform['value'], $subform_state);

      if ($allowDefault) {
        if (!$hasOverrideValue && $form_state->getValue([
          'filters',
          $uuid,
          '_default',
        ], $hasOverrideValue === FALSE)) {
          $subform['value']['#prefix'] = '<div class="hidden">';
          $subform['value']['#suffix'] = '</div>';
        }
        $subform['_default'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Default'),
          '#description' => $this->t('Use the default value of @label', ['@label' => $filter->label()]),
          '#parents' => ['filters', $uuid, '_default'],
          '#default_value' => !$hasOverrideValue,
          '#access' => $allowDefault,
          '#neo_size' => 'xs',
          '#ajax' => [
            'callback' => [get_class($this), 'ajaxFilter'],
            'wrapper' => $id,
          ],
        ];
      }
      $form['filters'][$filter->uuid()] = $subform;
    }

    // The three panes the tab strip switches between.
    //
    // Marked with #prefix/#suffix rather than nested inside a container: a real
    // wrapper would change each element's #parents, and the style props reach
    // the Styles accordion through a #group key derived from exactly that path.
    // Both holders are plain containers, which render no title of their own —
    // the tab already names each section.
    $this->markFormPane($form['values'], 'content');
    $this->markFormPane($form['styles'], 'style');
    $this->markFormPane($form['filters'], 'sources');

    // Which tab is open is client state, but the header is server-rendered
    // and the refresh below replaces it wholesale — so it has to round-trip.
    // Without it every refresh would hand back a strip that says "Content"
    // while the pane on screen is still Sources.
    $form['active_tab'] = [
      '#type' => 'hidden',
      '#default_value' => '',
      '#attributes' => ['data-neo-alchemist-active-tab' => 'true'],
    ];

    $form['header'] = $this->buildHeader((string) $form_state->getValue('active_tab', ''));

    return $form;
  }

  /**
   * Takes the form's `#theme` back from the admin theme.
   *
   * The admin theme themes every content entity form with `entity_edit_form`,
   * which renders `header` inside the two-column row. The editor needs it
   * outside, as a band above the scrolling fields — see
   * neo-alchemist-component-form.html.twig.
   *
   * This runs as an #after_build rather than an alter because neo_back's is a
   * THEME alter: themes alter last, so no module alter could still be holding
   * the hook. Opting out of that alter instead (it skips itself when
   * `#neo_entity_form` is already set) would also skip neo_back_form_meta(),
   * which this form does want.
   *
   * @param array $form
   *   The built form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  public static function claimFormTheme(array $form, FormStateInterface $form_state): array {
    $form['#theme'] = 'neo_alchemist_component_form';
    return $form;
  }

  /**
   * Tags a top-level form section as a tab pane.
   *
   * @param array $element
   *   The section, modified by reference.
   * @param string $tab
   *   The tab key this section belongs to.
   */
  private function markFormPane(array &$element, string $tab): void {
    $element['#prefix'] = '<div class="neo-alchemist--form-pane" data-neo-alchemist-pane="' . Html::escape($tab) . '">';
    $element['#suffix'] = '</div>';
  }

  /**
   * Builds the pinned header: what is being edited, and its current state.
   *
   * A band outside the scrolling region, above it, mirroring the footer below
   * — so the styles and sources a builder has chosen stay readable however far
   * down the fields they are. That is the whole reason this header exists: the
   * form runs well past those sections otherwise.
   *
   * It used to pin itself with `sticky top-0` from inside the scroller, which
   * worked but made `top: 0` mean "below the header" for everything else that
   * wanted to pin. Being outside it is what lets an array's legend stick to the
   * real top without measuring this one.
   *
   * @param string $activeTab
   *   The tab to mark selected. See buildTabStrip().
   */
  private function buildHeader(string $activeTab = ''): array {
    $state = $this->collectState();

    $header = [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => [
        'class' => [
          'neo-alchemist--form-header',
          'z-20', 'bg-default',
          // Negative margins cancel the scroll pane's px-4 and the form's pt-4
          // so the band runs edge to edge and sits flush with the toolbar.
          '-mx-4', '-mt-4', 'px-4', 'pt-3', 'border-b',
        ],
      ],
    ];

    $header['identity'] = $this->buildIdentity();

    if ($chips = $this->rankChips($state)) {
      $header['chips'] = $this->buildChips($chips);
    }

    $header['tabs'] = $this->buildTabStrip($state['counts'], $state['attention'], $activeTab);

    return $header;
  }

  /**
   * The component being edited, and the page it sits on.
   *
   * Deliberately no machine name, entity id or view mode: a content builder
   * cannot name those, and they were the reason the old panel said nothing
   * useful about where you were.
   */
  private function buildIdentity(): array {
    $label = (string) ($this->instance->label() ?? $this->t('Component'));

    // `my-0` because a nested container is tagged `form--item`, which carries
    // `my-form-item`; the header sets its own rhythm. No `gap-x` either: the
    // help badge brings its own `margin-inline-start`, so a gap would stack on
    // top of it and leave the badge floating away from the name.
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['flex', 'flex-wrap', 'items-baseline', 'my-0'],
      ],
    ];

    $build['name'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => ['class' => ['font-bold', 'leading-tight']],
      '#value' => $label,
    ];

    // The component's description hangs off the component's name, which is the
    // rule every field in the panel below already follows — see
    // neo-tooltip-help.html.twig. It used to sit in a standing box above the
    // panes, where it cost 64px of a 30rem panel on every tab for a sentence
    // read once. Deliberately not on the toolbar title: that names the task
    // ("Add component"), not the thing being described.
    if ($description = $this->instance->getDescription()) {
      $build['help'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['form-label-help'],
          'aria-label' => $this->t('More information about @label', [
            '@label' => $label,
          ]),
        ],
        '#value' => Markup::create('<span aria-hidden="true">?</span>'),
      ];
      // No setDescribedElsewhere(): the sentence lives nowhere else on screen
      // now, so tippy's own `aria-describedby` is what announces it.
      (new Tooltip($description, ['placement' => 'bottom-start']))
        ->applyTo($build['help']);
    }

    $entity = $this->instance->getFieldItem()->getEntity();
    if ($entity && $entity->label()) {
      $build['page'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['text-xs', 'text-base-0-content/60', 'ml-2']],
        '#value' => $this->t('on @page', ['@page' => $entity->label()]),
      ];
    }

    return $build;
  }

  /**
   * Renders the ranked chips, collapsing the overflow into a single "+N more".
   */
  private function buildChips(array $chips): array {
    $shown = array_slice($chips, 0, self::CHIP_LIMIT);
    $hidden = count($chips) - count($shown);

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'neo-alchemist--form-chips',
          'flex', 'flex-wrap', 'gap-1',
          // A recessed band of its own rather than more of the header's white:
          // this row is the state readout, and it reads as one object when the
          // surface changes under it. The negative margin cancels the scroll
          // pane's padding so the band runs edge to edge.
          '-mx-4', 'px-4', 'py-2', 'mt-3',
          // Drupal's container template tags these as `form--item`, which
          // carries `my-form-item` (1rem top and bottom). The spacing here is
          // deliberate, so the inherited margin is cleared.
          'mb-0',
          'bg-base-50', 'border-y',
        ],
      ],
    ];
    foreach ($shown as $delta => $chip) {
      $build[$delta] = $this->buildChip($chip);
    }
    if ($hidden > 0) {
      $build['more'] = $this->buildChip([
        'title' => NULL,
        'value' => (string) $this->t('+@count more', ['@count' => $hidden]),
        'tab' => 'style',
        'target' => NULL,
        'prop' => NULL,
        'attention' => FALSE,
      ]);
    }
    return $build;
  }

  /**
   * One chip: a label, its current value, and where clicking it goes.
   */
  private function buildChip(array $chip): array {
    $classes = [
      'inline-flex', 'items-center', 'gap-1', 'max-w-full', 'cursor-pointer',
      'rounded-2xl', 'border', 'px-2', 'py-px', 'text-2xs', 'leading-normal',
      'transition',
    ];
    if ($chip['attention']) {
      $classes = array_merge($classes, ['bg-warning-50', 'border-warning-200', 'text-warning-900']);
    }
    else {
      $classes = array_merge($classes, ['bg-base-50', 'hover:border-primary']);
    }

    $markup = '';
    if (!empty($chip['title'])) {
      $markup .= '<span class="uppercase tracking-tight opacity-60">' . Html::escape($chip['title']) . '</span>';
    }
    $markup .= '<span class="font-semibold truncate">' . Html::escape($chip['value']) . '</span>';

    $attributes = [
      'type' => 'button',
      'class' => $classes,
      'data-neo-alchemist-chip' => $chip['tab'],
    ];
    if (!empty($chip['target'])) {
      $attributes['data-neo-alchemist-chip-target'] = $chip['target'];
    }
    if (!empty($chip['prop'])) {
      $attributes['data-neo-alchemist-chip-prop'] = $chip['prop'];
    }

    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#attributes' => $attributes,
      '#value' => Markup::create($markup),
    ];
  }

  /**
   * The Content / Style / Sources strip.
   *
   * An underline strip rather than the segmented pill group the local tasks
   * use: those read as buttons competing with Save, and this sits directly
   * above the fields it switches. The active bar itself is a pseudo-element in
   * component-form.css, keyed on aria-selected.
   *
   * A tab is omitted when the component has nothing in that section — a
   * component with no filters gets no Sources tab at all.
   *
   * @param array $counts
   *   How many fields each tab holds, keyed by tab.
   * @param bool $attention
   *   TRUE when a required source is still empty.
   * @param string $activeTab
   *   The tab the client last opened, round-tripped through the hidden
   *   'active_tab' field. Empty on a first build, and ignored when it names a
   *   tab this component does not have.
   */
  private function buildTabStrip(array $counts, bool $attention, string $activeTab = ''): array {
    $labels = [
      'content' => $this->t('Content'),
      'style' => $this->t('Style'),
      'sources' => $this->t('Sources'),
    ];

    $strip = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'neo-alchemist--form-tabs',
          // `my-0` clears the `my-form-item` that `form--item` brings in: the
          // tabs sit directly under the chip band, which already separates
          // them from the identity row.
          'flex', 'items-end', 'gap-1', 'my-0', '-mb-px', 'border-b',
        ],
        'role' => 'tablist',
      ],
    ];

    // The tab the client reports, but only if it still exists: a component
    // whose only source was removed would otherwise come back with no tab
    // marked at all.
    $available = array_keys(array_filter(array_intersect_key($counts, $labels)));
    $selected = in_array($activeTab, $available, TRUE)
      ? $activeTab
      : (string) (reset($available) ?: '');

    foreach ($labels as $key => $label) {
      if (empty($counts[$key])) {
        continue;
      }
      $active = $key === $selected;
      $badge = ($key === 'sources' && $attention)
        ? 'badge bg-warning text-warning-content'
        : 'badge bg-base-100 text-base-100-content/70';

      $strip[$key] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'role' => 'tab',
          'aria-selected' => $active ? 'true' : 'false',
          'data-neo-alchemist-tab' => $key,
          'class' => [
            'relative', 'inline-flex', 'items-center', 'gap-1.5', 'cursor-pointer',
            'px-3', 'py-2', 'text-xs', 'font-semibold', 'transition',
            'border-0', 'bg-transparent',
          ],
        ],
        '#value' => Markup::create(
          '<span>' . $label . '</span>'
          . '<span class="' . $badge . '">' . (int) $counts[$key] . '</span>'
        ),
      ];
    }

    return $strip;
  }

  /**
   * Reads the current state of every style prop and every editable source.
   *
   * @return array
   *   Keys: 'styles' and 'sources' (chip candidates, already ordered), plus the
   *   three tab counts and whether Sources needs attention.
   */
  private function collectState(): array {
    $styles = [];
    $content = 0;
    $styleCount = 0;

    foreach ($this->instance->getPropShapes() as $shape) {
      if (!$shape->access('update')) {
        continue;
      }
      if (!$shape instanceof ComponentShapeStylePluginInterface) {
        $content++;
        continue;
      }
      // Counted before the label is resolved: a style prop the builder has not
      // set yet still belongs in the Style tab, it just earns no chip.
      $styleCount++;
      $key = $this->styleValueKey($shape->getValue());
      $label = $key === NULL ? NULL : ($shape->getFieldOptions()[$key] ?? NULL);
      if ($label === NULL) {
        continue;
      }
      $styles[] = [
        // The scheme is the most visually consequential prop, so it leads.
        'lead' => $shape->getRef() === 'scheme',
        'title' => (string) $shape->getTitle(),
        'value' => (string) $label,
        'tab' => 'style',
        'target' => NULL,
        // The shape id is what every shape form carries as data-neo-prop, so
        // the chip can hand it straight to focusProp() and reuse the whole
        // reveal-tab / open-groups / scroll / focus / flash path.
        'prop' => $shape->id(),
        'attention' => FALSE,
      ];
    }
    usort($styles, fn(array $a, array $b) => ($b['lead'] <=> $a['lead']));

    $sources = [];
    foreach ($this->instance->getFilters() as $uuid => $filter) {
      if (!$filter->isEditable()) {
        continue;
      }
      $summary = $filter->valueSummary();
      $attention = $filter->isRequired() && $filter->isEmpty();
      $sources[] = [
        'lead' => FALSE,
        'title' => (string) $filter->label(),
        'value' => $attention ? (string) $this->t('Not set') : (string) ($summary ?? $this->t('None chosen')),
        'tab' => 'sources',
        // Filters are not prop shapes, so they have no data-neo-prop to hand
        // focusProp(); they are addressed by the id set on the subform below.
        'target' => Html::getId('filter-' . $uuid),
        'prop' => NULL,
        'attention' => $attention,
      ];
    }

    return [
      'styles' => $styles,
      'sources' => $sources,
      'counts' => [
        'content' => $content,
        'style' => $styleCount,
        'sources' => count($sources),
      ],
      'attention' => (bool) array_filter($sources, fn(array $s) => $s['attention']),
    ];
  }

  /**
   * Flattens a style value down to the option key it selects.
   *
   * Most style shapes store a plain key, but the scheme shape stores an entity
   * reference array (`['target_id' => 'default']`) — so a naive is_scalar()
   * check silently drops the one style prop that matters most.
   */
  private function styleValueKey(mixed $value): ?string {
    if (is_array($value)) {
      $value = $value['target_id'] ?? reset($value);
    }
    return (is_scalar($value) && $value !== '') ? (string) $value : NULL;
  }

  /**
   * Ranks the chips, so a narrow header shows the ones that matter.
   *
   * Order: the colour scheme, then anything required and still unset (it is why
   * a save will fail), then the remaining sources, then the other style props.
   * Whatever does not fit collapses into a single "+N more".
   */
  private function rankChips(array $state): array {
    $attention = array_values(array_filter($state['sources'], fn(array $s) => $s['attention']));
    $rest = array_values(array_filter($state['sources'], fn(array $s) => !$s['attention']));
    $lead = array_values(array_filter($state['styles'], fn(array $s) => $s['lead']));
    $styles = array_values(array_filter($state['styles'], fn(array $s) => !$s['lead']));

    return array_merge($lead, $attention, $rest, $styles);
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    // No entity building is needed.
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = [
      'status' => (int) !empty($form_state->getValue('status')),
    ];
    $original_values = $form_state->get('original_values') ?? [];
    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#op'] ?? NULL) === 'refresh') {
      // We do not validate the form when we are just refreshing it.
      $form_state->clearErrors();
    }
    // Update shapes. Left unset rather than set empty when nothing was
    // harvested, which is what the loop this replaced did.
    if ($props = $this->propValueHarvester->harvest($this->instance, $form, $form_state, $original_values)) {
      $values['props'] = $props;
    }
    // Update filters.
    foreach ($this->instance->getFilters() as $uuid => $filter) {
      if (isset($form['filters'][$uuid])) {
        $value = NULL;
        if (!$form_state->getValue(['filters', $uuid, '_default']) && isset($form['filters'][$uuid]['value'])) {
          $subform_state = SubformState::createForSubform($form['filters'][$uuid]['value'], $form, $form_state);
          $filter->validateForm($form['filters'][$uuid]['value'], $subform_state);
          $value = $filter->massageFormValue($subform_state->getValues(), $form['filters'][$uuid]['value'], $subform_state);
        }
        $values['filters'][$uuid]['value'] = $value;
      }
    }
    $this->instance->setValues($values);

    // Refuse a save against a shared draft a colleague moved past while this
    // form was open. Only the Save submit writes the shared draft, so only it
    // is checked: the Refresh submit just buffers this editor's own scratch
    // values, and a filter-default toggle is an AJAX rebuild, not a write.
    // Surfaced here, in the validation phase, because a form error cannot be
    // set once validation has finished.
    if (($trigger['#type'] ?? NULL) === 'submit' && ($trigger['#op'] ?? NULL) !== 'refresh') {
      $fieldItem = $this->instance->getFieldItem();
      if ($conflict = $fieldItem->draftConflict((int) $form_state->getValue('draft_version'))) {
        $this->surfaceDraftConflict($conflict, $fieldItem, $form, $form_state);
      }
    }

    return $this->entity;
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
    $actions['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->instance->toUrl(),
      '#attributes' => [
        'data-neo-modal-close' => '1',
      ],
    ];
    if ($this->instance->isNew()) {
      // A new component was picked from the library, so cancel returns there
      // rather than to the manage page — with the placement intact, so picking
      // a different component lands in the same spot. The query is set on the
      // Url object because neither scope's toUrl() forwards options.
      $url = $this->instance->getFieldItem()->toUrl('library');
      if ($query = array_filter(['parent' => $this->parent, 'before' => $this->before, 'after' => $this->after])) {
        $url->setOption('query', $query);
      }
      $actions['cancel']['#url'] = $url;
      if ($this->isAjax()) {
        // In the modal flow the library and add screens replace each other in
        // one dialog: a plain href would navigate the whole page and
        // data-neo-modal-close would just close the modal. Mirror the
        // library's own component links so cancel swaps the library back in.
        // $actions is the local array built at the top of this method; the
        // sniff does not follow it into this branch.
        // phpcs:ignore DrupalPractice.CodeAnalysis.VariableAnalysis.UndefinedUnsetVariable
        unset($actions['cancel']['#attributes']['data-neo-modal-close']);
        $actions['cancel']['#attributes']['class'][] = 'use-ajax';
        $actions['cancel']['#attributes']['data-dialog-type'] = 'modal';
        $actions['cancel']['#attributes']['data-dialog-options'] = Json::encode([
          'width' => '100%',
          'height' => '100%',
          'neo' => [
            'displaceTop' => '0px',
            'displaceBottom' => '0px',
            'contentPadding' => '0px',
          ],
        ]);
        $actions['cancel']['#attached']['library'][] = 'core/drupal.dialog.ajax';
      }
    }
    $actions['submit']['#attributes']['class'][] = 'btn btn-primary btn-xs';
    $actions['cancel']['#attributes']['class'][] = 'btn btn-xs';
    if ($this->isAjax()) {
      $actions['submit']['#ajax']['callback'] = '::ajaxSubmit';
    }
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $this->instance->setRebuilding(FALSE);
    $form_state->setRedirectUrl($this->instance->toUrl());

    // Update shapes.
    $values = $this->instance->getValues();
    foreach ($this->instance->getPropShapes() as $propName => $shape) {
      $values['props'][$propName] = $shape->massageFinalValues($values['props'][$propName] ?? []);
    }
    $this->instance->setValues($values);

    $fieldItem = $this->instance->getFieldItem();
    $fieldDefinition = $fieldItem->getFieldDefinition();
    $isNew = $this->instance->isNew();

    // Carry the loaded version into the write so the store — the authority on
    // the draft's version — refuses a stale save even in the narrow gap
    // between validateForm() (which surfaces the common case as a form error)
    // and here. A refusal that slips through that gap is caught below as a
    // message, not a silent overwrite of a colleague's work.
    $fieldItem->carryDraftVersion((int) $form_state->getValue('draft_version'));

    // If we have requested a position change, we make it here.
    $position = $this->after ? 'after' : ($this->before ? 'before' : NULL);
    if ($position) {
      $this->instance->getFieldItem()->moveComponent($this->instance->uuid(), $this->after ?: $this->before, $position, $this->instance->getParentUuid(), $this->instance->getParentSlot());
    }

    try {
      $result = $this->instance->save();
    }
    catch (DraftConflictException $conflict) {
      $this->messenger()->addError($this->draftConflictMessage($conflict, $fieldItem));
      $form_state->setRebuild();
      return SAVED_UPDATED;
    }

    $this->messenger()->addStatus($this->t('@op component %name successfully on %label: %field_label.', [
      '@op' => $isNew ? 'Created' : 'Updated',
      '%name' => $this->instance->label(),
      '%label' => $fieldItem->belongsToFieldConfig() ? $this->entityTypeManager->getDefinition($fieldDefinition->getTargetEntityTypeId())->getLabel() : $this->entity->label(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]));

    return $result;
  }

  /**
   * Submit refresh.
   */
  public function submitRefresh(array $form, FormStateInterface $form_state) {
    $form_state->setRebuild();
    // Buffer the in-progress values so this editor's preview iframe reflects
    // them; the scratch store invalidates the preview's cache tag on write.
    $this->scratchStore->set($this->instance->getFieldItem(), $form_state->getValue('uuid'), $this->instance->getValues());
  }

  /**
   * Ajax refresh.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state) {
    $form['#old_build_id'] = $form['#build_id'];
    $response = new AjaxResponse();
    $response->addCommand(new InstanceComponentManageIframeCommand('#' . ComponentManageHelper::getId($this->instance) . ' iframe'));
    // The header is a readout of the values being edited, so it has to travel
    // with them. validateForm() has already written this round's values onto
    // the instance, and setValues() drops the prop-shape and filter memos, so
    // the header in the rebuilt form reads the new state rather than the one
    // the page was opened with.
    //
    // Only the header: the panes hold the controls this refresh was typed
    // into, and replacing those would take the focused field out from under
    // the cursor. Nothing inside the header carries an event listener of its
    // own — both the tabs and the chips are handled by delegation on the form
    // — so swapping it costs no rebinding.
    if (isset($form['header'])) {
      $selector = '#' . ComponentValuePanelBuilder::FORM_ID . ' .neo-alchemist--form-header';
      $response->addCommand(new ReplaceCommand($selector, $form['header']));
    }
    return $response;
  }

  /**
   * Ajax callback.
   */
  public static function ajaxFilter(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
  }

}
