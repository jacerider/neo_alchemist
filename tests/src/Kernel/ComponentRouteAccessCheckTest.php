<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Access\ComponentAccessCheck;
use Drupal\neo_alchemist\Access\ComponentFieldAccessCheck;
use Drupal\neo_alchemist\Access\ComponentPropAccessCheck;
use Drupal\neo_alchemist\Access\ComponentSlotAccessCheck;
use Drupal\neo_alchemist\Access\EntityComponentAccessCheck;
use Drupal\neo_alchemist\Access\FieldComponentAccessCheck;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Route;

/**
 * The parse, resolution, fallback and cacheability every checker shares.
 *
 * Six checkers each wrote out the same five steps — read a requirement, split
 * it on dots, destructure positionally, resolve each part as a route
 * parameter, call access — with the arity varying between two and three, one
 * of them padding its requirement string to fit, and the positional meaning
 * written down nowhere. Only two of the six were constructed in any test, and
 * four attached no cacheability at all, so their results varied by nothing and
 * were invalidated by nothing.
 *
 * These tests construct all six. The decisions the two entity-facing checkers
 * make are covered by EntityComponentRouteAccessTest; what is covered here is
 * the base they now share.
 *
 * @see \Drupal\neo_alchemist\Access\ComponentRouteAccessCheckBase
 */
