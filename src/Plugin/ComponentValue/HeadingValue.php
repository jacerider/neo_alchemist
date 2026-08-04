<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo\Helpers\Str;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\MatcherField;
use Drupal\neo_alchemist\Plugin\ComponentShape\ObjectShape;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'heading',
  label: new TranslatableMarkup('Heading'),
  description: new TranslatableMarkup('Provides modifications for headings.'),
  group: 'providers',
  allow_on_default: TRUE,
  ref_types: [
    'heading',
  ],
  weight: 900
)]
final class HeadingValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface {

  use ComponentValueTitleResolverTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The sub-props that hold a text value.
   */
  protected const TEXT_KEYS = ['supertitle', 'title', 'subtitle'];

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

  /**
   * Resolved entity field values, keyed by sub-prop name.
   *
   * Both onShapeInit() and provideDefaultValue() need the resolved value, and
   * they run in separate passes over the same entity.
   *
   * @var array<string, string|null>
   */
  protected array $resolvedFieldValues = [];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'hide' => FALSE,
      'supertitle_edit' => TRUE,
      'supertitle_default' => FALSE,
      'supertitle_empty' => FALSE,
      'supertitle_page' => FALSE,
      'supertitle_entity' => FALSE,
      'supertitle_field' => '',
      'supertitle_field_mode' => ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
      'supertitle_value' => NULL,
      'title_edit' => TRUE,
      'title_default' => FALSE,
      'title_empty' => FALSE,
      'title_page' => FALSE,
      'title_entity' => FALSE,
      'title_field' => '',
      'title_field_mode' => ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
      'title_value' => NULL,
      'subtitle_edit' => TRUE,
      'subtitle_default' => FALSE,
      'subtitle_empty' => FALSE,
      'subtitle_page' => FALSE,
      'subtitle_entity' => FALSE,
      'subtitle_field' => '',
      'subtitle_field_mode' => ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
      'subtitle_value' => NULL,
      'size_edit' => TRUE,
      'size_default' => FALSE,
      'size_value' => '',
    ] + $this->processingModeDefaultConfiguration();
  }

  /**
   * The field matcher.
   *
   * Fetched lazily rather than injected: the constructor comes from
   * ComponentValueTitleResolverTrait, which every heading needs, while the
   * matcher is only touched when a sub-prop is actually bound to an entity
   * field.
   *
   * @return \Drupal\neo_alchemist\MatcherField
   *   The matcher.
   */
  protected function getMatcher(): MatcherField {
    if (!isset($this->matcherField)) {
      $this->matcherField = \Drupal::service('neo_alchemist.matcher_field');
    }
    return $this->matcherField;
  }

  /**
   * Determine whether a sub-prop is sourced from something other than a value.
   *
   * @param string $key
   *   The sub-prop name.
   *
   * @return bool
   *   TRUE when the page title, the entity label or an entity field supplies
   *   the value, so the plain "Value" textfield no longer applies.
   */
  protected function hasSource(string $key): bool {
    return !empty($this->configuration["{$key}_page"])
      || !empty($this->configuration["{$key}_entity"])
      || !empty($this->configuration["{$key}_field"]);
  }

  /**
   * {@inheritdoc}
   */
  public function onUpdate(): void {
    if (isset($this->configuration['h1_edit'])) {
      $this->configuration['size_edit'] = $this->configuration['h1_edit'];
      unset($this->configuration['h1_edit']);
    }
    if (isset($this->configuration['h1_default'])) {
      $this->configuration['h1_default'] = $this->configuration['h1_default'];
      unset($this->configuration['h1_default']);
    }
    if (isset($this->configuration['h1_value'])) {
      $this->configuration['size_value'] = !empty($this->configuration['h1_value']) ? 'xl' : '';
      unset($this->configuration['h1_value']);
    }
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    $form['hide'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide'),
      '#default_value' => $this->configuration['hide'],
      '#description' => $this->t('If checked, the component will be hidden by default.'),
    ];

    $shape = $this->shape;
    if (!$shape instanceof ObjectShape) {
      return $form;
    }
    $childShapes = $shape->getChildShapes();
    foreach ([
      'supertitle' => $this->t('Supertitle'),
      'title' => $this->t('Title'),
      'subtitle' => $this->t('Subtitle'),
      'size' => $this->t('Size'),
    ] as $key => $label) {
      $form["{$key}"] = [
        '#type' => 'fieldset',
        '#title' => $label,
      ];
      if (isset($this->configuration["{$key}_edit"])) {
        $allowOverride = !empty($this->configuration["{$key}_page"]) || !empty($this->configuration["{$key}_entity"]);
        $form["{$key}"]["edit"] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Allow @op', ['@op' => $allowOverride ? 'override' : 'edit']),
          '#neo_size' => 'xs',
          '#default_value' => $this->shape->isEditable() ? $this->configuration["{$key}_edit"] : FALSE,
          '#disabled' => !$this->shape->isEditable(),
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }
      if (isset($this->configuration["{$key}_default"])) {
        $form["{$key}"]["default"] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Default'),
          '#neo_size' => 'xs',
          '#description' => $this->t('If checked, the default option will be enabled on component creation.'),
          '#default_value' => $this->configuration["{$key}_default"],
          '#disabled' => !$this->shape->isEditable() ||empty($this->configuration["{$key}_edit"]) || !empty($this->configuration["{$key}_empty"]),
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }
      if (isset($this->configuration["{$key}_empty"])) {
        $form["{$key}"]["empty"] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Hide'),
          '#neo_size' => 'xs',
          '#description' => $this->t('If checked, the hide option will be enabled on component creation.'),
          '#default_value' => $this->configuration["{$key}_empty"],
          '#disabled' => !empty($this->configuration["{$key}_default"]),
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }
      if (isset($this->configuration["{$key}_page"])) {
        $form["{$key}"]["page"] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Use %title as value', ['%title' => $this->t('page title')]),
          '#neo_size' => 'xs',
          '#default_value' => $this->configuration["{$key}_page"],
          '#access' => empty($this->configuration["{$key}_entity"]) && empty($this->configuration["{$key}_field"]),
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }
      if (isset($this->configuration["{$key}_entity"]) && $this->getShape()->getComponent()->getTargetEntityTypeId()) {
        $form["{$key}"]["entity"] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Use %title as value', ['%title' => $this->t('entity label')]),
          '#neo_size' => 'xs',
          '#default_value' => $this->configuration["{$key}_entity"],
          '#access' => empty($this->configuration["{$key}_page"]) && empty($this->configuration["{$key}_field"]),
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }
      if (isset($this->configuration["{$key}_field"]) && isset($childShapes[$key])) {
        $form["{$key}"] = $this->buildFieldSourceForm($form["{$key}"], $key, $childShapes[$key], $wrapperId);
      }
      if (isset($this->configuration["{$key}_value"]) || is_null($this->configuration["{$key}_value"])) {
        if ($key === 'size') {
          $form["{$key}"]["value"] = [
            '#type' => 'select',
            '#title' => $this->t('Value'),
            '#default_value' => $this->configuration["{$key}_value"] ?? $childShapes[$key]->getDefaultValue(),
            '#description' => $this->t('The default value for the @label.', ['@label' => $label]),
            '#options' => ['' => $this->t('- Default -')] + $this->getSizeOptions(),
          ];
        }
        else {
          $form["{$key}"]["value"] = [
            '#type' => 'textfield',
            '#title' => $this->t('Value'),
            '#default_value' => $this->configuration["{$key}_value"] ?? $childShapes[$key]->getDefaultValue(),
            '#description' => $this->t('The default value for the @label.', ['@label' => $label]),
          ];
        }
      }
      $form["{$key}"]['value']['#access'] = !$this->hasSource($key);
    }

    $form = $this->buildProcessingModeForm($form, $form_state);

    return $form;
  }

  /**
   * Build the entity field binding for a single sub-prop.
   *
   * One searchable control rather than the group-then-field pair of selects
   * this used to render. The matcher walks referenced entities two levels
   * deep, so a sub-prop is offered well over a thousand fields — too many to
   * put in a `<select>`, and splitting them by reference path meant you had to
   * know which path reached a field before you could search for it by name.
   * The neo_field_select element renders no options and searches the whole set
   * server-side instead.
   *
   * @param array $form
   *   The sub-prop fieldset.
   * @param string $key
   *   The sub-prop name.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $childShape
   *   The sub-prop's own shape. Matching against the child rather than the
   *   heading object is what limits the list to fields that can supply a
   *   string.
   * @param string $wrapperId
   *   The ajax wrapper id of the plugin's settings form.
   *
   * @return array
   *   The fieldset with the field/mode elements added.
   */
  protected function buildFieldSourceForm(array $form, string $key, ComponentShapePluginInterface $childShape, string $wrapperId): array {
    $component = $this->getShape()->getComponent();
    if (!$component->id() || !$childShape->getTargetEntityType()) {
      return $form;
    }
    // The page title and the entity label are alternative sources, so the
    // binding stays in the form (to keep its value) but out of sight.
    $access = empty($this->configuration["{$key}_page"]) && empty($this->configuration["{$key}_entity"]);
    $field = $this->configuration["{$key}_field"];

    $form['field'] = [
      '#type' => 'neo_field_select',
      '#title' => $this->t('Use %title as value', ['%title' => $this->t('entity field')]),
      '#neo_size' => 'xs',
      '#component' => $component->id(),
      '#prop' => $this->getShape()->getName(),
      '#shape' => $childShape->id(),
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $field,
      '#access' => $access,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($field) {
      $form['field_mode'] = [
        '#type' => 'select',
        '#title' => $this->t('When the field is empty'),
        '#neo_size' => 'xs',
        '#description' => $this->t('The plugin-wide "Processing" setting cannot answer this: it claims the heading as a whole, which is never empty while any other sub-property holds a value.'),
        '#options' => [
          ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND => $this->t('Fall back to the default value'),
          ComponentValueProcessingModeInterface::MODE_BLOCK => $this->t('Render nothing'),
        ],
        '#default_value' => $this->configuration["{$key}_field_mode"],
        '#access' => $access,
      ];
    }

    return $form;
  }

  /**
   * Get size options.
   */
  protected function getSizeOptions(): array {
    $shape = $this->shape;
    if (!$shape instanceof ObjectShape) {
      return [];
    }
    /** @var \Drupal\neo_alchemist\Plugin\ComponentShape\StyleShape $sizeShape */
    $sizeShape = $shape->getChildShapes()['size'];
    return $sizeShape->getFieldOptions();
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -2));
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $finalValues = [];
    foreach ($values as $key => $v) {
      if (!is_array($v)) {
        $finalValues[$key] = $v;
        continue;
      }
      foreach ($v as $ii => $vv) {
        $finalValues["{$key}_{$ii}"] = $vv;
      }
    }
    $form_state->setValues($finalValues);
  }

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$element, FormStateInterface $form_state) {
    if (!empty($element['title']['_options']['default'])) {
      // Ajax on the default title checkbox will refresh the whole heading
      // value provider form.
      $element['title']['_options']['default']['#ajax']['wrapper'] = $element['#id'];
      $element['title']['_options']['default']['#ajax_level'] = -3;
      if ($this->shape->getNestedOptionDefault('title')) {
        // Hide the anchor when no title editing is allowed.
        $element['anchor']['#access'] = FALSE;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    parent::onShapeInit();
    $shape = $this->shape;

    if (!$shape instanceof ComponentShapeChildrenPluginInterface) {
      return;
    }

    if ($this->configuration['hide']) {
      $shape->getOptionEmpty()->setLockedValue(TRUE, 'Heading hidden by Heading value provider.');
    }

    $dynamicAnchor = FALSE;
    foreach (static::TEXT_KEYS as $field) {
      // A sub-prop that draws its value from somewhere — the page title, the
      // entity label, or a bound entity field — starts on its default value,
      // because that default is exactly what the source produced. Overriding
      // it with typed text is what the editor opts into.
      //
      // Sourcing must NOT also start the sub-prop hidden. "Hide" is the site
      // builder's own switch ({$field}_empty), and conflating the two made
      // every source pointless the moment it was chosen: the empty option wins
      // over the default one in ComponentShapePluginBase::getValue(), so
      // ticking "Use page title as value" on the title resolved the page title
      // and then threw it away, rendering an empty <h2> in the preview and on
      // every instance created afterwards.
      //
      // Nor can "starts on its default" be gated on the parent heading's own
      // default option: that option is off for an ordinary authored heading,
      // which is precisely the case a sourced sub-prop has to work in.
      //
      // Both conditions used to be written as `??` chains. That cannot express
      // "any of these": every key is seeded by defaultConfiguration(), so `??`
      // stopped at the first one and the entity-label source never reached
      // either branch. The empty branch additionally read `{$field}_empty`
      // first, which existed for supertitle and subtitle but NOT for title —
      // so it silently meant "hide when the Hide box is ticked" for two
      // sub-props and "hide when sourced from the page title" for the third.
      // The missing title_empty key is now seeded alongside its siblings.
      $hasSource = $this->hasSource($field);
      if ($hasSource || $this->configuration["{$field}_default"]) {
        $shape->setDefaultNestedOptionDefault($field);
      }
      if (!empty($this->configuration["{$field}_empty"])) {
        $shape->setDefaultNestedOptionEmpty($field);
      }
      // No `??` fallbacks here: setConfiguration() merges every saved value
      // over defaultConfiguration(), which seeds `_edit` and `_empty` for all
      // three text sub-props, so a coalesce operand would be dead code. It read
      // as a guard against a missing key, which is exactly how `title_empty`
      // being absent from the defaults went unnoticed for as long as it did.
      if (!$this->configuration["{$field}_edit"]) {
        if (!empty($this->configuration["{$field}_empty"])) {
          $shape->setNestedOptionEmpty($field);
        }
        $shape->setNestedOptionDefault($field);
        $shape->setNestedOptionAccess($field);
        if ($field === 'title') {
          $dynamicAnchor = TRUE;
        }
      }
      // "Render nothing" for a bound field this entity leaves empty. Returning
      // NULL from provideDefaultValue() is not enough on its own: an empty
      // child value is skipped rather than applied, so the sub-prop falls back
      // to the component's own schema example. Forcing the sub-prop's empty
      // state is what actually renders nothing.
      //
      // This has to be a per-sub-prop setting. The plugin-wide "Processing"
      // mode claims the heading object as a whole, and that object is not
      // empty while size — or any other sub-property — still holds a value, so
      // "Always stop (block if empty)" never fires for a single empty title.
      if ($this->isBlockedEmptyField($field)) {
        $shape->setNestedOptionEmpty($field);
      }
    }
    if ($this->configuration['size_default']) {
      $shape->setNestedOptionDefault('size');
    }
    if (!$this->configuration['size_edit']) {
      $shape->setNestedOptionDefault('size');
      $shape->setNestedOptionAccess('size');
    }
    if ($this->shape->getNestedOptionDefault('title')) {
      $dynamicAnchor = TRUE;
    }
    if ($dynamicAnchor) {
      $shape->setNestedOptionDefault('anchor');
      $shape->setNestedOptionAccess('anchor');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    foreach (static::TEXT_KEYS as $field) {
      if (!empty($this->configuration["{$field}_page"])) {
        $value[$field] = $this->getPageTitle();
      }
      elseif (!empty($this->configuration["{$field}_entity"])) {
        $value[$field] = $this->getShape()->getEntity()->label();
      }
      elseif (!empty($this->configuration["{$field}_field"])) {
        $value[$field] = $this->getEntityFieldValue($field);
      }
      else {
        $value[$field] = $this->configuration["{$field}_value"] ?? NULL;
      }
    }
    if ($this->configuration['size_value']) {
      $value['size'] = $this->configuration['size_value'];
    }
    $value['anchor'] = $value['title'] ? Str::machine($value['title'], '-') : NULL;
    return $value;
  }

  /**
   * Resolve a sub-prop's bound entity field down to a single heading string.
   *
   * @param string $key
   *   The sub-prop name.
   *
   * @return string|null
   *   The value, or NULL when the entity holds nothing for that field. NULL
   *   leaves the value the pipeline threaded in untouched, so an empty field
   *   does not blank out what an earlier provider supplied — see
   *   ::isBlockedEmptyField() for the opposite choice.
   */
  protected function getEntityFieldValue(string $key): ?string {
    if (array_key_exists($key, $this->resolvedFieldValues)) {
      return $this->resolvedFieldValues[$key];
    }
    $shape = $this->getShape();
    // Published FALSE mirrors the generic Entity Field provider: the status
    // gate belongs to the entity being rendered, not to a heading sourced
    // from it.
    $value = $this->getMatcher()->getEntityValue(
      entity: $shape->getEntity(),
      key: $this->configuration["{$key}_field"],
      published: FALSE,
      cacheableMetadata: $shape->getCacheableMetadata(),
    );
    // A match arrives in whatever shape its source uses — a field item list
    // ([['value' => 'x']]), a single-element dynamic list (['x']) or an
    // already-scalar property. A heading sub-prop holds one string, so take
    // the first scalar the match resolves to.
    while (is_array($value)) {
      if (!$value) {
        $value = NULL;
        break;
      }
      $value = reset($value);
    }
    $this->resolvedFieldValues[$key] = is_scalar($value) && $value !== '' ? (string) $value : NULL;
    return $this->resolvedFieldValues[$key];
  }

  /**
   * Whether a sub-prop is bound to a field that is empty and must render so.
   *
   * @param string $key
   *   The sub-prop name.
   *
   * @return bool
   *   TRUE when the sub-prop takes its value from an entity field, that field
   *   holds nothing for this entity, and the site builder chose "Render
   *   nothing" over falling back to the component's example.
   */
  protected function isBlockedEmptyField(string $key): bool {
    if (empty($this->configuration["{$key}_field"])) {
      return FALSE;
    }
    // The page title and the entity label take precedence as sources, so a
    // stale binding underneath one of them must not blank the sub-prop.
    if (!empty($this->configuration["{$key}_page"]) || !empty($this->configuration["{$key}_entity"])) {
      return FALSE;
    }
    $mode = $this->configuration["{$key}_field_mode"] ?? ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND;
    if ($mode !== ComponentValueProcessingModeInterface::MODE_BLOCK) {
      return FALSE;
    }
    return $this->getEntityFieldValue($key) === NULL;
  }

}
