<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist_search\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers how shapes are classified from what they declare about themselves.
 *
 * @see \Drupal\neo_alchemist\Attribute\ComponentShape::$text_keys
 */
#[Group('neo_alchemist_search')]
final class ShapeTextPolicyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_search',
  ];

  /**
   * The only shapes descended into blindly are the generic containers.
   *
   * A container that declares nothing has every child read, because a child
   * answers for itself — which is right for an object or a list, whose children
   * are whatever a component author put there. It is wrong for a container
   * whose children are furniture: a breadcrumb's or a filter widget's children
   * are strings and links, and each would quite correctly answer that it is
   * text, putting the same route trail into every entity on the site.
   *
   * Such a shape has to say so with `text_keys: FALSE`. This test is here
   * because forgetting is silent — the leak shows up as slightly worse search
   * results, months later, with nothing pointing at the cause.
   */
  public function testOnlyGenericContainersRecurseUndeclared(): void {
    $policy = $this->container->get('neo_alchemist_search.shape_text_policy');
    $manager = $this->container->get('plugin.manager.neo_component_shape');

    $undeclared = [];
    foreach (array_keys($manager->getDefinitions()) as $shapeId) {
      if ($policy->isContainer($shapeId) && $policy->textKeys($shapeId) === NULL) {
        $undeclared[] = $shapeId;
      }
    }
    sort($undeclared);

    $this->assertSame(['array', 'object'], $undeclared, sprintf(
      'These shapes hold children but say nothing about them, so every child is read: %s. '
      . 'Give each either a text_keys allow-list or text_keys: FALSE.',
      implode(', ', $undeclared),
    ));
  }

  /**
   * Chrome containers contribute nothing, and nothing below them either.
   */
  public function testChromeContainersAreBarred(): void {
    $policy = $this->container->get('neo_alchemist_search.shape_text_policy');
    foreach (['breadcrumb', 'views_filter', 'views_active_filters'] as $shapeId) {
      $this->assertTrue($policy->isBarred($shapeId), sprintf('%s must not be descended into.', $shapeId));
      $this->assertNull($policy->textKeys($shapeId));
    }
  }

  /**
   * A shape's ancestry bars it whatever its own declaration says.
   *
   * The net beneath the declarations: style, media and region shapes cannot
   * contribute text even by declaring that they do, so a new colour token or
   * media wrapper cannot leak an id into an index through a careless attribute.
   */
  public function testMarkerInterfacesBarRegardlessOfDeclaration(): void {
    $policy = $this->container->get('neo_alchemist_search.shape_text_policy');
    $manager = $this->container->get('plugin.manager.neo_component_shape');

    $checked = 0;
    foreach (['style', 'scheme', 'image_size', 'image', 'media', 'file', 'region'] as $shapeId) {
      // Some of these need a module this environment does not install.
      if ($manager->getDefinition($shapeId, FALSE) === NULL) {
        continue;
      }
      $checked++;
      $this->assertTrue($policy->isBarred($shapeId), sprintf('%s is barred by its ancestry.', $shapeId));
      $this->assertNull($policy->textKeys($shapeId));
    }
    $this->assertGreaterThan(0, $checked, 'Premise: at least one barred shape was available to check.');
  }

  /**
   * A shape nobody has heard of contributes nothing.
   *
   * The default has to fall this way. A shape can arrive from any module, and
   * one whose value turns out to be a machine name would quietly corrupt
   * relevance for everyone, where a missing string is merely missing.
   */
  public function testUnknownShapeContributesNothing(): void {
    $policy = $this->container->get('neo_alchemist_search.shape_text_policy');

    $this->assertNull($policy->textKeys('a_shape_from_the_future'));
    $this->assertFalse($policy->isContainer('a_shape_from_the_future'));
    // An empty id is what a schema node with neither ref nor type resolves to.
    $this->assertTrue($policy->isBarred(''));
    $this->assertNull($policy->textKeys(''));
  }

  /**
   * The shapes that carry words say so.
   */
  public function testTextBearingShapesDeclareTheirKeys(): void {
    $policy = $this->container->get('neo_alchemist_search.shape_text_policy');

    foreach (['string', 'markup', 'email', 'telephone'] as $shapeId) {
      $this->assertTrue($policy->textKeys($shapeId), sprintf('%s is text throughout.', $shapeId));
    }
    $this->assertSame(['supertitle', 'title', 'subtitle'], $policy->textKeys('heading'));
    $this->assertSame(['title'], $policy->textKeys('link'));
    $this->assertSame(['title'], $policy->textKeys('url'));

    // Only rich text needs stripping before it is words.
    $this->assertTrue($policy->isMarkup('markup'));
    $this->assertFalse($policy->isMarkup('string'));
  }

  /**
   * Machine values are not text, however string-like they look.
   */
  public function testMachineValueShapesDeclareNoText(): void {
    $policy = $this->container->get('neo_alchemist_search.shape_text_policy');
    foreach (['slug', 'uri', 'integer', 'number', 'boolean'] as $shapeId) {
      $this->assertNull($policy->textKeys($shapeId), sprintf('%s holds no words.', $shapeId));
    }
  }

  /**
   * Resolution prefers a shape reference over the schema's own type.
   *
   * A prop can declare `type: string` and still be a machine value — an icon
   * name, an anchor slug — so trusting the type would index the machine name.
   */
  public function testShapeIdPrefersRefOverType(): void {
    $policy = $this->container->get('neo_alchemist_search.shape_text_policy');

    $this->assertSame('slug', $policy->shapeId(['type' => 'string', 'ref' => 'slug']));
    $this->assertSame('string', $policy->shapeId(['type' => 'string']));
    // A stored ref outlives a schema that has moved on.
    $this->assertSame('markup', $policy->shapeId(['type' => 'string', 'ref' => 'string'], 'markup'));
    // A ref naming a shape that no longer exists falls back to the type.
    $this->assertSame('string', $policy->shapeId(['type' => 'string', 'ref' => 'gone_away']));
    // An array type is the first entry of the union.
    $this->assertSame('array', $policy->shapeId(['type' => ['array', 'object']]));
  }

  /**
   * A closed vocabulary is not prose, whatever shape carries it.
   */
  public function testEnumNodesAreRecognised(): void {
    $policy = $this->container->get('neo_alchemist_search.shape_text_policy');

    $this->assertTrue($policy->isEnumNode(['type' => 'string', 'enum' => ['_self', '_blank']]));
    $this->assertTrue($policy->isEnumNode(['type' => 'string', 'styles' => ['primary' => 'Primary']]));
    $this->assertFalse($policy->isEnumNode(['type' => 'string']));
  }

}
