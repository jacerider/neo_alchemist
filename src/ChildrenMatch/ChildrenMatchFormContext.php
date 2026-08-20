<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ChildrenMatch;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

/**
 * The form-time context a children-match handler is configured against.
 *
 * A handler's addOptions() and buildForm() need the shape being configured, the
 * scope its fields are read from, the form state and — for the handlers that
 * recurse (Expand, Reference) — the mapper and the source. Bundling them keeps
 * the handler signatures short and stable as the set of collaborators grows.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchHandlerInterface
 */
final class ChildrenMatchFormContext {

  /**
   * Constructs a ChildrenMatchFormContext.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper $mapper
   *   The mapper, for handlers that recurse into a nested mapping form.
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface $source
   *   The producer whose form is being built.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The child shape being configured.
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope $scope
   *   The entity type and bundle the child's fields are read from.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   * @param array $ajax
   *   The ajax settings a child control refreshes with.
   */
  public function __construct(
    public readonly ChildrenMatchMapper $mapper,
    public readonly ChildrenMatchSourceInterface $source,
    public readonly ComponentShapePluginInterface $shape,
    public readonly ChildrenMatchScope $scope,
    public readonly FormStateInterface $formState,
    public readonly array $ajax,
  ) {}

}
