<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Template\Attribute;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * An editor's "open link in new window" survives to the resolved link value.
 *
 * The two halves of that checkbox live under different keys. The Neo Link
 * widget stores it as `options.attributes.target` (NeoLinkWidget's
 * ::massageFormValues() normalises the checked box to '_blank'), while a
 * template reads it as a top-level `target` — that is what the shape's
 * generated Twig snippet renders into `target="{{ link.target }}"`. Something
 * has to move the value across, and UrlShapeTrait::preRenderValue() did it
 * under `if (empty($value['target']))`.
 *
 * That guard is never true for a `link` prop. LinkShape extends
 * StructuredObjectShapeBase, whose ::buildValue() backfills the schema defaults
 * (`$value += $this->getDefaultSchemaValue()`) — and the trait's
 * ::getDefaultSchemaValue() hard-sets `target` to '_self'. Backfill runs before
 * pre-render, so by the time the lift was attempted `target` was already
 * populated, the authored `_blank` was skipped, and the two lines that follow
 * threw the evidence away with `unset($value['options']['attributes'])`. Every
 * link an editor flagged opened in the same tab, silently and with nothing
 * logged.
 *
 * Both halves of the fix are pinned here: the lift now happens in
 * LinkShape::adaptValue(), ahead of that backfill, and the enum guard that
 * follows it still normalises anything invalid to '_self' rather than letting
 * SDC prop validation white-screen the page.
 *
 * Scalar `url` props were never affected — ComponentShapePluginBase's own
 * ::buildValue() does no such backfill — which is why this is a LinkShape test.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\LinkShape::adaptValue()
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\UrlShapeTrait::liftTargetFromOptions()
 */
