<?php

namespace Drupal\neo_alchemist\Ajax;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_modal\Ajax\NeoModalCloseCommand;

/**
 * Provides a helper to update component build pages via ajax.
 *
 * @internal
 */
trait ComponentAjaxHelperTrait {

  /**
   * Gets an AJAX response for component management operations.
   *
   * @param string|null $manageId
   *   The ID of the management container element to target with iframe
   *   commands.
   * @param string|null $draftId
   *   The ID of the draft element to focus on.
   * @param \Drupal\neo_alchemist\ComponentInstanceInterface|\Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|null $instance
   *   The component instance or tree item being managed.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response with UI update commands.
   */
  protected function getComponentReponse(
    ?string $manageId = NULL,
    ?string $draftId = NULL,
    ComponentInstanceInterface|ComponentTreeItem|null $instance = NULL,
  ): AjaxResponse {
    $response = new AjaxResponse();

    // Add iframe command if management ID is provided.
    if ($manageId !== NULL) {
      $response->addCommand(new InstanceComponentManageIframeCommand('#' . $manageId . ' iframe'));
    }

    // Add focus command if draft ID is provided.
    if ($draftId !== NULL) {
      $response->addCommand(new InstanceComponentManageFocusCommand($draftId));
    }

    // Always add modal close command.
    $response->addCommand(new NeoModalCloseCommand());

    // Update dynamic operations if instance is provided.
    if ($instance !== NULL) {
      $item = $instance instanceof ComponentInstanceInterface
        ? $instance->getFieldItem()
        : $instance;

      $selector = '#' . ComponentManageHelper::getId($item) . ' .neo-alchemist-manage--top-start';
      $operations = ComponentManageHelper::buildDynamicOperations($item);
      $response->addCommand(new HtmlCommand($selector, $operations));
    }

    return $response;
  }

}
