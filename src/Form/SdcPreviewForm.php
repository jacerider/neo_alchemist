<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Url;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageIframeCommand;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\ComponentPropValueHarvester;
use Drupal\neo_alchemist\EditorState\SdcPreviewStore;
use Drupal\neo_alchemist\SdcThumbnailWriter;
use Drupal\neo_alchemist\Value\ComponentValuePanelBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Editable value form for the standalone SDC preview workspace.
 *
 * This mirrors the live prop/style editor used when a component is placed on a
 * page (@see InstanceComponentForm) but operates on the transient
 * (unsaved) neo_component entity built for an SDC preview. Instead of saving,
 * changes are written as disposable preview-value overrides on the SDC preview
 * store (SdcPreviewStore::setValues()) and the preview iframe is reloaded so
 * the developer sees them immediately. Nothing is persisted to configuration.
 */
final class SdcPreviewForm extends EntityForm {

  /**
   * The component entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * The SDC thumbnail writer.
   *
   * Must be protected and non-promoted: form objects are serialized into the
   * form cache, and DependencySerializationTrait swaps services for their IDs
   * from FormBase's scope — where a private property declared here would be
   * invisible. The writer would then be serialized whole, dragging the SDC
   * plugin manager's object graph into every cached form.
   *
   * @var \Drupal\neo_alchemist\SdcThumbnailWriter
   */
  protected $thumbnailWriter;

  /**
   * The value panel builder.
   *
   * Protected and non-promoted for the same reason as the writer above.
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
   * The SDC preview workspace store.
   *
   * Protected and non-promoted for the same serialization reason as the writer
   * above.
   *
   * @var \Drupal\neo_alchemist\EditorState\SdcPreviewStore
   */
  protected $sdcPreviewStore;

