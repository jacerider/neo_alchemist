<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Template\Attribute;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Plugin\ComponentShape\HeadingShape;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * A heading with no text reports itself empty.
 *
 * A `heading` carries content in `supertitle`, `title` and `subtitle` only. Its
 * other two children are presentation: `size` ALWAYS resolves — it falls back
 * to `md` whether or not an editor ever opened the component — and `anchor` is
 * a link target rather than text. Because `size` is always there, the resolved
 * value was always a non-empty array, so `{% if heading %}` in a template was
 * true for every heading ever declared, including one with nothing in it. The
 * visible symptom is a wrapper rendered around no text:
 *
 * @code
 * <div class="title-md mb-4"></div>
 * @endcode
 *
 * whose spacing classes are live, so an unfilled heading still pushes down
 * whatever follows it. Template authors could only avoid it by knowing to test
 * the three text keys by hand, and the shape's own generated Twig snippet
 * (`drush neo:alchemist:shapes heading`) told them otherwise.
 *
 * The fix is expressed as a contract rather than a special case:
 * ComponentShapePluginBase::getPresentationalValueKeys() names the keys that do
 * not count as content — `size` by default, for the media image size modifier
 * — and HeadingShape adds `anchor`. ::isProvidedValueEmpty() already consulted
 * that idea (it was a hardcoded `unset($value['size'])`), and HeadingShape now
 * hands Twig an empty value when the contract says the heading is empty.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\HeadingShape::preRenderValue()
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::getPresentationalValueKeys()
 */
