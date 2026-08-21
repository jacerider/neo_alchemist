<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Routing\EditorRouteFamily;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cross-scope parity: every scope offers one op set, or differs as declared.
 *
 * The regression guard for the whole class of drift this effort closes: a site
 * builder in a block-scoped layout should get the same operations as one in a
 * field-scoped layout, and where a scope genuinely differs that difference
 * must be a decision the table records rather than a route someone forgot to
 * copy. This is the test that would have caught the missing purge route: the
 * field-UI scope had it and the block scope silently did not, and nothing
 * forced anyone to decide whether that was intentional.
 *
 * The deliberate differences are read from the table itself
 * (EditorRouteFamily::divergences()), never from a list hardcoded here. So
 * changing the table's mind about which scope offers what changes what this
 * test expects, and forgetting a scope — dropping an op from one scope, or
 * adding one to another without declaring the opt-out — fails here rather than
 * shipping.
 *
 * The subscribers are driven end to end elsewhere: EditorRouteFamilyTest for
 * the entity and field-UI scopes, AlchemistBlockRouteFamilyTest for the block
 * scope. This test asserts the cross-scope invariant none of those can see
 * alone, over the pure table the subscribers all register through.
 *
 * @see \Drupal\neo_alchemist\Routing\EditorRouteFamily
 */
#[Group('neo_alchemist')]
final class CrossScopeOpParityTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The table's methods are pure, so the module's hard floor is enough — this
   * test never registers against a real host, it asserts the family the table
   * would register for each scope.
   */
  protected static $modules = ['system', 'user', 'neo_settings', 'neo_alchemist'];

  /**
   * Each host scope, and the host entity type its route names interpolate.
   *
   * The block host differs deliberately — its route names follow the field-UI
   * pattern under its own null-storage host — so the parity asserted here is
   * over ops, not over the host string each scope happens to carry.
   */
  private const SCOPE_HOSTS = [
    EditorRouteFamily::SCOPE_ENTITY => 'node',
    EditorRouteFamily::SCOPE_FIELD_UI => 'node',
    EditorRouteFamily::SCOPE_BLOCK => 'neo_alchemist_block_host',
  ];

  /**
   * The route family builder under test.
   */
  private function family(): EditorRouteFamily {
    return $this->container->get('neo_alchemist.editor_route_family');
  }

  /**
   * Each scope registers exactly the ops the table declares for it, in order.
   *
   * The per-scope half of the guard: a scope missing a route its own table row
   * says it should carry fails here. Registration keys the routes by the name
   * routeName() spells, so this also ties build() to that single naming site.
   */
  public function testEachScopeRegistersItsDeclaredOps(): void {
    foreach (self::SCOPE_HOSTS as $scope => $host) {
      $registered = array_keys($this->family()->build($scope, $host, '/p'));
      $expected = array_map(
        fn (string $op) => $this->family()->routeName($scope, $host, $op),
        $this->family()->opsForScope($scope),
      );
      $this->assertSame($expected, $registered, "$scope registers its declared ops.");
    }
  }

  /**
   * The route-name pattern is pinned, so a rename fails here not downstream.
   *
   * Link templates, configuration and code outside this repository depend on
   * these exact strings; two URL generators build them by interpolating the
   * host entity type. The entity scope names `entity.{id}.alchemist(.{op})`;
   * the field-UI and block scopes share `entity.{id}.field_ui.alchemist(.{op})`
   * because the block's URL generator interpolates the field-UI pattern.
   */
  public function testRouteNamePatternIsPinned(): void {
    $family = $this->family();

    $entity = EditorRouteFamily::SCOPE_ENTITY;
    $this->assertSame('entity.node.alchemist', $family->routeName($entity, 'node', 'alchemist'));
    $this->assertSame('entity.node.alchemist.edit', $family->routeName($entity, 'node', 'edit'));

    $fieldUi = EditorRouteFamily::SCOPE_FIELD_UI;
    $this->assertSame('entity.node.field_ui.alchemist', $family->routeName($fieldUi, 'node', 'alchemist'));
    $this->assertSame('entity.node.field_ui.alchemist.edit', $family->routeName($fieldUi, 'node', 'edit'));

    $this->assertSame(
      'entity.neo_alchemist_block_host.field_ui.alchemist.edit',
      $family->routeName(EditorRouteFamily::SCOPE_BLOCK, 'neo_alchemist_block_host', 'edit'),
    );
  }

  /**
   * Every cross-scope difference is a declared decision, and vice versa.
   *
   * The centrepiece. The op each scope offers comes from the per-scope table
   * rows (opsForScope); the differences deemed deliberate come from a separate
   * declaration (divergences). This asserts the two agree exactly: an op some
   * scope offers but not all must be declared, naming precisely the scopes that
   * opt out of it — and a declared opt-out must name a real difference. The two
   * are authored independently, so an undeclared drift (the missing purge
   * route) makes them disagree here.
   */
  public function testDifferencesAreDeclaredAndDeclarationsAreReal(): void {
    $family = $this->family();
    $scopes = array_keys(self::SCOPE_HOSTS);

    $perScope = [];
    foreach ($scopes as $scope) {
      $perScope[$scope] = $family->opsForScope($scope);
    }

    // The differences the per-scope rows actually describe: for every op any
    // scope offers, the scopes that do not offer it.
    $actual = [];
    foreach (array_unique(array_merge(...array_values($perScope))) as $op) {
      $missing = array_values(array_filter(
        $scopes,
        static fn (string $scope) => !in_array($op, $perScope[$scope], TRUE),
      ));
      if ($missing !== []) {
        sort($missing);
        $actual[$op] = $missing;
      }
    }

    // The differences the table declares deliberate, read from the same table.
    $declared = array_map(
      static function (array $scopesWithReasons): array {
        $declaredScopes = array_keys($scopesWithReasons);
        sort($declaredScopes);
        return $declaredScopes;
      },
      $family->divergences(),
    );

    ksort($actual);
    ksort($declared);
    $this->assertSame(
      $declared,
      $actual,
      'Every op a scope opts out of is a declared decision naming exactly its scopes.',
    );
  }

  /**
   * An op every scope offers carries no opt-out declaration.
   *
   * The other side of the same coin: a divergence is only for a real
   * difference, so a common op must not appear among them. Without this a stale
   * declaration for an op every scope had regained would pass unnoticed.
   */
  public function testCommonOpsCarryNoOptOut(): void {
    $family = $this->family();
    $perScope = array_map(
      fn (string $scope) => $family->opsForScope($scope),
      array_keys(self::SCOPE_HOSTS),
    );
    $common = array_intersect(...$perScope);
    foreach ($common as $op) {
      $this->assertArrayNotHasKey($op, $family->divergences(), "Common op $op needs no opt-out.");
    }
  }

  /**
   * Every declared opt-out records a reason.
   *
   * "A decision someone made" is only visible if the decision says why. The
   * reasons live in the table beside the opt-out, so a new divergence added
   * without a rationale fails here rather than silently becoming an accident of
   * the kind this test exists to prevent.
   */
  public function testEveryDeclaredOptOutHasReason(): void {
    foreach ($this->family()->divergences() as $op => $scopesWithReasons) {
      foreach ($scopesWithReasons as $scope => $reason) {
        $this->assertNotSame('', trim((string) $reason), "$scope opt-out of $op has a reason.");
      }
    }
  }

}
