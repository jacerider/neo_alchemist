<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the component-level prop editability policy.
 *
 * Editability used to be per-prop only, with a hardcoded TRUE for any prop
 * that carried no stored setting. That default is reached exactly when a
 * component's template grows a prop: setCachedDefinitions() re-saves the
 * component on the next cache rebuild, preSave() re-serializes the prop set,
 * and the new prop lands editable whether or not anything else on that
 * component is. On a content-driven component that is a silent hole.
 *
 * The policy closes it with two distinct mechanisms, and the distinction is
 * what these tests exist to pin:
 *
 * - "guarded" changes only the DEFAULT, at shape construction. Stored per-prop
 *   settings are never rewritten, which is what makes it safe to switch on for
 *   a component that deliberately leaves some props open.
 * - "locked" is a live OVERRIDE, through isLocked(). Stored settings stay
 *   intact and dormant beneath it, and resume the moment the mode changes.
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::__construct()
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::isLocked()
 * @see \Drupal\neo_alchemist\Entity\Component::setPropShapeSettings()
 */
#[Group('neo_alchemist')]
class PropEditabilityTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    // The fixture's `link` prop stores into a link field item whose enum'd
    // `target` child resolves to a list_string item, so both field types must
    // exist. Same pair AggregateModeTest needs for this fixture.
    'link',
    'options',
    'entity_test',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
  }

  /**
   * Creates a fixture component with three top-level props.
   *
   * Component::save() re-derives a new component's id from its SDC id, so the
   * id is read back rather than assumed.
   */
  private function createComponent(?string $mode = NULL): Component {
    $values = [
      'label' => 'Prop editability fixture',
      'description' => 'Prop editability fixture',
      'component' => 'neo_alchemist_test:na_falsy_object',
      'status' => TRUE,
      'target_entity_type' => 'entity_test',
    ];
    if ($mode !== NULL) {
      $values['prop_editability'] = $mode;
    }
    $component = Component::create($values);
    $component->save();
    return $this->reload($component->id());
  }

  /**
   * Reloads the component, dropping every cached shape.
   */
  private function reload(string $id): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);
    return $component;
  }

  /**
   * The stored `settings.props` as raw config.
   */
  private function storedProps(string $id): array {
    return $this->container->get('config.factory')
      ->get('neo_alchemist.neo_component.' . $id)
      ->get('settings.props') ?? [];
  }

  /**
   * The stored `editable` flag per prop, as raw config.
   */
  private function storedEditable(string $id): array {
    return array_map(fn (array $prop) => $prop['editable'] ?? NULL, $this->storedProps($id));
  }

  /**
   * Removes a prop's stored settings, as if the template had just grown it.
   *
   * This is the state ComponentShapePluginManager::getInstancesFromSchema()
   * hands a shape for a prop it has never seen — an empty settings array — and
   * is what preSave() re-serializes from after a schema change.
   */
  private function forgetPropSettings(string $id, string $prop): void {
    $config = $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id);
    $props = $config->get('settings.props') ?? [];
    unset($props[$prop]);
    $config->set('settings.props', $props)->save();
  }

  /**
   * Open is the default, and is today's behavior unchanged.
   *
   * The non-vacuity guard for everything below: if the fixture's props were not
   * editable to begin with, no assertion in this file would mean anything.
   */
  public function testOpenIsTheDefaultAndLeavesPropsEditable(): void {
    $component = $this->createComponent();

    $this->assertSame(ComponentInterface::PROP_EDITABILITY_OPEN, $component->getPropEditability());
    foreach ($component->getPropShapes() as $name => $shape) {
      $this->assertFalse($shape->isLocked(), sprintf('Prop "%s" is not locked.', $name));
      $this->assertTrue($shape->isEditable(), sprintf('Prop "%s" is editable.', $name));
    }
  }

  /**
   * Guarded flips the default for a prop the template has just grown.
   *
   * The bug this policy exists for. Both halves matter: the new prop must come
   * back locked, and the props that were already configured must be left
   * exactly as the site builder set them — a "guarded" mode that rewrote stored
   * settings could never be switched on for the mixed components that make up
   * every real content-driven component on a site.
   */
  public function testGuardedLocksUnconfiguredPropsAndLeavesStoredOnesAlone(): void {
    $component = $this->createComponent();
    $id = $component->id();

    $component->setPropEditability(ComponentInterface::PROP_EDITABILITY_GUARDED)->save();
    $this->assertSame(
      ['box' => TRUE, 'link' => TRUE, 'count' => TRUE],
      $this->storedEditable($id),
      'Precondition: switching to guarded rewrites nothing that is already stored.',
    );

    // The template grows a prop: it has no stored settings entry.
    $this->forgetPropSettings($id, 'count');
    $component = $this->reload($id);

    $this->assertFalse($component->getPropShape('count')->isEditable(), 'The unconfigured prop defaults to non-editable.');
    $this->assertTrue($component->getPropShape('box')->isEditable(), 'A prop that carries its own setting still honors it.');

    // And the answer persists, rather than being re-derived every request.
    $component->save();
    $this->assertSame(['box' => TRUE, 'link' => TRUE, 'count' => FALSE], $this->storedEditable($id));
  }

  /**
   * Open leaves the same unconfigured prop editable.
   *
   * The contrast case. Without it, the assertion above could pass for a reason
   * that has nothing to do with the mode.
   */
  public function testOpenLeavesUnconfiguredPropsEditable(): void {
    $component = $this->createComponent();
    $id = $component->id();

    $this->forgetPropSettings($id, 'count');
    $component = $this->reload($id);

    $this->assertTrue($component->getPropShape('count')->isEditable());
  }

  /**
   * Locked overrides every root prop, whatever it has stored.
   */
  public function testLockedLocksEveryRootProp(): void {
    $component = $this->createComponent(ComponentInterface::PROP_EDITABILITY_LOCKED);

    foreach ($component->getPropShapes() as $name => $shape) {
      $this->assertTrue($shape->isLocked(), sprintf('Prop "%s" is locked.', $name));
      $this->assertFalse($shape->isEditable(), sprintf('Prop "%s" is not editable.', $name));
    }
  }

  /**
   * A component created locked stores non-editable props on its own.
   *
   * ComponentForm no longer stamps the per-prop flags for a new "entity" group
   * component — it sets the mode and nothing else. That only produces a sane
   * dormant layer because the mode also supplies the construction default, so
   * a component whose props have never been configured stores them FALSE. If
   * this ever regressed to storing TRUE, the first site builder to loosen the
   * mode would open every prop at once instead of the one they meant.
   *
   * @see \Drupal\neo_alchemist\Form\ComponentForm::save()
   */
  public function testCreatingLockedStoresNonEditableProps(): void {
    $component = $this->createComponent(ComponentInterface::PROP_EDITABILITY_LOCKED);

    $this->assertSame(
      ['box' => FALSE, 'link' => FALSE, 'count' => FALSE],
      $this->storedEditable($component->id()),
      'The mode supplies the stored flags; nothing has to stamp them.',
    );
  }

  /**
   * Locked reaches nested shapes too.
   *
   * A root-only implementation would leave every child of an object prop
   * editable, which on this fixture is most of the surface a content editor
   * actually touches.
   */
  public function testLockedLocksNestedShapes(): void {
    $component = $this->createComponent(ComponentInterface::PROP_EDITABILITY_LOCKED);
    $all = $component->getPropShapesAll();

    $nested = array_filter($all, fn ($shape) => $shape->isNested());
    $this->assertNotEmpty($nested, 'Precondition: the fixture has nested shapes to check.');
    foreach ($nested as $id => $shape) {
      $this->assertTrue($shape->isLocked(), sprintf('Nested shape "%s" is locked.', $id));
    }
  }

  /**
   * Leaving locked restores each prop's own stored setting.
   *
   * The dormancy contract, end to end. If the override were ever materialized
   * into settings.props, the mixed state set up here would come back uniformly
   * non-editable and the site builder's configuration would be gone.
   */
  public function testLockedIsReversible(): void {
    $component = $this->createComponent();
    $id = $component->id();

    $component->setPropShapeSettings($component->getPropShape('count')->setEditable(FALSE));
    $component->save();
    $this->assertSame(['box' => TRUE, 'link' => TRUE, 'count' => FALSE], $this->storedEditable($id));

    $component = $this->reload($id);
    $component->setPropEditability(ComponentInterface::PROP_EDITABILITY_LOCKED)->save();

    $component = $this->reload($id);
    $component->setPropEditability(ComponentInterface::PROP_EDITABILITY_OPEN)->save();

    $component = $this->reload($id);
    $this->assertTrue($component->getPropShape('box')->isEditable());
    $this->assertFalse($component->getPropShape('count')->isEditable());
  }

  /**
   * The lock is never written into the per-prop settings.
   *
   * Persisting must use getEditable(), the raw flag, and not isEditable(),
   * which folds in the override. Writing the resolved answer
   * would bake the lock into settings.props on the next cache rebuild and
   * destroy the dormant layer the test above depends on — irreversibly, since
   * nothing records what the flags were before.
   */
  public function testLockIsNeverWrittenIntoPropSettings(): void {
    $component = $this->createComponent();
    $id = $component->id();

    $component->setPropShapeSettings($component->getPropShape('count')->setEditable(FALSE));
    $component->save();

    $component = $this->reload($id);
    $component->setPropEditability(ComponentInterface::PROP_EDITABILITY_LOCKED)->save();

    $this->assertSame(
      ['box' => TRUE, 'link' => TRUE, 'count' => FALSE],
      $this->storedEditable($id),
      'Saving while locked persists the raw per-prop flags, not the resolved ones.',
    );
  }

  /**
   * Locking forbids editing a prop's value but not configuring the prop.
   *
   * The two operations are deliberately different: a locked component is still
   * one a site builder configures value providers on, and only 'update' —
   * per-instance value editing — is what the lock takes away.
   *
   * @see \Drupal\neo_alchemist\ComponentShapePluginBase::checkAccess()
   */
  public function testLockForbidsUpdateButNotManageValue(): void {
    // Both operations gate on the component's own update access first, so an
    // anonymous kernel test would report FALSE for reasons unrelated to the
    // lock.
    $this->installConfig(['user']);
    $this->setUpCurrentUser(['uid' => 1], ['administer neo_alchemist']);

    $component = $this->createComponent(ComponentInterface::PROP_EDITABILITY_LOCKED);
    $shape = $component->getPropShape('count');
    $this->assertFalse($shape->access('update'), 'A locked prop cannot have its value edited.');
    $this->assertTrue($shape->access('manage_value'), 'A locked prop can still be configured.');
  }

  /**
   * The mode survives a toggle that rebuilds the whole prop set.
   *
   * Aggregating changes the generated expression, which sends preSave() down
   * its `setSetting('props', [])` rebuild branch. Per-prop settings do not
   * survive that (see AggregateModeTest::testTogglingDiscardsPropSettings) —
   * the mode does, because it is stored top-level rather than inside settings.
   * That is precisely why a mode is the right home for a standing rule.
   */
  public function testModeSurvivesAggregateToggle(): void {
    $component = $this->createComponent(ComponentInterface::PROP_EDITABILITY_LOCKED);
    $id = $component->id();

    $component->set('aggregate', TRUE)->save();
    $component = $this->reload($id);

    $this->assertSame(ComponentInterface::PROP_EDITABILITY_LOCKED, $component->getPropEditability());
    $this->assertTrue($component->getPropShape('_aggregate')->isLocked());
  }

  /**
   * The mode rides along on a clone.
   *
   * ComponentCloneForm saves the copied configuration as-is rather than letting
   * the group defaults re-apply, so anything the source deliberately carries
   * has to be in config_export to survive the copy.
   */
  public function testModeIsCarriedByClone(): void {
    $source = $this->createComponent(ComponentInterface::PROP_EDITABILITY_GUARDED);

    $values = $source->toArray();
    unset($values['uuid']);
    $values['id'] = 'prop_editability_clone';
    $clone = Component::create($values);

    $this->assertSame(ComponentInterface::PROP_EDITABILITY_GUARDED, $clone->getPropEditability());
  }

}
