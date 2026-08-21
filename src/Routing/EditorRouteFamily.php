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
   */
  public const SCOPE_FIELD_UI = 'field_ui';

  /**
   * The block scope: a null-storage host offered as a placeable block.
   *
   * The neo_alchemist_block submodule registers this family from a subscriber
   * of its own. Its route names follow the field-UI pattern because the block's
   * field config (AlchemistBlockFieldConfig, a ComponentFieldConfig subclass)
   * builds them by interpolating the host entity type, so routeName() maps it
   * to the same `entity.{id}.field_ui.alchemist.*` prefix. It is a scope in its
   * own right rather than a subset passed through SCOPE_FIELD_UI so its op set
   * — and its deliberate purge opt-out — are declared here, in the table, where
   * the cross-scope parity test reads them.
   *
   * @see \Drupal\neo_alchemist_block\EventSubscriber\RouteSubscriber
   */
  public const SCOPE_BLOCK = 'block';

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
   * The scopes differ, and the differences are declarations rather than
   * accidents:
   *
   * - `publish`, `revert` and `reset` are entity-scope only. They act on a
   *   single entity's stored draft/published pair; a field's shared default
   *   layout has no per-entity draft to publish, revert or reset, so the ops
   *   are meaningless in the field-UI scope.
   * - `purge` is a field-UI-scope member the entity and block scopes both opt
   *   out of. Purging removes the per-entity layout rows a now-locked field no
   *   longer renders (@see \Drupal\neo_alchemist\Form\PurgeInertDataForm), so
   *   it is a decision about the field across every entity, never about one
   *   entity, which is why the entity scope has no purge. The block scope opts
   *   out for a second reason: its host (neo_alchemist_block_host) uses
   *   ContentEntityNullStorage, so no entity ever persists a layout row behind
   *   a block and purge is a structural no-op there. Offering it would present
   *   a "Purge stored layout data" action that can only ever report "Nothing
   *   to purge". The opt-out is deliberate, and
   *   AlchemistBlockFieldConfig::toUrl() refuses the `purge` rel to match this
   *   declaration.
   * - The management landing (OP_BASE) is entity- and field-UI-scope only. The
   *   block scope reaches its editor straight through the `manage` op (its
   *   entity_operation "Layout" link and local tasks target that route), so it
   *   registers no bare landing.
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
    // The field-UI set minus the two members declared above: no management
    // landing, and no purge (a structural no-op for a null-storage host).
    self::SCOPE_BLOCK => [
      'manage',
      'preview',
      'library',
      'sort',
      'add',
      'edit',
      'clone',
      'delete',
      'move',
    ],
  ];

  /**
   * The deliberate cross-scope differences: op → (scope → reason).
   *
   * SCOPE_OPS lists what each scope offers; this lists why the scopes are not
   * all the same. It names, per op, the scopes that deliberately opt out of it
   * and the reason each does — the same asymmetries the SCOPE_OPS docblock
   * narrates, promoted to data so the cross-scope parity test can read them
   * instead of hardcoding a copy. The two declarations are authored by hand and
   * cross-checked against each other: every op some scope offers but not all
   * must appear here naming exactly the scopes that lack it, and every entry
   * here must name a real difference. An op dropped from one scope without a
   * matching entry — the class of drift that lost the block scope its purge
   * route — makes the two disagree, which is a red test rather than a silent
   * hole (@see \Drupal\Tests\neo_alchemist\Kernel\CrossScopeOpParityTest).
   *
   * Each reason below is the whole of the rationale — the SCOPE_OPS docblock
   * narrates the same asymmetries from the op-set side; this is the same story
   * told once more, as data. Keep the two in step when a scope's ops change.
   */
  private const SCOPE_DIVERGENCES = [
    self::OP_BASE => [
      self::SCOPE_BLOCK => 'The block reaches its editor through the manage op, so it registers no bare management landing.',
    ],
    'publish' => [
      self::SCOPE_FIELD_UI => 'A shared default layout has no per-entity draft to publish.',
      self::SCOPE_BLOCK => 'A null-storage block host has no per-entity draft to publish.',
    ],
    'revert' => [
      self::SCOPE_FIELD_UI => 'A shared default layout has no per-entity draft to revert.',
      self::SCOPE_BLOCK => 'A null-storage block host has no per-entity draft to revert.',
    ],
    'reset' => [
      self::SCOPE_FIELD_UI => 'A shared default layout has no per-entity draft to reset.',
      self::SCOPE_BLOCK => 'A null-storage block host has no per-entity draft to reset.',
    ],
    'purge' => [
      self::SCOPE_ENTITY => 'Purge clears the field-wide per-entity layout rows a locked field no longer renders — a decision about the field across every entity, never about one entity.',
      self::SCOPE_BLOCK => 'A null-storage block host persists no per-entity layout rows, so purge is a structural no-op there.',
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
   * The deliberate cross-scope differences, as data.
   *
   * @return array<string, array<string, string>>
   *   Op id => (scope constant => reason the scope opts out of the op). Only
   *   ops that some scope offers and another deliberately withholds appear; an
   *   op every scope offers is absent.
   *
   * @see self::SCOPE_DIVERGENCES
   */
  public function divergences(): array {
    return self::SCOPE_DIVERGENCES;
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
      // The block scope shares the field-UI name pattern deliberately: its URL
      // generator (AlchemistBlockFieldConfig) builds field_ui.alchemist names.
      self::SCOPE_FIELD_UI,
      self::SCOPE_BLOCK => "entity.$entityTypeId.field_ui.alchemist",
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
   * The entity-scope link templates, derived from the same member table.
   *
   * The module's entity_type_alter (neo_alchemist_entity_type_alter) puts one
   * link template on the host entity type per entity-scope op, so an entity can
   * address any op through $entity->toUrl('alchemist.{op}'). Deriving them here
   * from the member table the routes come from keeps a template from naming a
   * path no route serves (the phantom `alchemist.region` this replaced) and a
   * route from going un-addressable for want of a template (the `move` route,
   * which the hand-written list omitted). A template and a route can no longer
   * disagree, because neither is written by hand.
   *
   * The key is the string routeName() produces with the `entity.{id}.` prefix
   * stripped — `alchemist` for the management landing, `alchemist.{op}` for a
   * member op — because an entity's `alchemist.{op}` rel resolves to the
   * `entity.{id}.alchemist.{op}` route. Only SCOPE_ENTITY carries link
   * templates; the field-UI and block scopes address routes by name, not rel.
   *
   * @param string $scope
   *   One of the SCOPE_* constants (SCOPE_ENTITY in practice — see above).
   * @param string $pathPrefix
   *   The alchemist base path: the host's `alchemist` link template value
   *   ("{canonical}/alchemist"), which is also the entity scope's route path
   *   prefix, so the base op's template is exactly this string.
   * @param string[]|null $ops
   *   The subset of ops to template, in order. NULL templates the scope's full
   *   declared set.
   *
   * @return string[]
   *   Link-template key => path template, keyed the same way build() keys its
   *   route names below the `entity.{id}.` prefix.
   */
  public function linkTemplates(string $scope, string $pathPrefix, ?array $ops = NULL): array {
    $ops ??= $this->opsForScope($scope);
    $memberSpecs = self::memberSpecs();
    $templates = [];
    foreach ($ops as $op) {
      // The base op's empty path mirrors baseSpec()'s — the landing lives at
      // the alchemist root — so its template is $pathPrefix itself. The
      // bidirectional kernel test pins this key set against build()'s names.
      $path = $op === self::OP_BASE
        ? ''
        : ($memberSpecs[$op]['path'] ?? throw new \InvalidArgumentException("Unknown editor op: $op"));
      $key = $op === self::OP_BASE ? self::OP_BASE : self::OP_BASE . '.' . $op;
      $templates[$key] = $pathPrefix . $path;
    }
    return $templates;
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
