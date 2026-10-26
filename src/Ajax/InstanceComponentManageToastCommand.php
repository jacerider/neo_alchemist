<?php

namespace Drupal\neo_alchemist\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Defines an AJAX command that shows a toast in the editor.
 *
 * The editor's own message area, over the canvas, which fades by itself: core's
 * MessageCommand writes into the page's messages, which sit across the header
 * and stay until dismissed.
 *
 * @see Drupal.neoAlchemist.toast() in component-parent.ts
 *
 * @ingroup ajax
 */
class InstanceComponentManageToastCommand implements CommandInterface {

  /**
   * Constructs the command.
   *
   * @param \Drupal\Component\Render\MarkupInterface|string $message
   *   The message, as plain text.
   * @param string $type
   *   The kind of toast: 'status' or 'error'.
   */
  public function __construct(
    protected $message,
    protected string $type = 'status',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'neoAlchemistToast',
      'message' => (string) $this->message,
      'type' => $this->type,
    ];
  }

}