#[Group('neo_alchemist')]
class ComponentRouteAccessCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * An account with no special permissions.
   */
  private AccountInterface $account;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['neo_alchemist']);
    $this->installEntitySchema('user');
    $account = User::create(['name' => 'checker']);
    $account->save();
    $this->account = $account;
  }

  /**
   * A component whose SDC declares one prop and one slot.
   */
  private function component(): Component {
    $component = Component::create([
      'label' => 'Route access fixture',
      'description' => 'Route access fixture',
      'component' => 'neo_alchemist_test:na_slot_host',
      'status' => TRUE,
    ]);
    $component->save();
    return $component;
  }

  /**
   * Runs one checker against a requirement and a set of route parameters.
   */
  private function check(object $checker, string $key, string $requirement, array $parameters = []): AccessResultInterface {
    // RouteMatch pre-filters parameters to the ones the route declares, so a
    // checker only ever sees a parameter the path actually has a slot for.
    $path = '/na-test' . implode('', array_map(static fn ($name) => '/{' . $name . '}', array_keys($parameters)));
    $route = (new Route($path))->setRequirement($key, $requirement);
    $raw = array_map(static fn ($value) => is_object($value) ? 'object' : $value, $parameters);
    return $checker->access($route, new RouteMatch('na.test', $route, $parameters, $raw), $this->account);
  }

  /**
   * Every checker, with the requirement key it reads.
   */
  public static function checkerProvider(): array {
    return [
      '_neo_component' => [ComponentAccessCheck::class, '_neo_component'],
      '_neo_component_field' => [ComponentFieldAccessCheck::class, '_neo_component_field'],
      '_neo_component_prop' => [ComponentPropAccessCheck::class, '_neo_component_prop'],
      '_neo_component_slot' => [ComponentSlotAccessCheck::class, '_neo_component_slot'],
      '_neo_entity_component' => [EntityComponentAccessCheck::class, '_neo_entity_component'],
      '_neo_field_component' => [FieldComponentAccessCheck::class, '_neo_field_component'],
    ];
  }

  /**
   * Builds a checker, injecting whatever its constructor needs.
   */
  private function checker(string $class): object {
    return $class === FieldComponentAccessCheck::class
      ? new FieldComponentAccessCheck($this->container->get('entity_field.manager'))
      : new $class();
  }

  /**
   * A route without this checker's requirement is not this checker's business.
   */
  #[DataProvider('checkerProvider')]
  public function testNoRequirementIsNeutral(string $class, string $key): void {
    $route = new Route('/na-test');
    $result = $this->checker($class)->access($route, new RouteMatch('na.test', $route), $this->account);
    $this->assertTrue($result->isNeutral(), 'A route this checker was not asked about gets no opinion.');
  }

  /**
   * A requirement naming parameters the route does not carry is neutral.
   *
   * The old checkers destructured the split requirement positionally and then
   * read parameters that might not be there; the base resolves a missing one
   * to NULL and lets the decision method decline.
   */
  #[DataProvider('checkerProvider')]
  public function testUnresolvableParametersAreNeutral(string $class, string $key): void {
    $result = $this->check($this->checker($class), $key, 'nothing.nowhere.update');
    $this->assertTrue($result->isNeutral());
  }

  /**
   * A requirement shorter than its declared format does not fatal.
   *
   * FieldComponentAccessCheck used to buy this by appending '..' to the string
   * it parsed — a workaround for an arity the format never declared.
   */
  public function testShortRequirementDoesNotFatal(): void {
    $result = $this->check($this->checker(FieldComponentAccessCheck::class), '_neo_field_component', 'entity_type_id');
    $this->assertTrue($result->isNeutral());
  }

  /**
   * The component checker defers to the component's own access handler.
   */
  public function testComponentCheckerMatchesTheEntityDecision(): void {
    $component = $this->component();
    $expected = $component->access('update', $this->account, TRUE);

    $result = $this->check(new ComponentAccessCheck(), '_neo_component', 'neo_component.update', [
      'neo_component' => $component,
    ]);

    $this->assertSame($expected->isAllowed(), $result->isAllowed(), 'The route answer is the entity answer.');
    $this->assertContains('config:neo_alchemist.neo_component.' . $component->id(), $result->getCacheTags(), 'The decision is invalidated when the component changes.');
  }

  /**
   * A parameter that is not a component is not this checker's business.
   */
  public function testComponentCheckerIgnoresUnrelatedParameter(): void {
    $result = $this->check(new ComponentAccessCheck(), '_neo_component', 'neo_component.update', [
      'neo_component' => 'not-an-entity',
    ]);
    $this->assertTrue($result->isNeutral());
  }

  /**
   * The prop checker resolves a declared prop and attaches the component.
   */
  public function testPropCheckerResolvesDeclaredProp(): void {
    $component = $this->component();
    $result = $this->check(new ComponentPropAccessCheck(), '_neo_component_prop', 'neo_component.prop.manage_value', [
      'neo_component' => $component,
      'prop' => 'text',
    ]);

    $this->assertSame($component->access('update', $this->account, TRUE)->isAllowed(), $result->isAllowed());
    $this->assertContains('config:neo_alchemist.neo_component.' . $component->id(), $result->getCacheTags());
  }

  /**
   * A prop the component does not declare gets no opinion.
   */
  public function testPropCheckerDeclinesUnknownProp(): void {
    $result = $this->check(new ComponentPropAccessCheck(), '_neo_component_prop', 'neo_component.prop.manage_value', [
      'neo_component' => $this->component(),
      'prop' => 'no_such_prop',
    ]);
    $this->assertTrue($result->isNeutral());
  }

  /**
   * The slot checker allows a slot the component declares, and only that one.
   */
  public function testSlotCheckerAllowsDeclaredSlot(): void {
    $component = $this->component();

    $allowed = $this->check(new ComponentSlotAccessCheck(), '_neo_component_slot', 'neo_component.slot', [
      'neo_component' => $component,
      'slot' => 'body',
    ]);
    $this->assertTrue($allowed->isAllowed());
    $this->assertContains('config:neo_alchemist.neo_component.' . $component->id(), $allowed->getCacheTags(), 'Which slots exist comes from the component.');

    $neutral = $this->check(new ComponentSlotAccessCheck(), '_neo_component_slot', 'neo_component.slot', [
      'neo_component' => $component,
      'slot' => 'no_such_slot',
    ]);
    $this->assertTrue($neutral->isNeutral());
  }

}
