<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\Ajax\ComponentAjaxHelperTrait;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentMoveController extends ControllerBase {

  use AjaxHelperTrait;
  use ComponentAjaxHelperTrait;

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, ComponentTreeItem $neo_field, ComponentInterface $neo_component, string $direction) {
    $parent = $request->query->get('parent');
    [$parentUuid, $shapeId] = explode('--', (string) ($parent ?? '--'));
    if ($neo_field->isHybridScope()) {
      // Hybrid mode: only entity-owned components within an
      // entity-customizable region may be moved.
      if (!$neo_field->isCustomTarget($parentUuid ?: NULL, $shapeId ?: NULL) || $neo_field->isInheritedInstance($neo_component->uuid())) {
        throw new AccessDeniedHttpException();
      }
    }
    // Every placed instance, not just the labelable ones: an instance whose
    // component config no longer loads has no label, and ordering built from
    // the labelled options used to skip it — which turned a move into a
    // deletion. The reorder is non-destructive now, but the ordering source
    // should be complete regardless, so a move past a broken component moves
    // past it rather than over it.
    // A move to a place rather than a step (Layers drag-and-drop): `target` is
    // the container it lands in (`ownerUuid--shapeId`, empty for the page),
    // `before` or `after` the sibling it lands next to. `parent` stays where it
    // comes from, which the hybrid check above has already allowed.
    if ($direction === 'to') {
      // Empty for the page itself; the padding gives that a shape too.
      [$targetUuid, $targetShape] = explode('--', $request->query->get('target', '') . '--');
      if ($neo_field->isHybridScope() && !$neo_field->isCustomTarget($targetUuid ?: NULL, $targetShape ?: NULL)) {
        throw new AccessDeniedHttpException();
      }
      $sibling = $request->query->get('before') ?: $request->query->get('after');
      $position = $request->query->get('before') ? 'before' : 'after';
      if ($neo_field->relocateComponent($neo_component->uuid(), $targetUuid ?: NULL, $targetShape ?: NULL, $sibling ? (string) $sibling : NULL, $position)) {
        $neo_field->saveComponents();
        $this->moveMessage($neo_field, $neo_component);
      }
      return $this->moveResponse($neo_field, $neo_component);
    }

    $uuids = $neo_field->getPlacedUuids($parentUuid, $shapeId);
    $swap_with = NULL;
    switch ($direction) {
      case 'up':
        $index = array_search($neo_component->uuid(), $uuids, TRUE);
        if ($index > 0) {
          $swap_with = $uuids[$index - 1];
        }
        break;

      case 'down':
        $index = array_search($neo_component->uuid(), $uuids, TRUE);
        if ($index < count($uuids) - 1) {
          $swap_with = $uuids[$index + 1];
        }
        break;
    }
    if ($swap_with) {
      $index1 = array_search($neo_component->uuid(), $uuids, TRUE);
      $index2 = array_search($swap_with, $uuids, TRUE);
      [$uuids[$index1], $uuids[$index2]] = [$uuids[$index2], $uuids[$index1]];
      // Perform the swap.
      $neo_field->reorderComponents($uuids, $parentUuid, $shapeId);
      $neo_field->saveComponents();
      $this->moveMessage($neo_field, $neo_component);
    }

    return $this->moveResponse($neo_field, $neo_component);
  }

  /**
   * Reports a move that happened.
   */
  private function moveMessage(ComponentTreeItem $neo_field, ComponentInterface $neo_component): void {
    $fieldDefinition = $neo_field->getFieldDefinition();
    $this->messenger()->addStatus($this->t('@op component %name successfully on %label: %field_label.', [
      '@op' => 'Moved',
      '%name' => $neo_component->label(),
      '%label' => $neo_field->belongsToFieldConfig() ? $this->entityTypeManager()->getDefinition($fieldDefinition->getTargetEntityTypeId())->getLabel() : $neo_field->getEntity()->label(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]));
  }

  /**
   * The editor's refresh after a move, or a redirect outside it.
   */
  private function moveResponse(ComponentTreeItem $neo_field, ComponentInterface $neo_component) {
    if ($this->isAjax()) {
      return $this->getComponentReponse(
        ComponentManageHelper::getId($neo_field),
        $neo_component->uuid(),
        $neo_field
      );
    }
    $url = $neo_component->toUrl();
    return $this->redirect($url->getRouteName(), $url->getRouteParameters());
  }

}
