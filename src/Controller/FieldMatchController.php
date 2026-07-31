<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\FieldMatchLocator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves entity field matches to the neo_field_select element.
 *
 * Two shapes, one per mode of the picker's modal. ::__invoke() answers the
 * search box with a flat ranked list of {value, label, path}; ::browse()
 * answers one Miller column with the fields at a point in the entity tree and
 * the references leading out of it.
 *
 * @see \Drupal\neo_alchemist\FieldMatchLocator
 */
final class FieldMatchController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly FieldMatchLocator $locator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist.field_match_locator'),
    );
  }

  /**
   * Builds the response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, carrying the `q` search string.
   * @param string $component
   *   The neo_component id.
   * @param string $prop
   *   The prop name.
   * @param string $shape
   *   The nested shape id, or "_root".
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The matches.
   */
  public function __invoke(Request $request, string $component, string $prop, string $shape): JsonResponse {
    $shapePlugin = $this->shape($component, $prop, $shape);
    $results = $this->locator->search(
      $shapePlugin,
      (string) $request->query->get('q', ''),
      (bool) $request->query->get('all', FALSE),
      50,
      $request->query->get('entity_type') ?: NULL,
      $request->query->get('bundle') ?: NULL,
    );
    return $this->json($results);
  }

  /**
   * Builds one pane of the browser.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, carrying the `path` to open.
   * @param string $component
   *   The neo_component id.
   * @param string $prop
   *   The prop name.
   * @param string $shape
   *   The nested shape id, or "_root".
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The pane.
   */
  public function browse(Request $request, string $component, string $prop, string $shape): JsonResponse {
    $shapePlugin = $this->shape($component, $prop, $shape);
    $pane = $this->locator->browse(
      $shapePlugin,
      (string) $request->query->get('path', ''),
      (bool) $request->query->get('all', FALSE),
      $request->query->get('entity_type') ?: NULL,
      $request->query->get('bundle') ?: NULL,
    );
    return $this->json($pane);
  }

  /**
   * Resolves the addressed shape or refuses.
   *
   * @param string $component
   *   The neo_component id.
   * @param string $prop
   *   The prop name.
   * @param string $shape
   *   The nested shape id.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The shape.
   */
  private function shape(string $component, string $prop, string $shape): ComponentShapePluginInterface {
    $shapePlugin = $this->locator->resolveShape($component, $prop, $shape);
    if (!$shapePlugin) {
      // Covers both "no such shape" and "no access to that component": the
      // picker must not let a caller distinguish the two by probing ids.
      throw new NotFoundHttpException();
    }
    return $shapePlugin;
  }

  /**
   * Wraps a payload in an uncacheable, per-user JSON response.
   *
   * @param mixed $data
   *   The payload.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  private function json(mixed $data): JsonResponse {
    // Per-user because the shape was resolved behind an access check, and
    // uncacheable at the page level because the query string drives the body.
    $response = new JsonResponse($data);
    $response->setPrivate();
    $response->setMaxAge(0);
    return $response;
  }

}