#[Group('neo_alchemist')]
class HeadingEmptyValueTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The heading's `size` sub-prop is a StyleShape backed by `list_string`,
    // which the options module supplies. Without it the whole prop fails to
    // build, long before any of this is reached.
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The sub-props that actually carry content.
   */
  private const TEXT_KEYS = ['supertitle', 'title', 'subtitle'];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // HeadingShape memoises the anchor-override setting in a static, which
    // outlives a single test method — a case that forced it would otherwise
    // decide what every later test in this process resolves. Put back the
    // value the config would produce on the next read, which is exactly what
    // an untouched static computes on first use.
    $settings = $this->container->get('neo_alchemist.settings')->getActive();
    (new \ReflectionProperty(HeadingShape::class, 'allowAnchorOverride'))
      ->setValue(NULL, $settings ? (bool) $settings->getValue('anchor_override_status') : TRUE);
    parent::tearDown();
  }

  /**
   * Builds the heading fixture with the given authored sub-prop values.
   *
   * Every case runs on `na_heading_empty`, whose heading declares
   * `examples: {}` the way a real component ships an optional section heading
   * (the theme's list_s4 does exactly this). That matters: a heading's
   * emptiness is decided by its schema as much as by its authored value, and a
   * fixture carrying text examples cannot express "nobody filled this in" —
   * the parent shape falls back to those examples and ChildrenShapeBase pushes
   * them down into the children. Starting from a genuinely empty heading also
   * makes "a heading carrying ONLY a supertitle" literally true rather than
   * approximately true.
   *
   * Authored values are staged as preview overrides; the seeded `default => 0`
   * option is what stops a child from falling back to a default instead of
   * taking the staged value.
   *
   * @param string $id
   *   The fixture component id.
   * @param array $children
   *   Authored child values keyed by sub-prop name.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The component, in preview scope with those values staged.
   */
  private function buildComponent(string $id, array $children = []): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load($id)) {
      Component::create([
        'id' => $id,
        'label' => 'Heading fixture',
        'description' => 'Heading fixture',
        'component' => 'neo_alchemist_test:' . $id,
        'status' => TRUE,
      ])->save();
    }
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);

    $component->setPreview(TRUE);
    if ($children) {
      $value = [];
      $options = [];
      foreach ([...self::TEXT_KEYS, 'anchor'] as $key) {
        $options["heading~{$key}"] = ['default' => 0];
        if (array_key_exists($key, $children)) {
          $value[$key] = ['value' => $children[$key]];
        }
      }
      $this->setPreviewValues($component, [
        'props' => [
          'heading' => [
            'ref' => 'heading',
            'value' => $value,
            'options' => $options,
          ],
        ],
      ]);
    }
    else {
      $this->resetPreviewValues($component);
    }

    return $component;
  }

  /**
   * Resolves the heading down the render path.
   *
   * Component::getPropValues() reaches the shape through getPropValue(), the
   * only entry point that runs preRenderValue() — plain getValue() stops
   * short of it. Named resolveHeading() rather than render() because
   * KernelTestBase::render() already exists with a different signature.
   *
   * @param string $id
   *   The fixture component id.
   * @param array $children
   *   Authored child values keyed by sub-prop name.
   *
   * @return array
   *   The resolved value and the attributes the shape wired onto the root.
   */
  private function resolveHeading(string $id, array $children = []): array {
    $attributes = new Attribute();
    $value = $this->buildComponent($id, $children)
      ->getPropShapes()['heading']
      ->getPropValue($attributes);
    return [$value, $attributes];
  }

  /**
   * A heading with no text resolves empty, so `{% if heading %}` is false.
   *
   * This is the whole point of the change. The assertion is deliberately
   * written as the Twig question rather than "=== []": what a template author
   * needs is that the value is falsy, not which flavour of empty it is.
   */
  public function testEmptyHeadingIsFalsyInTwig(): void {
    [$value] = $this->resolveHeading('na_heading_empty');

    $this->assertEmpty($value, 'A heading with no supertitle, title or subtitle must be falsy so a template can guard it with `{% if heading %}`.');
  }

  /**
   * The `size` child alone never makes a heading non-empty.
   *
   * Named separately from the test above because `size` is the specific reason
   * the old behaviour was unavoidable: it resolves to `md` with no editor
   * input at all, so every heading looked authored.
   */
  public function testSizeAloneDoesNotCountAsContent(): void {
    $shape = $this->buildComponent('na_heading_empty')->getPropShapes()['heading'];

    $this->assertTrue(
      $shape->isProvidedValueEmpty(['size' => 'md']),
      'A heading carrying only its always-present `size` is empty.',
    );
  }

  /**
   * An authored anchor does not, by itself, make a heading non-empty.
   *
   * An anchor is where the heading points, not what it says. It still wires
   * the component id — ::testAuthoredAnchorStillWiresTheComponentId() — but it
   * gives a template nothing to render.
   */
  public function testAnchorAloneDoesNotCountAsContent(): void {
    $shape = $this->buildComponent('na_heading_empty')->getPropShapes()['heading'];

    $this->assertTrue(
      $shape->isProvidedValueEmpty(['anchor' => 'somewhere', 'size' => 'md']),
      'An anchor is a link target, not heading text.',
    );
  }

  /**
   * Any one of the three text parts is enough to make a heading render.
   *
   * The title is not privileged: a heading may legitimately be supertitle- or
   * subtitle-only, and collapsing those would silently drop authored text —
   * the exact failure this change exists to prevent, inverted.
   */
  #[DataProvider('textKeyCases')]
  public function testAnyTextPartMakesTheHeadingRender(string $key): void {
    [$value] = $this->resolveHeading('na_heading_empty', [$key => 'AUTHORED']);

    $this->assertNotEmpty($value, "A heading carrying only a {$key} must still render.");
    $this->assertSame('AUTHORED', (string) ($value[$key] ?? ''), "The authored {$key} survived to the render value.");
  }

  /**
   * One case per content-bearing sub-prop.
   *
   * @return array
   *   Cases of [sub-prop name].
   */
  public static function textKeyCases(): array {
    $cases = [];
    foreach (self::TEXT_KEYS as $key) {
      $cases[$key] = [$key];
    }
    return $cases;
  }

  /**
   * A textless heading leaves no anchor wiring on the component root.
   *
   * The shape used to set the root id unconditionally, so an empty heading
   * emitted a bare `id=""` plus a `scroll-mt-neo` offset reserved for a target
   * that does not exist.
   */
  public function testEmptyHeadingWiresNothingOntoTheRoot(): void {
    [, $attributes] = $this->resolveHeading('na_heading_empty');

    $this->assertFalse($attributes->hasAttribute('id'), 'An empty heading must not emit an id.');
    $this->assertFalse($attributes->hasClass('scroll-mt-neo'), 'An empty heading must not reserve a scroll offset.');
    $this->assertFalse($attributes->hasAttribute('data-component-title'), 'An empty heading has no title to advertise.');
  }

  /**
   * A titled heading still gets the free anchor wiring it always had.
   *
   * The guard added around that wiring must not cost a real heading its
   * id/scroll offset — that is what makes any component with a heading
   * anchor-linkable without extra markup.
   */
  public function testTitledHeadingKeepsItsAnchorWiring(): void {
    [$value, $attributes] = $this->resolveHeading('na_heading_empty', ['title' => 'Section One']);

    $this->assertNotEmpty($value);
    $this->assertSame('section-one', $attributes->offsetGet('id')->__toString(), 'The id is still derived from the title.');
    $this->assertTrue($attributes->hasClass('scroll-mt-neo'), 'A real heading still reserves its scroll offset.');
    $this->assertSame('Section One', $attributes->offsetGet('data-component-title')->__toString());
  }

  /**
   * An authored anchor keeps the component linkable even with no heading text.
   *
   * The value still collapses — there is nothing to render — but the id is
   * keyed on the resolved anchor rather than on emptiness, so deliberately
   * anchoring a component does not stop working just because its heading
   * carries no text.
   */
  public function testAuthoredAnchorStillWiresTheComponentId(): void {
    // The anchor sub-prop is only editable when the site allows overriding it;
    // with the override off the anchor is always derived from the title and
    // this case cannot arise.
    (new \ReflectionProperty(HeadingShape::class, 'allowAnchorOverride'))->setValue(NULL, TRUE);

    [$value, $attributes] = $this->resolveHeading('na_heading_empty', ['anchor' => 'my-section']);

    $this->assertEmpty($value, 'An anchor is not content, so there is still no heading to render.');
    $this->assertSame('my-section', $attributes->offsetGet('id')->__toString(), 'The authored anchor still makes the component linkable.');
  }

}
