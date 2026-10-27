<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\Ajax\ComponentAjaxHelperTrait;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentManageHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Removes a component from its tree.
 *
 * The editor deletes at once and offers Undo (`immediate`), sending the
 * session's request-header token as `token`, which is checked here: the
 * route serves the confirm form too, so it cannot require the token of every
 * request. Anything else gets the confirm form.
 */
final class InstanceComponentDeleteController extends ControllerBase {

  use AjaxHelperTrait;
  use ComponentAjaxHelperTrait;

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInterface $neo_component, Request $request) {
    if ($request->query->get('immediate') && $neo_component instanceof ComponentInstanceInterface) {
      $token = (string) $request->query->get('token', '');
      if (!\Drupal::csrfToken()->validate($token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)) {
        throw new AccessDeniedHttpException('The request could not be verified.');
      }
      $fieldItem = $neo_component->getFieldItem();
      // Names the step itself ("Delete @label"), which Undo then offers.
      $neo_component->delete();
      if ($this->isAjax()) {
        return $this->getComponentReponse(ComponentManageHelper::getId($fieldItem), NULL, $neo_component);
      }
      $url = $neo_component->toUrl();
      return $this->redirect($url->getRouteName(), $url->getRouteParameters());
    }
    return $this->entityFormBuilder()->getForm($neo_component->getTargetEntity(), 'alchemist_delete', [
      'neo_component_instance' => $neo_component,
    ]);
  }

}
