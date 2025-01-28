<?php

namespace Drupal\neo_alchemist\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Defines an AJAX command to create and open a modal.
 *
 * @ingroup ajax
 */
class InstanceComponentPreviewIframeCommand implements CommandInterface {

  /**
   * A iframe ID.
   *
   * @var string|null
   */
  protected $selector;

  /**
   * Constructs an InsertCommand object.
   *
   * @param string $selector
   *   The selector.
   */
  public function __construct($selector = '#neo-alchemist--iframe') {
    $this->selector = $selector;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'neoAlchemistInstanceComponentPreviewIframe',
      'selector' => $this->selector,
    ];
  }

}
