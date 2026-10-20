<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Hook;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Element;
use Drupal\media_library\MediaLibraryState;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Saves a media item edited from an Alchemist media prop over AJAX.
 *
 * The media library widget's Edit button (core issue #2985168, and the
 * neo_media_library_edit module until core ships it) opens the media item's
 * edit form in a modal, and both wire that form's Save only when the request's
 * media library state names core's own field widget opener. Alchemist props
 * swap that opener for neo_alchemist.media_library.opener, so their Edit
 * button would open a form whose Save navigates inside the modal. This wires it
 * for Alchemist's opener the same way. It keys on the request alone, so it
 * works whichever module rendered the button.
 *
 * The success path is core's: press the widget's hidden update button, which
 * MediaLibraryPropOpener keeps targetable through its `field_widget_id`
 * parameter, and close the modal.
 *
 * @see \Drupal\neo_alchemist\MediaLibraryPropOpener
 * @see neo_alchemist_field_widget_single_element_media_library_widget_form_alter()
 * @see https://www.drupal.org/project/drupal/issues/2985168
 */
class MediaEditFormHooks {

  use DependencySerializationTrait;

  /**
   * The media library opener Alchemist props use.
   */
  protected const OPENER_ID = 'neo_alchemist.media_library.opener';

  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly MessengerInterface $messenger,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for media_form.
   */
  #[Hook('form_media_form_alter')]
  public function formMediaFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface || $form_object->getOperation() !== 'edit') {
      return;
    }
    if ($this->stateFromRequest()?->getOpenerId() !== static::OPENER_ID) {
      return;
    }
    $wrapper_format = $this->requestStack->getCurrentRequest()->query->get(MainContentViewSubscriber::WRAPPER_FORMAT);
    if ($wrapper_format !== 'drupal_modal' && $wrapper_format !== 'drupal_ajax') {
      return;
    }

    foreach (Element::children($form['actions']) as $key) {
      if ($key === 'submit') {
        $form['actions'][$key]['#ajax'] = [
          'callback' => [$this, 'ajaxCallback'],
        ];
        // Keep the form that opened the modal from refocusing the Save button.
        $form['actions'][$key]['#attributes']['data-disable-refocus'] = 'true';
        // The data-drupal-selector must stay the same across AJAX requests,
        // which \Drupal\Core\Form\FormBuilder only allows when #id does too.
        // This form only ever occurs once on a page.
        // @todo Remove once https://www.drupal.org/node/2897377 is fixed.
        $form['#id'] = Html::getId($form_state->getBuildInfo()['form_id']);
      }
      else {
        $form['actions'][$key]['#access'] = FALSE;
      }
    }
  }

  /**
   * AJAX callback for the media edit form opened from an Alchemist prop.
   *
   * @internal
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    if ($form_state->hasAnyErrors()) {
      $form['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => -1000,
      ];
      $response->addCommand(new ReplaceCommand('[data-drupal-selector="' . $form['#attributes']['data-drupal-selector'] . '"]', $form));
      return $response;
    }

    $field_widget_id = $this->stateFromRequest()?->getOpenerParameters()['field_widget_id'] ?? NULL;
    if (!$field_widget_id) {
      return $response;
    }

    $response->addCommand(new InvokeCommand("[data-media-library-widget-update=\"$field_widget_id\"]", 'trigger', ['mousedown']));
    $response->addCommand(new CloseModalDialogCommand());
    $this->messenger->deleteByType(MessengerInterface::TYPE_STATUS);

    return $response;
  }

  /**
   * The media library state carried by the current request, if valid.
   *
   * NULL as well when media_library is not installed, since its classes are
   * then not loadable.
   */
  protected function stateFromRequest(): ?MediaLibraryState {
    if (!$this->moduleHandler->moduleExists('media_library')) {
      return NULL;
    }
    try {
      return MediaLibraryState::fromRequest($this->requestStack->getCurrentRequest());
    }
    catch (BadRequestHttpException | \InvalidArgumentException) {
      return NULL;
    }
  }

}
