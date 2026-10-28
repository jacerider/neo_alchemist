<?php

namespace Drupal\neo_alchemist\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Defines an AJAX command saying a component's Apply was not saved.
 *
 * A refused Apply (a field error, or a draft conflict) only re-renders the
 * form with its message, which tells the inspector nothing: its prompt stayed
 * up and the action waiting on the Apply stayed armed. Sent after the form, so
 * the message is in place when the inspector brings it into view.
 *
 * @see components-parent.ts, inspectorApplyRefused()
 *
 * @ingroup ajax
 */
class InstanceComponentManageApplyRefusedCommand implements CommandInterface {

  /**
   * Constructs the command.
   *
   * @param string $uuid
   *   The component whose Apply was refused.
   */
  public function __construct(
    protected string $uuid,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'neoAlchemistApplyRefused',
      'uuid' => $this->uuid,
    ];
  }

}