#[Group('neo_alchemist')]
class LinkTargetFromOptionsTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // LinkShape's default field type is `link`, so the prop cannot build a
    // field item without the module that supplies it.
    'link',
    // `target` declares an enum, so its child shape takes the with-options
    // field type — `list_string`, which the options module supplies.
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * Resolves the fixture's link prop with the given authored link options.
   *
   * @param array $options
   *   The link item's `options`, as the widget would have stored them.
   *
   * @return array
   *   The resolved link value.
   */
  private function resolveLink(array $options): array {
    return $this->resolveProp('link', ['options' => $options]);
  }

  /**
   * Resolves one of the fixture's props from an authored field-item value.
   *
   * The value is staged as a preview override, which is the config-scope way to
   * author a value with no host entity behind it. It is shaped like a real
   * `link` field item — uri/title/options — because that is what the widget
   * saves and therefore what the shape has to read.
   *
   * ::getPropValue() rather than ::getValue(): it is the only entry point that
   * runs the pre-render stage, which is where the enum guard lives.
   *
   * @param string $prop
   *   The fixture prop to resolve — 'link' or 'url'. Both share UrlShapeTrait
   *   but reach it through different parent classes.
   * @param array $value
   *   The authored field-item value, merged over a valid uri/title baseline.
   *
   * @return array
   *   The resolved value.
   */
  private function resolveProp(string $prop, array $value): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_link_probe')) {
      Component::create([
        'id' => 'na_link_probe',
        'label' => 'Link fixture',
        'description' => 'Link fixture',
        'component' => 'neo_alchemist_test:na_link_probe',
        'status' => TRUE,
      ])->save();
    }
    $storage->resetCache(['na_link_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_link_probe');

    $component->setPreview(TRUE);
    $this->setPreviewValues($component, [
      'props' => [
        $prop => [
          'ref' => $prop,
          'value' => $value + [
            'uri' => 'internal:/',
            'title' => 'Authored',
            'options' => [],
          ],
          'options' => [$prop => ['default' => 0]],
        ],
      ],
    ]);

    return $component->getPropShapes()[$prop]->getPropValue(new Attribute());
  }

  /**
   * A flagged link resolves to `_blank`, so the template opens a new window.
   *
   * This is the regression. Asserted on the resolved value rather than on
   * rendered markup because the value is the shape's contract with every
   * template — the theme's search_s1 renders it directly, and the snippet
   * `drush neo:alchemist:shapes link` generates does the same.
   */
  public function testFlaggedLinkOpensInNewWindow(): void {
    $value = $this->resolveLink(['attributes' => ['target' => '_blank']]);

    $this->assertSame('_blank', $value['target'] ?? NULL, 'A link an editor flagged "open in new window" must resolve to _blank.');
  }

  /**
   * An unflagged link still resolves to `_self`.
   *
   * The widget writes the unchecked box as `0`, not as an absent key, so this
   * covers the falsy-but-present case as well as the plain absent one.
   */
  public function testUnflaggedLinkStaysInTheSameWindow(): void {
    $this->assertSame('_self', $this->resolveLink(['attributes' => ['target' => 0]])['target'] ?? NULL);
    $this->assertSame('_self', $this->resolveLink([])['target'] ?? NULL);
  }

  /**
   * A target outside the enum is normalised rather than passed through.
   *
   * The enum guard exists because an invalid `target` fails SDC prop validation
   * and takes the whole page down with it. Lifting the widget's value earlier
   * must not let an arbitrary attribute skip that guard — the lift now feeds
   * the value in ahead of it, so this is the case that proves it still runs.
   */
  public function testInvalidTargetFallsBackToSelf(): void {
    $value = $this->resolveLink(['attributes' => ['target' => '_parent']]);

    $this->assertSame('_self', $value['target'] ?? NULL, 'A target outside the schema enum must be normalised, not rendered.');
  }

  /**
   * The raw options attributes do not reach the template.
   *
   * They are widget storage, not render data; the shape reads what it needs out
   * of them and drops the rest. Pinned because the lift moved to a stage that
   * runs before that unset, and a lift that also left the attributes behind
   * would hand templates two competing sources for the same choice.
   */
  public function testOptionsAttributesAreStrippedFromTheRenderValue(): void {
    $value = $this->resolveLink(['attributes' => ['target' => '_blank']]);

    $this->assertArrayNotHasKey('attributes', $value['options'] ?? [], 'Widget storage attributes are not render data.');
  }

  /**
   * A `url` with no link text resolves to '', not to NULL.
   *
   * A `link` field's title column is nullable and every link-shaped prop-def
   * types `title` as `string`, so a title-less link handed straight to SDC
   * fails prop validation and white-screens the whole page. That is the common
   * case, not an edge one: UrlShape disables the widget's title input outright,
   * so a `url` prop bound to an entity's link field arrives with a NULL title
   * unless the site builder went out of their way to enable one — and a value
   * provider that reads many entities at once takes the page down for every row
   * at once, not just the offending one.
   *
   * This is the exact mirror of the target regression above. There, `link` was
   * the affected shape because StructuredObjectShapeBase::buildValue() backfills
   * the schema examples and `url` gets no such treatment. Here that same
   * backfill is what saves `link`: it array_filter()s the NULL away and puts the
   * component author's example title in its place, so the NULL never reaches
   * validation. `url` has nothing between the field item and SDC but the
   * pre-render stage, which is why the guard has to live there.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentShape\UrlShapeTrait::preRenderValue()
   * @see \Drupal\neo_alchemist\Plugin\ComponentShape\StructuredObjectShapeBase::buildValue()
   */
  public function testTitlelessUrlResolvesToAnEmptyString(): void {
    $value = $this->resolveProp('url', ['title' => NULL]);

    $this->assertSame('', $value['title'] ?? NULL, "A title-less `url` must resolve to '' — a NULL fails SDC prop validation.");
  }

  /**
   * An authored title is left alone by that normalisation.
   *
   * The guard has to be narrow: blanking a real title would silently strip the
   * link text off every link on the site, which is a worse failure than the one
   * it fixes because nothing would error. Asserted on both shapes because the
   * guard sits in the trait they share.
   */
  public function testAuthoredTitleSurvives(): void {
    foreach (['link', 'url'] as $prop) {
      $value = $this->resolveProp($prop, ['title' => 'Directions']);

      $this->assertSame('Directions', $value['title'] ?? NULL, "An authored `$prop` title must reach the template unchanged.");
    }
  }

}
