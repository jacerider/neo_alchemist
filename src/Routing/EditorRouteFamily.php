<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Routing;

use Drupal\neo_alchemist\Controller\EntityComponentController;
use Drupal\neo_alchemist\Controller\FieldComponentController;
use Drupal\neo_alchemist\Controller\InstanceComponentAddController;
use Drupal\neo_alchemist\Controller\InstanceComponentCloneController;
use Drupal\neo_alchemist\Controller\InstanceComponentDeleteController;
use Drupal\neo_alchemist\Controller\InstanceComponentEditController;
use Drupal\neo_alchemist\Controller\InstanceComponentLibraryController;
use Drupal\neo_alchemist\Controller\InstanceComponentManageController;
use Drupal\neo_alchemist\Controller\InstanceComponentMoveController;
use Drupal\neo_alchemist\Controller\InstanceComponentPreviewController;
use Drupal\neo_alchemist\Controller\InstanceComponentPublishController;
use Drupal\neo_alchemist\Controller\InstanceComponentResetController;
use Drupal\neo_alchemist\Controller\InstanceComponentRevertController;
use Drupal\neo_alchemist\Controller\InstanceComponentSortController;
use Drupal\neo_alchemist\Form\PurgeInertDataForm;
use Symfony\Component\Routing\Route;

/**
 * The editor's route family, as one table.
 *
 * "An operation on a component in the editor" — edit, delete, sort, clone, add,
 * move, and the management landing itself — is registered across more than one
 * host scope: an entity that renders a component tree (the entity scope) and
 * the field-config editor under Manage fields (the field-UI scope). The block
 * submodule offers the same family for a null-storage host, and is intended to
 * register through this builder from a subscriber of its own (ticket 02). Every
 * scope needs the SAME family, off a different path prefix, and the route names
 * two URL generators build by interpolating the host entity type
 * (@see \Drupal\neo_alchemist\Entity\ComponentFieldConfig::toUrl(),
 * \Drupal\neo_alchemist\Entity\ComponentField::toUrl()) MUST match what is
 * registered. Hand-writing the family per scope let those two facts drift — the
 * field-UI scope gained a purge route the block scope silently lacked, and no
 * test could see it.
 *
 * This builder owns the op → (path suffix, controller, access requirement,
 * options) mapping once. A scope is one call: pass an entity type id, a path
 * prefix, the base parameters and options, the shared route defaults and the
 * subset of ops the scope offers, and get back the routes to register, keyed by
 * their (interpolated) route names. Adding an op is one row; adding a scope is
 * one call; and every scope's names come from a single place, so two scopes can
 * no longer register mismatched families. The two URL generators above still
 * interpolate their own names today; routing them through routeName() so a
 * generator cannot drift from the routes is later work in this same effort.
 *
 * @see \Drupal\neo_alchemist\EventSubscriber\RouteSubscriber
 */
final class EditorRouteFamily {

  /**
   * The entity scope: an entity whose canonical/edit route renders a tree.
   */
  public const SCOPE_ENTITY = 'entity';

  /**
   * The field-UI scope: the shared layout editor under Manage fields.
   *
   * The block submodule's host also registers through this scope, because its
   * URL generators build the same `entity.{id}.field_ui.alchemist.*` names.
   */
  public const SCOPE_FIELD_UI = 'field_ui';

  /**
   * The management landing op, whose route name is the bare scope prefix.
   */
  private const OP_BASE = 'alchemist';

  /**
   * Upcasting for the tree-field parameter carried by every member op.
   */
  private const PARAM_NEO_FIELD = ['type' => 'neo_alchemist_field'];

  /**
   * Upcasting for the component-instance parameter carried by component ops.
   */
  private const PARAM_NEO_COMPONENT = ['type' => 'neo_alchemist_field_component'];

  /**
   * The ops each host scope declares, in registration order.
   *
   * The two scopes differ, and the differences are declarations rather than
   * accidents:
   *
   * - `publish`, `revert` and `reset` are entity-scope only. They act on a
   *   single entity's stored draft/published pair; a field's shared default
   *   layout has no per-entity draft to publish, revert or reset, so the ops
   *   are meaningless in the field-UI scope.
   * - `purge` is field-UI scope only. Purging stored layout data is a decision
   *   about the field across every entity that uses it, never about one entity
   *   (@see \Drupal\neo_alchemist\Entity\ComponentFieldConfig::toUrl()), so it
   *   has no place in the entity scope.
   *
   * The block scope (neo_alchemist_block) registers through SCOPE_FIELD_UI with
   * its own explicit op subset; whether it offers purge is decided in its own
   * subscriber, not here.
   */
  private const SCOPE_OPS = [
    self::SCOPE_ENTITY => [
      self::OP_BASE,
      'manage',
      'preview',
      'library',
      'publish',
      'revert',
      'reset',
      'sort',
      'add',
      'edit',
      'clone',
      'delete',
      'move',
    ],
    self::SCOPE_FIELD_UI => [
      self::OP_BASE,
      'manage',
      'preview',
      'library',
      'sort',
      'purge',
      'add',
      'edit',
      'clone',
      'delete',
      'move',
    ],
  ];

