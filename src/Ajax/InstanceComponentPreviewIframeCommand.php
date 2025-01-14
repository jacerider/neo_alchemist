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
  protected $elementId;

  /**
   * Constructs an InsertCommand object.
   *
   * @param string $element_id
   *   The iframe ID.
   */
  public function __construct($element_id = 'neo-alchemist--iframe') {
    $this->elementId = $element_id;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'neoAlchemistInstanceComponentPreviewIframe',
      'selector' => $this->elementId,
    ];
  }

}
