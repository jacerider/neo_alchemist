<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Value;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\Plugin\ComponentValue\UserHasRoleValue;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the `user_has_role` veto provider.
 *
 * This is one of the plugin family that claims the value directly rather than
 * letting the processing mode decide — a veto. When the current user lacks the
 * configured roles it returns a claimed FALSE, so the search halts and no
 * fallback can put a truthy value back and reveal the component.
 *
 * Access-adjacent: a wrong verdict here shows role-gated content to the wrong
 * users, or hides it from the right ones. Assertions are on the outcome the
 * search reads — the produced value and whether it is claimed. The claim's
 * halting effect on the real pipeline is covered by
 * ComponentValueProcessingModeIntegrationTest; this pins the verdict itself.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\UserHasRoleValue
 * @see \Drupal\neo_alchemist\ValueProviderSearch
 */
#[Group('neo_alchemist')]
class UserHasRoleValueTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * Builds the plugin for an account with the given roles.
   *
   * @param string[] $accountRoles
   *   The roles the current user holds.
   * @param array $configuration
   *   The plugin configuration ('roles' and 'match').
   */
  private function plugin(array $accountRoles, array $configuration): UserHasRoleValue {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn($accountRoles);

    return new UserHasRoleValue(
      'user_has_role',
      [],
      $this->unusedShape(),
      $configuration,
      $account,
      $this->createMock(EntityTypeManagerInterface::class),
    );
  }

  /**
   * The role-matching truth table.
   *
   * @return array
   *   Cases of [account roles, match mode, configured roles, granted].
   */
  public static function matchCases(): array {
    return [
      'any: holds one of two => granted' => [
        ['authenticated', 'editor'], 'any', ['editor', 'admin'], TRUE,
      ],
      'any: holds both => granted' => [
        ['editor', 'admin'], 'any', ['editor', 'admin'], TRUE,
      ],
      'any: holds neither => denied' => [
        ['authenticated'], 'any', ['editor', 'admin'], FALSE,
      ],
      'all: holds both => granted' => [
        ['authenticated', 'editor', 'admin'], 'all', ['editor', 'admin'], TRUE,
      ],
      'all: holds only one => denied' => [
        ['authenticated', 'editor'], 'all', ['editor', 'admin'], FALSE,
      ],
      'all: holds neither => denied' => [
        ['authenticated'], 'all', ['editor', 'admin'], FALSE,
      ],
      'anonymous with no roles => denied' => [
        [], 'any', ['editor'], FALSE,
      ],
    ];
  }

  /**
   * A granted user passes the value through untouched and never claims.
   */
  #[DataProvider('matchCases')]
  public function testRoleMatching(array $accountRoles, string $match, array $roles, bool $granted): void {
    $plugin = $this->plugin($accountRoles, ['match' => $match, 'roles' => $roles]);

    $provision = $plugin->provide(TRUE);

    if ($granted) {
      $this->assertTrue($provision->getValue(), 'The incoming value passed through untouched.');
      $this->assertFalse($provision->isClaimed(), 'A granted user leaves the pipeline running.');
    }
    else {
      $this->assertFalse($provision->getValue(), 'A denied user resolves to FALSE.');
      $this->assertTrue($provision->isClaimed(), 'A denied user halts the pipeline so no fallback can reveal the component.');
    }
  }

  /**
   * The incoming value is returned verbatim when granted, whatever it is.
   */
  public function testGrantedPassesAnyValueThrough(): void {
    $plugin = $this->plugin(['editor'], ['match' => 'any', 'roles' => ['editor']]);

    // FALSE is a legitimate boolean value; the plugin must not "upgrade" it.
    $provision = $plugin->provide(FALSE);
    $this->assertFalse($provision->getValue(), 'An authored FALSE survives a granted check.');
    $this->assertFalse($provision->isClaimed());
  }

  /**
   * CHARACTERIZATION: an empty role list means opposite things per mode.
   *
   * With `any`, array_intersect() against an empty configured list is empty,
   * so nobody matches and the component is hidden from everyone. With `all`,
   * array_diff() of an empty list is empty, so everyone matches and nothing is
   * gated at all.
   *
   * The configuration form marks roles #required and filters empties, so this
   * is only reachable through imported or programmatically written config —
   * but the two modes failing open and closed respectively is worth stating,
   * because the safe-looking one is the deny.
   */
  public function testEmptyRoleListIsModeDependent(): void {
    $any = $this->plugin(['editor'], ['match' => 'any', 'roles' => []])->provide(TRUE);
    $this->assertFalse($any->getValue(), 'match=any with no roles denies everyone (fails closed).');
    $this->assertTrue($any->isClaimed());

    $all = $this->plugin(['editor'], ['match' => 'all', 'roles' => []])->provide(TRUE);
    $this->assertTrue($all->getValue(), 'match=all with no roles grants everyone (fails open).');
    $this->assertFalse($all->isClaimed());
  }

  /**
   * An unrecognised match mode denies rather than granting.
   *
   * The switch has no default arm, so $hasRoles stays FALSE — which is the
   * safe direction for an access-adjacent gate. Pinned so a refactor to
   * match() or a new mode cannot silently invert it.
   */
  public function testUnknownMatchModeDenies(): void {
    $provision = $this->plugin(['editor'], ['match' => 'sometimes', 'roles' => ['editor']])->provide(TRUE);

    $this->assertFalse($provision->getValue(), 'An unknown match mode fails closed.');
    $this->assertTrue($provision->isClaimed());
  }

  /**
   * The gate is never editable by a content author.
   */
  public function testNotEditable(): void {
    $plugin = $this->plugin(['editor'], ['match' => 'any', 'roles' => ['editor']]);

    $this->assertFalse($plugin->isEditable(), 'A role gate must not be exposed as an authorable value.');
  }

}
