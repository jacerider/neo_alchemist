<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Opens the add or edit form for one configured plugin on a component.
 *
 * Replaces four controllers — add and edit, once for access and once for
 * filters — that differed only in which factory they called and which key
 * they handed the wrapper to the form under. The route supplies the kind;
 * `uuid` is present on the edit routes and absent on the add routes, which is
 * the whole difference between the two.
 */
final class ComponentConfiguredPluginController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly ConfiguredPluginKindRepository $kinds,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist.configured_plugin_kinds'),
    );
  }

  /**
   * Builds the response.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $neo_component
   *   The component the plugin is configured on.
   * @param string $neo_kind
   *   The configured-plugin kind, from the route.
   * @param string|null $uuid
   *   The stored uuid on the edit routes; absent when adding.
   *
   * @return array
   *   The form render array.
   */
  public function __invoke(ComponentInterface $neo_component, string $neo_kind, ?string $uuid = NULL): array {
    $kind = $this->kinds->get($neo_kind);
    $wrapper = $uuid === NULL ? $kind->create($neo_component) : $kind->load($neo_component, $uuid);
    if (!$wrapper) {
      throw new AccessDeniedHttpException();
    }
    return $this->entityFormBuilder()->getForm($neo_component, $kind->id(), [
      $kind->id() => $wrapper,
    ]);
  }

}
