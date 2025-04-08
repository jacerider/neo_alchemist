<?php

namespace Drupal\neo_alchemist\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Defines an AJAX command to create and open a modal.
 *
 * @ingroup ajax
 */
class InstanceComponentManageForcusCommand implements CommandInterface {

  /**
   * A component UUID.
   *
   * @var string|null
   */
  protected $uuid;

  /**
   * Constructs an InsertCommand object.
   *
   * @param string $uuid
   *   The uuid.
   */
  public function __construct(string $uuid) {
    $this->uuid = $uuid;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'neoAlchemistComponentFocus',
      'uuid' => $this->uuid,
    ];
  }

}
