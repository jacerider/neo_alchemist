<?php

namespace Drupal\neo_alchemist\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Defines an AJAX command to create and open a modal.
 *
 * @ingroup ajax
 */
class InstanceComponentManageIframeCommand implements CommandInterface {

  /**
   * A iframe ID.
   *
   * @var string|null
   */
  protected $selector;

  /**
   * Query arguments the refreshed render is fetched with.
   *
   * @var array
   */
  protected $query;

  /**
   * Constructs an InsertCommand object.
   *
   * @param string $selector
   *   The selector.
   * @param array $query
   *   Query arguments to set on the frames' own URL for this one render, such
   *   as the component the inspector has open (`inspect`).
   */
  public function __construct($selector = '#neo-alchemist--iframe', array $query = []) {
    $this->selector = $selector;
    $this->query = $query;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'neoAlchemistComponentIframeReload',
      'selector' => $this->selector,
      'query' => (object) $this->query,
    ];
  }

}