  /**
   * The ops a host scope declares.
   *
   * @param string $scope
   *   One of the SCOPE_* constants.
   *
   * @return string[]
   *   The op ids, in registration order.
   */
  public function opsForScope(string $scope): array {
    return self::SCOPE_OPS[$scope] ?? throw new \InvalidArgumentException("Unknown host scope: $scope");
  }

  /**
   * The route name an op registers as, for a scope and host entity type.
   *
   * The one place route names are spelled, so a caller that needs a name (the
   * builder, the parity test, and eventually the URL generators) reads it here
   * rather than re-interpolating the pattern.
   *
   * @param string $scope
   *   One of the SCOPE_* constants.
   * @param string $entityTypeId
   *   The host entity type id.
   * @param string $op
   *   An op id (a member op, or OP_BASE for the management landing).
   *
   * @return string
   *   The fully-qualified route name.
   */
  public function routeName(string $scope, string $entityTypeId, string $op): string {
    $prefix = match ($scope) {
      self::SCOPE_ENTITY => "entity.$entityTypeId.alchemist",
      self::SCOPE_FIELD_UI => "entity.$entityTypeId.field_ui.alchemist",
      default => throw new \InvalidArgumentException("Unknown host scope: $scope"),
    };
    return $op === self::OP_BASE ? $prefix : "$prefix.$op";
  }

  /**
   * Builds a scope's editor routes, keyed by route name.
   *
   * @param string $scope
   *   One of the SCOPE_* constants.
   * @param string $entityTypeId
   *   The host entity type id — interpolated into every route name.
   * @param string $pathPrefix
   *   The alchemist base path off which every op hangs its suffix (e.g. the
   *   host's `alchemist` link template, or the field-UI base path plus
   *   /alchemist).
   * @param array $parameters
   *   The base route parameters (the host entity/bundle parameter, with any
   *   upcasting). Member ops add neo_field / neo_component on top.
   * @param array $options
   *   The base route options seed, WITHOUT a `parameters` key (the builder sets
   *   parameters itself). The field-UI scope seeds this from the field-UI base
   *   route so those options carry through; the entity scope passes [].
   * @param array $defaults
   *   The route defaults every op shares (e.g. entity_type_id, neo_draft,
   *   bundle). Op-specific defaults (controller, title) are merged over these.
   * @param string[]|null $ops
   *   The subset of ops to register, in order. NULL registers the scope's full
   *   declared set. A caller passes a narrower subset to withhold ops it does
   *   not offer (e.g. the block scope, or a host with no tree field).
   * @param string|null $bundleEntityType
   *   The host's bundle entity type id, used to build the field-UI base op's
   *   access requirement. NULL falls back to the literal `bundle`.
   *
   * @return \Symfony\Component\Routing\Route[]
   *   The routes to register, keyed by their route names.
   */
  public function build(
    string $scope,
    string $entityTypeId,
    string $pathPrefix,
    array $parameters = [],
    array $options = [],
    array $defaults = [],
    ?array $ops = NULL,
    ?string $bundleEntityType = NULL,
  ): array {
    $ops ??= $this->opsForScope($scope);
    $memberSpecs = self::memberSpecs();
    $routes = [];
    foreach ($ops as $op) {
      $spec = $op === self::OP_BASE
        ? $this->baseSpec($scope, $entityTypeId, $bundleEntityType)
        : ($memberSpecs[$op] ?? throw new \InvalidArgumentException("Unknown editor op: $op"));

      $route = new Route($pathPrefix . $spec['path']);
      $route->setDefaults($spec['defaults'] + $defaults);

      $routeParameters = $parameters;
      foreach ($spec['params'] as $paramName) {
        $routeParameters[$paramName] = $paramName === 'neo_field'
          ? self::PARAM_NEO_FIELD
          : self::PARAM_NEO_COMPONENT;
      }
      $routeOptions = $options;
      $routeOptions['parameters'] = $routeParameters;
      $routeOptions['_admin_route'] = $spec['admin_route'];
      if ($spec['preview']) {
        $routeOptions['_neo_alchemist_preview'] = TRUE;
      }
      $route->setOptions($routeOptions);

      foreach ($spec['requirement'] as $name => $value) {
        $route->setRequirement($name, $value);
      }

      $routes[$this->routeName($scope, $entityTypeId, $op)] = $route;
    }
    return $routes;
  }

