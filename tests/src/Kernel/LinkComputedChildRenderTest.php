<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins that a link's computed children survive a producer that maps neither.
 *
 * ChildOptionPolicyCrossBaseTest made StructuredObjectShapeBase honour a
 * producer's hide flag, which was right, and opened a hole one step further
 * down. A hidden child resolves through ComponentShapePluginBase::getValue(),
 * which returns a bare `[]` before ::buildRenderValue() can run — so neither
 * StringShape nor BooleanShape gets to coerce it — and ::buildValue() wrote
 * that `[]` into a slot the prop-def types as `string` (`icon`) or `boolean`
 * (`access`). SDC's ComponentValidator rejects both on sight and the page is
 * replaced by a stack trace:
 *
 *   [front:x/pw_link.icon]   Array value found, but a string is required.
 *   [front:x/pw_link.access] Array value found, but a boolean is required.
 *
 * Two things had to be true for that to reach a live site, and this class pins
 * both. `access` and `options` are computed from the URL rather than authored,
 * so a producer is no longer offered them and cannot flag them — see
 * LinkShape::getComputedChildShapeNames(). And whatever a child resolves to,
 * ::buildValue() drops the key rather than writing an empty over a typed slot.
 *
 * The reason the defect shipped is worth stating, because it is the shape of
 * the test rather than of the code: ChildOptionPolicyCrossBaseTest asserts on
 * getPropValues() and never renders, and `[]` reads as empty. Every assertion
 * there passed while the page white-screened. So this class renders.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\StructuredObjectShapeBase::buildValue()
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\LinkShape::getComputedChildShapeNames()
 * @see \Drupal\Tests\neo_alchemist\Kernel\ChildOptionPolicyCrossBaseTest
 */
#[Group('neo_alchemist')]
class LinkComputedChildRenderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
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
    EntityTest::create(['name' => 'ENTITY NAME'])->save();
  }

  /**
   * A producer on a link prop, mapping the uri and nothing else.
   *
   * The configuration a site builder writes when the component only needs a
   * destination — and the one that used to take the page down. `entity_load`
   * is the provider because it is the only children-match provider applicable
   * to a link prop: the query, reference and views providers all require an
   * iterable or expandable shape, and a link is neither.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The reloaded component.
   */
  private function buildComponent(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'Link computed child fixture',
      'description' => 'Link computed child fixture',
      'component' => 'neo_alchemist_test:na_falsy_object',
      'status' => TRUE,
      'target_entity_type' => 'entity_test',
    ]);
    $component->save();
    $id = $component->id();

    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id)
      ->set('settings.props.link.plugins.link', [
        'entity_load' => [
          'id' => 'entity_load',
          'settings' => [
            'entity_type' => 'entity_test',
            'entity_id' => 1,
            'shape_fields' => [
              'uri' => ['field' => '_raw:string', 'string' => 'https://mapped.example.com'],
              'title' => ['field' => ''],
            ],
          ],
        ],
      ])
      ->save();
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);
    return $component;
  }

  /**
   * The values that reach the rendered component.
   *
   * @return array
   *   The prop values.
   */
  private function propValues(): array {
    return $this->buildComponent()->getPropValues();
  }

  /**
   * The mapped child resolves, so the assertions below mean something.
   */
  public function testMappedChildResolves(): void {
    $this->assertSame('https://mapped.example.com', $this->propValues()['link']['uri'] ?? NULL);
  }

  /**
   * No child resolves to an array, whatever else it resolves to.
   *
   * The assertion the crash reduces to, and the one assertEmpty() cannot make:
   * `[]` is empty, so the cross-base test's form of this passes on the broken
   * value. Only the type separates the two.
   *
   * Absent is a correct outcome and an array is not. `icon` is authorable, so
   * leaving it unmapped hides it and the key is dropped — the same treatment
   * `title` gets, and SDC marks neither required, so nothing is left to
   * validate. What SDC rejects is a key that is present holding the wrong type.
   */
  public function testNoLinkChildResolvesToAnArray(): void {
    $link = $this->propValues()['link'];

    foreach (['icon', 'access', 'title', 'target', 'uri'] as $child) {
      $this->assertIsNotArray(
        $link[$child] ?? NULL,
        "A hidden `$child` must not reach SDC as an array; that is the white-screen.",
      );
    }
  }

  /**
   * Access is a real boolean, present, whatever the producer did.
   *
   * The one computed child a template reads as a decision rather than as
   * content, so it is the one that has to survive as its declared type rather
   * than merely as "not an array". `options` needs no equivalent: it is typed
   * `object`, so an empty one is dropped like any other empty child, and
   * TwigExtension::getUrl() normalises the resulting NULL back to [] —
   * "templates commonly pass a value that does not exist" is its own comment.
   */
  public function testAccessResolvesAsBoolean(): void {
    $this->assertIsBool($this->propValues()['link']['access'] ?? NULL);
  }

  /**
   * An unmapped `access` means allowed, not hidden.
   *
   * `access` is computed from the URL and there is no field to point it at, so
   * leaving it unmapped is the only answer a site builder can give. Reading
   * that as "hidden" made every `{% if link.access %}` guard — the pattern
   * every scaffolded template ships — suppress the link it was written to
   * protect.
   */
  public function testUnmappedAccessMeansAllowed(): void {
    $this->assertTrue($this->propValues()['link']['access'] ?? NULL);
  }

  /**
   * An unmapped authorable child is still hidden.
   *
   * The guard against over-correcting. `title` can be mapped, so leaving it
   * unmapped is a choice, and honouring that choice is what
   * ChildOptionPolicyCrossBaseTest exists to pin. Without this assertion the
   * fix above could be "restore every schema default", which would put the
   * component author's `EXAMPLE LINK TITLE` back on the live page.
   */
  public function testUnmappedAuthorableChildIsStillHidden(): void {
    $this->assertEmpty($this->propValues()['link']['title'] ?? NULL);
  }

  /**
   * The component renders, which is the assertion that was missing.
   *
   * SDC validates props from inside the compiled template, so nothing short of
   * rendering reaches ComponentValidator. Before the fix this threw
   * InvalidComponentException and every other assertion in this class still
   * passed.
   */
  public function testComponentRendersWithoutFailingPropValidation(): void {
    $build = $this->buildComponent()->toRenderable();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $this->assertStringContainsString('https://mapped.example.com', $html);
  }

  /**
   * Assertions are on, so the render above actually validated anything.
   *
   * Core wraps prop validation in `assert()`
   * (ComponentsTwigExtension::validateProps()), so under zend.assertions=0 the
   * render test passes on a value SDC would have rejected — silently, which is
   * the one failure mode this class cannot afford.
   */
  public function testAssertionsAreEnabled(): void {
    $this->assertSame('1', ini_get('zend.assertions'), 'Prop validation is compiled out without this.');
    $this->assertSame('1', ini_get('assert.exception'), 'A failed validation has to throw, not warn.');
  }

}
