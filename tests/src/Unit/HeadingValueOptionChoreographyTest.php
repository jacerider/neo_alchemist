<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapeOption;
use Drupal\neo_alchemist\ComponentShapeOptionsInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\NestedOptionMap;
use Drupal\neo_alchemist\Plugin\ComponentValue\HeadingValue;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the Heading provider records for its sub-props, without a kernel.
 *
 * This one method is where two thirds of the module's nested-option traffic
 * came from, and until the options became an object none of it could be seen
 * without building a component, saving config and booting a container — so the
 * Heading suite is a kernel test that reaches its conclusions through resolved
 * child shapes. That suite still earns its keep: it pins what a site builder
 * ends up seeing. This one pins the step in between, which is what actually
 * went wrong the last two times.
 *
 * The distinction it turns on is the saved layer versus the fallback one. A
 * source says "start here unless something else has an opinion" and writes the
 * fallback; turning editing off is a decision and writes the saved layer. The
 * provider then reads the saved layer back — mid-method — to decide whether the
 * anchor sub-prop follows the title, so the two layers are not interchangeable
 * and a test that could only see their union could not tell them apart.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\HeadingValue::onShapeInit()
 * @see \Drupal\Tests\neo_alchemist\Kernel\HeadingValueSubPropOptionsTest
 */