  public function __construct(SdcThumbnailWriter $thumbnail_writer, ComponentValuePanelBuilder $value_panel_builder, ComponentPropValueHarvester $prop_value_harvester, SdcPreviewStore $sdc_preview_store) {
    $this->thumbnailWriter = $thumbnail_writer;
    $this->valuePanelBuilder = $value_panel_builder;
    $this->propValueHarvester = $prop_value_harvester;
    $this->sdcPreviewStore = $sdc_preview_store;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist.sdc_thumbnail_writer'),
      $container->get('neo_alchemist.value_panel_builder'),
      $container->get('neo_alchemist.prop_value_harvester'),
      $container->get('neo_alchemist.sdc_preview_store'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function init(FormStateInterface $form_state) {
    parent::init($form_state);
    // A prop widget is built over a synthetic field definition, not a real
    // field on the target entity, so the media library has to be opened with
    // Alchemist's own opener. Without this flag the widget keeps core's
    // field-widget opener, which resolves the prop name against the target
    // entity — here a placeholder node — and dies with "Field image is
    // unknown" the moment a selection is inserted.
    // @see neo_alchemist_field_widget_single_element_media_library_widget_form_alter()
    $form_state->set('neo_component_form', TRUE);
    // Capture the value overrides present when the workspace first loaded so
    // that non-iterable shape values can be merged correctly on refresh.
    $form_state->set('original_values', $this->sdcPreviewStore->getValues($this->entity));
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['#parents'] = [];
    // The component.ajax.form behavior keys off this exact id.
    $form['#id'] = ComponentValuePanelBuilder::FORM_ID;
    // Match the saved-component manage form styling so the form is inset from
    // its container rather than flush to the edges.
    $form['#neo_style'] = 'clean';

    $this->valuePanelBuilder->attachClient($form);

    // A developer here is previewing an unsaved component, not configuring a
    // saved one, so the per-prop option controls are hidden; and the props are
    // left in schema order, which is the order of the declaration on screen.
    $panel = $this->valuePanelBuilder->build(
      $this->entity,
      $form,
      $form_state,
      sortStylesByTitle: FALSE,
      describeStyles: FALSE,
      hideOptionControls: TRUE,
    );
    $form['styles'] = $panel['styles'];
    $form['values'] = $panel['values'];

    // Hidden refresh submit, triggered by the component.ajax.form behavior on
    // every (debounced) input change.
    $form['refresh'] = $this->valuePanelBuilder->buildRefresh();

    return $form;
  }

  /**
   * Returns the action form element for the current entity form.
   */
  protected function actionsElement(array $form, FormStateInterface $form_state) {
    $actions = parent::actionsElement($form, $form_state);
    $actions['#attributes']['class'][] = 'bg-base-0 border-t py-4 sticky bottom-0 z-10';
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = [];
    $actions['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset'),
      '#limit_validation_errors' => [],
      '#submit' => ['::submitReset'],
      '#access' => $this->sdcPreviewStore->hasValues($this->entity),
      '#attributes' => [
        'class' => ['btn', 'btn-xs'],
      ],
    ];
    $actions += $this->buildThumbnailCapture();
    return $actions;
  }

  /**
   * Builds the thumbnail capture button, if capturing is available.
   *
   * The rest of the capture pipeline already runs on this page: the preview
   * iframe loads the rasterizer, and component-parent.ts finds this button by
   * its id and drives the framing toolbar. All that is added here is the
   * button and the destination it posts to.
   *
   * Hidden outright when the feature is off, because that is the state of
   * every environment that is not somebody's checkout and a permanently dead
   * button there is pure noise. Rendered but disabled when the feature is on
   * and something environmental is in the way, because at that point the
   * developer has declared intent and a missing button reads as a bug — the
   * tooltip names the directory so the fix is a chmod.
   *
   * @return array
   *   The action elements, empty when capturing is unavailable.
   */
  private function buildThumbnailCapture(): array {
    if (!$this->thumbnailWriter->isEnabled()) {
      return [];
    }
    // The entity is transient and carries a synthetic id; the SDC id is the
    // only thing that identifies the component on disk.
    $componentId = $this->entity->getComponentId();
    $reason = $this->thumbnailWriter->getUnavailableReason($componentId);
    $actions = [];

    // Show what is currently on disk so a capture can be judged without
    // leaving the page. The class is how the capture JS swaps in the new
    // image, which it must do by URL because every capture writes the same
    // filename and the browser would otherwise keep serving the old bytes.
    if ($uri = ComponentManageHelper::sdcThumbnailUri($this->entity->getComponent())) {
      $actions['thumbnail_preview'] = [
        '#theme' => 'image',
        '#uri' => $uri,
        '#alt' => $this->t('Current thumbnail'),
        '#weight' => 9,
        '#attributes' => [
          'class' => ['neo-alchemist--thumbnail-preview', 'border', 'rounded'],
          'style' => 'display: block; max-width: 80px; max-height: 40px',
        ],
      ];
    }

    $element = [
      '#type' => 'button',
      '#value' => $this->thumbnailWriter->getExistingPath($componentId)
        ? $this->t('Re-capture thumbnail')
        : $this->t('Capture thumbnail'),
      // component-parent.ts looks this up with getElementById(), so it must
      // stay exactly this and stay unique across both forms on the page.
      '#id' => 'neo-alchemist-thumbnail-capture-button',
      '#disabled' => (bool) $reason,
      '#tooltip' => $reason ?: $this->t('Writes thumbnail.png into the component directory.'),
      '#weight' => 10,
      '#attributes' => [
        'class' => ['btn', 'btn-xs'],
        // Tippy does not reliably receive pointer events on a disabled
        // element, so the reason gets a native tooltip as well.
        'title' => (string) ($reason ?: ''),
      ],
    ];

    if (!$reason) {
      // The CSRF token is not in this URL yet — RouteProcessorCsrf leaves a
      // placeholder and registers a #lazy_builder in the URL's bubbleable
      // metadata. That metadata only bubbles inside an active render context,
      // and this form is built before ComponentPageRenderer::renderBarePage()
      // opens the root context that would replace the placeholder, so it has
      // to be attached to this element by hand or the token stays a dead hash
      // and every capture 403s.
      $generated = Url::fromRoute('neo_alchemist.sdc_thumbnail_capture', [
        'component' => $componentId,
      ])->toString(TRUE);
      $element['#attributes']['data-capture-url'] = $generated->getGeneratedUrl();
      // Merge rather than apply the GeneratedUrl directly: applyTo() replaces
      // #attached wholesale.
      BubbleableMetadata::createFromRenderArray($element)
        ->merge($generated)
        ->applyTo($element);
    }

    $actions['thumbnail_capture'] = $element;
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    // No entity building is needed; this form never persists to config.
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    // Intentionally empty — values are stored as preview overrides, not config.
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    // We never block a refresh on validation errors — this is a live preview.
    if (($trigger['#op'] ?? NULL) === 'refresh') {
      $form_state->clearErrors();
    }

    $values = [];
    $original_values = $form_state->get('original_values') ?? [];
    // Left unset rather than set empty when nothing was harvested: an empty
    // 'props' key would make the store's hasValues() true and light up the
    // Reset button for a workspace with nothing to reset.
    if ($props = $this->propValueHarvester->harvest($this->entity, $form, $form_state, $original_values)) {
      $values['props'] = $props;
    }

    // Apply the harvest to the entity here, mirroring InstanceComponentForm's
    // $this->instance->setValues(). Every AJAX rebuild of this form has to see
    // the values and per-prop options the user just produced — not only the
    // debounced refresh — because the store's setValues() drops the memoized
    // prop shapes so the next getPropShapes() re-reads them. Deferring it to a
    // submit handler leaves any other rebuild (the media override button, which
    // has to turn the "default" option off to reveal its widget) reconstructing
    // shapes from the previous store entry, where nothing it just did exists.
    //
    // Writing during validation is not a phase violation for this form: it has
    // no commit step, it already wrote on every debounced keystroke, and the
    // overrides are a cache entry with a 1-hour TTL behind a Reset button.
    // ::submitReset() still wins, because it runs after this.
    $this->sdcPreviewStore->setValues($this->entity, $values);
    // Stash for the submit handler.
    $form_state->set('preview_values', $values);
    return $this->entity;
  }

  /**
   * Submit handler for the (debounced) live refresh.
   */
  public function submitRefresh(array $form, FormStateInterface $form_state) {
    // ::validateForm() has already persisted the overrides.
    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the live refresh: reload the preview iframe(s).
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state) {
    $form['#old_build_id'] = $form['#build_id'];
    $response = new AjaxResponse();
    $response->addCommand(new InstanceComponentManageIframeCommand('#' . ComponentManageHelper::getId($this->entity) . ' iframe'));
    return $response;
  }

  /**
   * Submit handler for the reset button: clear all preview overrides.
   */
  public function submitReset(array $form, FormStateInterface $form_state) {
    $this->sdcPreviewStore->resetValues($this->entity);
    $form_state->setRedirectUrl(Url::fromRoute('<current>'));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    // This form never saves the entity.
    return 0;
  }

}
