<?php

namespace Drupal\neo_alchemist\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Defines an AJAX command to create and open a modal.
 *
 * @ingroup ajax
 */
class InstanceComponentManageFocusCommand implements CommandInterface {

  /**
   * A component UUID.
   *
   * @var string|null
   */
  protected $uuid;

  /**
   * Whether the canvas scrolls to the component as well as selecting it.
   *
   * @var bool
   */
  protected $reveal;

  /**
   * Constructs an InsertCommand object.
   *
   * @param string $uuid
   *   The uuid.
   * @param bool $reveal
   *   (optional) Scroll the canvas to it too: after undo and redo, which can
   *   land anywhere on the page. Defaults to FALSE.
   */
  public function __construct(string $uuid, bool $reveal = FALSE) {
    $this->uuid = $uuid;
    $this->reveal = $reveal;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'neoAlchemistComponentFocus',
      'uuid' => $this->uuid,
      'reveal' => $this->reveal,
    ];
  }

}