#[Group('neo_alchemist')]
class HeadingValueOptionChoreographyTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * The heading shape's own "empty" option.
   *
   * @var \Drupal\neo_alchemist\ComponentShapeOption
   */
  private ComponentShapeOption $emptyOption;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->emptyOption = new ComponentShapeOption(FALSE, TRUE);
  }

  /**
   * A heading shape reading and writing the given option map.
   *
   * @param \Drupal\neo_alchemist\NestedOptionMap $options
   *   The map, already scoped to the shape.
   * @param bool $children
   *   Whether the shape reports children.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The shape double.
   */
  private function shape(NestedOptionMap $options, bool $children = TRUE): ComponentShapePluginInterface {
    // Both of the provider's questions are the options role's — the map it
    // writes sub-prop options into, and the heading's own empty toggle. Having
    // children is a capability rather than something it asks about: the
    // provider tests for it with instanceof and does nothing without it.
    $role = $this->shapeRole(ComponentShapeOptionsInterface::class);
    $role->method('getNestedOptionMap')->willReturn($options);
    $role->method('getOptionEmpty')->willReturn($this->emptyOption);

    return $this->shapeDouble(
      [$role],
      $children ? [ComponentShapeChildrenPluginInterface::class] : [],
    );
  }

  /**
   * Builds the provider against a shape.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape the provider sits on.
   * @param array $settings
   *   Heading provider settings, merged over the plugin's own defaults.
   *
   * @return \Drupal\neo_alchemist\Plugin\ComponentValue\HeadingValue
   *   The provider.
   */
  private function plugin(ComponentShapePluginInterface $shape, array $settings): HeadingValue {
    return new HeadingValue(
      'heading',
      [],
      $shape,
      $settings,
      Request::create('/'),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(TitleResolverInterface::class),
    );
  }

  /**
   * Runs the provider's shape-init against a bare option map.
   *
   * @param array $settings
   *   Heading provider settings, merged over the plugin's own defaults.
   * @param bool $children
   *   Whether the shape reports children. A heading that does not is left
   *   alone entirely.
   *
   * @return \Drupal\neo_alchemist\NestedOptionMap
   *   The map, scoped to the heading shape.
   */
  private function onShapeInit(array $settings, bool $children = TRUE): NestedOptionMap {
    $options = (new NestedOptionMap())->forShape('heading');
    $this->plugin($this->shape($options, $children), $settings)->onShapeInit();
    return $options;
  }

  /**
   * Runs the provider's form alter over a heading form with an anchor in it.
   *
   * @param \Drupal\neo_alchemist\NestedOptionMap $options
   *   The map the form reads, as a previous ::onShapeInit() left it.
   *
   * @return array
   *   The altered element.
   */
  private function alterForm(NestedOptionMap $options): array {
    $element = [
      '#id' => 'heading-form',
      'title' => ['_options' => ['default' => ['#type' => 'checkbox']]],
      'anchor' => ['#type' => 'textfield'],
    ];
    $this->plugin($this->shape($options), [])
      ->formAlter($element, $this->createMock(FormStateInterface::class));
    return $element;
  }

  /**
   * An untouched provider records nothing at all.
   *
   * Every branch below is opt-in, so the do-nothing case is the baseline the
   * others are read against.
   */
  public function testAnUntouchedProviderRecordsNothing(): void {
    $this->assertSame([], $this->onShapeInit([])->toArray());
  }

  /**
   * Sub-prop options are keyed under the heading shape, not by bare name.
   *
   * The provider names `title`; what reaches the store is the child's id. Two
   * headings on one component would otherwise share a key.
   */
  public function testSubPropsAreKeyedUnderTheHeadingShape(): void {
    $options = $this->onShapeInit(['title_edit' => FALSE]);

    $this->assertSame(['heading~title', 'heading~anchor'], array_keys($options->toArray()));
  }

  /**
   * A source starts its sub-prop on its default, as a fallback.
   *
   * The value a source produces IS the sub-prop's default, so the provider
   * asks for it rather than deciding it: anything the site builder saved for
   * that sub-prop still wins.
   */
  public function testSourceAsksForTheDefaultRatherThanDecidingIt(): void {
    $options = $this->onShapeInit(['title_page' => TRUE]);

    $this->assertSame(
      ['heading~title' => [NestedOptionMap::OPTION_DEFAULT => TRUE]],
      $options->toArray(),
    );
    $this->assertFalse(
      $options->get('title', NestedOptionMap::OPTION_DEFAULT),
      'A source writes the fallback layer, which the saved-layer read does not see.',
    );
  }

  /**
   * Every kind of source does the same thing.
   *
   * The three used to be an `??` chain, which stopped at the first key rather
   * than asking whether any of them was set — and since every key is seeded by
   * defaultConfiguration(), the second and third were unreachable.
   */
  public function testEverySourceAsksForTheDefault(): void {
    foreach (['title_page' => TRUE, 'title_entity' => TRUE, 'title_field' => 'name'] as $key => $value) {
      $options = $this->onShapeInit([$key => $value]);

      $this->assertSame(
        ['heading~title' => [NestedOptionMap::OPTION_DEFAULT => TRUE]],
        $options->toArray(),
        "The {$key} source did not start the sub-prop on its default.",
      );
    }
  }

  /**
   * Hiding a sub-prop is the site builder's own switch and nothing else.
   *
   * A source must not also hide, because the empty option beats the default one
   * when the value resolves — so a sourced-and-hidden sub-prop fetches its
   * value and then throws it away.
   */
  public function testOnlyTheHideSettingHides(): void {
    $sourced = $this->onShapeInit(['title_page' => TRUE])->toArray();
    $hidden = $this->onShapeInit(['title_empty' => TRUE])->toArray();

    $this->assertArrayNotHasKey(NestedOptionMap::OPTION_EMPTY, $sourced['heading~title']);
    $this->assertSame([NestedOptionMap::OPTION_EMPTY => TRUE], $hidden['heading~title']);
  }

  /**
   * Turning editing off is a decision, so it is saved rather than asked for.
   *
   * It pins the sub-prop to its default and withdraws the editor's access. The
   * withdrawal is the one that used to read backwards: the wrapper was named
   * "set access" and defaulted its value to FALSE.
   */
  public function testTurningEditingOffPinsAndWithdrawsAccess(): void {
    $options = $this->onShapeInit(['title_edit' => FALSE]);

    $this->assertTrue($options->get('title', NestedOptionMap::OPTION_DEFAULT));
    $this->assertFalse($options->get('title', NestedOptionMap::OPTION_ACCESS));
    $this->assertSame(
      [
        NestedOptionMap::OPTION_DEFAULT => TRUE,
        NestedOptionMap::OPTION_ACCESS => FALSE,
      ],
      $options->toArray()['heading~title'],
    );
  }

  /**
   * A non-editable sub-prop is hidden only if the Hide box is also ticked.
   */
  public function testNonEditableSubPropHidesOnlyWhenAlsoAskedTo(): void {
    $options = $this->onShapeInit(['title_edit' => FALSE, 'title_empty' => TRUE]);

    $this->assertTrue($options->get('title', NestedOptionMap::OPTION_EMPTY));
  }

  /**
   * A setting for one sub-prop never reaches its siblings.
   *
   * The settings are a flat `{sub-prop}_{setting}` namespace, so a slip in the
   * key interpolation leaks one sub-prop's configuration onto another.
   */
  public function testSettingsDoNotLeakBetweenSubProps(): void {
    $options = $this->onShapeInit(['supertitle_edit' => FALSE]);

    $this->assertTrue($options->get('supertitle', NestedOptionMap::OPTION_DEFAULT));
    $this->assertFalse($options->get('title', NestedOptionMap::OPTION_DEFAULT));
    $this->assertFalse($options->get('subtitle', NestedOptionMap::OPTION_DEFAULT));
  }

  /**
   * Both of the size settings pin the size, and only one withdraws access.
   *
   * "Default" and "not editable" are separate switches; the second implies the
   * first, which is why the provider used to write the same option twice.
   */
  public function testTheSizeSettingsPinItAndOnlyOneWithdrawsAccess(): void {
    $defaulted = $this->onShapeInit(['size_default' => TRUE]);
    $locked = $this->onShapeInit(['size_edit' => FALSE]);

    $this->assertSame(
      [NestedOptionMap::OPTION_DEFAULT => TRUE],
      $defaulted->toArray()['heading~size'],
    );
    $this->assertSame(
      [
        NestedOptionMap::OPTION_DEFAULT => TRUE,
        NestedOptionMap::OPTION_ACCESS => FALSE,
      ],
      $locked->toArray()['heading~size'],
    );
  }

  /**
   * A title nobody can edit takes the anchor with it.
   *
   * The anchor is derived from the title, so an editor who cannot change the
   * title has nothing to say about the anchor either.
   */
  public function testAnUneditableTitleTakesTheAnchorWithIt(): void {
    $options = $this->onShapeInit(['title_edit' => FALSE]);

    $this->assertTrue($options->get('anchor', NestedOptionMap::OPTION_DEFAULT));
    $this->assertFalse($options->get('anchor', NestedOptionMap::OPTION_ACCESS));
  }

  /**
   * A title someone can still edit leaves the anchor alone.
   *
   * Sourcing the title writes the fallback layer, and the mid-method read that
   * decides the anchor's fate sees the saved layer only. The two layers being
   * distinguishable is what keeps a still-editable title from freezing the
   * anchor beneath it.
   */
  public function testSourcedButEditableTitleLeavesTheAnchorAlone(): void {
    $options = $this->onShapeInit(['title_page' => TRUE]);

    $this->assertArrayNotHasKey('heading~anchor', $options->toArray());
  }

  /**
   * Only the title drives the anchor; its siblings do not.
   */
  public function testOnlyTheTitleDrivesTheAnchor(): void {
    $options = $this->onShapeInit(['supertitle_edit' => FALSE, 'subtitle_edit' => FALSE]);

    $this->assertArrayNotHasKey('heading~anchor', $options->toArray());
  }

  /**
   * The Hide setting locks the heading itself rather than a sub-prop.
   */
  public function testHideLocksTheHeadingItself(): void {
    $options = $this->onShapeInit(['hide' => TRUE]);

    $this->assertTrue($this->emptyOption->isLocked());
    $this->assertSame([], $options->toArray(), 'Hiding the heading says nothing about its sub-props.');
  }

  /**
   * A shape with no children is left entirely alone.
   *
   * The provider is offered on any `heading`-ref prop, and one that resolved to
   * a plain shape has no sub-props for these settings to mean anything about.
   */
  public function testShapeWithoutChildrenIsLeftAlone(): void {
    $options = $this->onShapeInit(['hide' => TRUE, 'title_edit' => FALSE], FALSE);

    $this->assertSame([], $options->toArray());
    $this->assertNull($this->emptyOption->isLocked());
  }

  /**
   * The provider's form hides the anchor when the title cannot be edited.
   *
   * Same read as the anchor branch above, one request later: the form is built
   * after init, and asks the map rather than re-deriving it from settings.
   */
  public function testTheFormHidesTheAnchorWhenTheTitleIsUneditable(): void {
    $element = $this->alterForm($this->onShapeInit(['title_edit' => FALSE]));

    $this->assertFalse($element['anchor']['#access']);
  }

  /**
   * The form leaves the anchor visible while the title is still editable.
   */
  public function testTheFormLeavesTheAnchorVisibleForAnEditableTitle(): void {
    $element = $this->alterForm($this->onShapeInit([]));

    $this->assertArrayNotHasKey('#access', $element['anchor']);
  }

}