  /**
   * The scope-specific spec for the management-landing (base) op.
   *
   * The base op is the one op whose controller and access requirement
   * genuinely differ by scope: the entity scope lands on the entity's own tree
   * editor, the field-UI scope on the field-config layout picker.
   */
  private function baseSpec(string $scope, string $entityTypeId, ?string $bundleEntityType): array {
    $spec = [
      'path' => '',
      'params' => [],
      'admin_route' => TRUE,
      'preview' => FALSE,
    ];
    return match ($scope) {
      self::SCOPE_ENTITY => $spec + [
        'defaults' => [
          '_controller' => EntityComponentController::class,
          '_title_callback' => EntityComponentController::class . '::getTitle',
        ],
        'requirement' => ['_neo_entity_component' => "$entityTypeId.update"],
      ],
      self::SCOPE_FIELD_UI => $spec + [
        'defaults' => [
          '_controller' => FieldComponentController::class,
          '_title_callback' => FieldComponentController::class . '::getTitle',
        ],
        'requirement' => ['_neo_field_component' => 'entity_type_id.' . ($bundleEntityType ?: 'bundle') . '.update'],
      ],
      default => throw new \InvalidArgumentException("Unknown host scope: $scope"),
    };
  }

  /**
   * The member-op table: op → (path, params, defaults, requirement, options).
   *
   * `move` carries a static `title` in every scope: the field-UI scope always
   * did, and InstanceComponentMoveController has no getTitle for the entity
   * scope's `_title_callback` to reach — the callback was latent, never
   * rendered, because move is an action endpoint. One table means one answer,
   * so the working static title wins.
   */
  private static function memberSpecs(): array {
    $controller = static fn (string $class): array => [
      '_controller' => $class,
      '_title_callback' => $class . '::getTitle',
    ];
    $action = static fn (string $class, string $title): array => [
      '_controller' => $class,
      'title' => $title,
    ];
    return [
      'manage' => [
        'path' => '/{neo_field}',
        'params' => ['neo_field'],
        'defaults' => $controller(InstanceComponentManageController::class),
        'requirement' => ['_neo_component_field' => 'neo_field.update'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
      'preview' => [
        'path' => '/{neo_field}/preview',
        'params' => ['neo_field'],
        'defaults' => $controller(InstanceComponentPreviewController::class),
        'requirement' => ['_neo_component_field' => 'neo_field.update'],
        'admin_route' => FALSE,
        'preview' => TRUE,
      ],
      'library' => [
        'path' => '/{neo_field}/library',
        'params' => ['neo_field'],
        'defaults' => $controller(InstanceComponentLibraryController::class),
        'requirement' => ['_neo_component_field' => 'neo_field.create'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
      'publish' => [
        'path' => '/{neo_field}/publish',
        'params' => ['neo_field'],
        'defaults' => $action(InstanceComponentPublishController::class, 'Save'),
        'requirement' => ['_neo_component_field' => 'neo_field.publish'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
      'revert' => [
        'path' => '/{neo_field}/revert',
        'params' => ['neo_field'],
        'defaults' => $action(InstanceComponentRevertController::class, 'Revert'),
        'requirement' => ['_neo_component_field' => 'neo_field.revert'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
      'reset' => [
        'path' => '/{neo_field}/reset',
        'params' => ['neo_field'],
        'defaults' => $action(InstanceComponentResetController::class, 'Reset'),
        'requirement' => ['_neo_component_field' => 'neo_field.reset'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
      'sort' => [
        'path' => '/{neo_field}/sort',
        'params' => ['neo_field'],
        'defaults' => $controller(InstanceComponentSortController::class),
        'requirement' => ['_neo_component_field' => 'neo_field.sort'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
      'purge' => [
        'path' => '/{neo_field}/purge',
        'params' => ['neo_field'],
        'defaults' => [
          '_form' => PurgeInertDataForm::class,
          '_title' => 'Purge stored layout data',
        ],
        'requirement' => ['_neo_component_field' => 'neo_field.purge'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
      'add' => [
        'path' => '/{neo_field}/add/{neo_component}',
        'params' => ['neo_field', 'neo_component'],
        'defaults' => $controller(InstanceComponentAddController::class),
        'requirement' => ['_neo_component' => 'neo_component.create'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
      'edit' => [
        'path' => '/{neo_field}/edit/{neo_component}',
        'params' => ['neo_field', 'neo_component'],
        'defaults' => $controller(InstanceComponentEditController::class),
        'requirement' => ['_neo_component' => 'neo_component.update'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
      'clone' => [
        'path' => '/{neo_field}/clone/{neo_component}',
        'params' => ['neo_field', 'neo_component'],
        'defaults' => $action(InstanceComponentCloneController::class, 'Clone'),
        'requirement' => ['_neo_component' => 'neo_component.clone'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
      'delete' => [
        'path' => '/{neo_field}/delete/{neo_component}',
        'params' => ['neo_field', 'neo_component'],
        'defaults' => $action(InstanceComponentDeleteController::class, 'Delete'),
        'requirement' => ['_neo_component' => 'neo_component.delete'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
      'move' => [
        'path' => '/{neo_field}/move/{neo_component}/{direction}',
        'params' => ['neo_field', 'neo_component'],
        'defaults' => $action(InstanceComponentMoveController::class, 'Move'),
        'requirement' => ['_neo_component_field' => 'neo_field.sort'],
        'admin_route' => TRUE,
        'preview' => FALSE,
      ],
    ];
  }

}
