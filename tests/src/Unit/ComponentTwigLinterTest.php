<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\ComponentTwigLinter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A lint that cries wolf is worse than no lint at all.
 *
 * `neo:alchemist:validate` reports warnings and still exits 0, so a detector
 * that over-matches never breaks a build — it teaches people to skim past the
 * whole command, taking the real finding on the next line with it. Two
 * regressions on record, both of that shape, and both pinned below:
 *
 * - Declared **slots** and `{% macro %}` parameters were absent from the
 *   safe-list, so every slotted component and every template that factored
 *   markup into a macro reported phantom "undeclared prop" warnings. One real
 *   header emitted four in a row.
 * - The mismatched-tag detector captured its condition with `(.+?)` under
 *   `/s`, which happily ran past `%}` and swallowed the rest of the file — so
 *   a correct template was reported as broken, with the whole template quoted
 *   back as the "condition".
 *
 * Everything here is a pure string function, so the service is constructed
 * directly with no container.
 *
 * @see \Drupal\neo_alchemist\ComponentTwigLinter
 */
#[Group('neo_alchemist')]
final class ComponentTwigLinterTest extends UnitTestCase {

  /**
   * The linter under test.
   */
  private ComponentTwigLinter $linter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->linter = new ComponentTwigLinter();
  }

  /**
   * A declared slot is not an undeclared prop.
   */
  public function testSlotsAreNotUndeclared(): void {
    $twig = '{% if content %}<div>{% block content %}{% endblock %}</div>{% endif %}';
    $findings = $this->linter->lint($twig, NULL, [], ['content']);
    $this->assertSame([], $this->checks($findings, 'undeclared_var'));

    // …but with the slot undeclared it is still reported.
    $findings = $this->linter->lint($twig, NULL, [], []);
    $this->assertNotSame([], $this->checks($findings, 'undeclared_var'));
  }

  /**
   * Macro parameters are locals, not props.
   */
  public function testMacroParamsAreNotUndeclared(): void {
    $twig = <<<'TWIG'
    {% macro nav(items, level) %}
      {% for item in items %}
        {% if level == 0 %}<span>{{ item.title }}</span>{% endif %}
      {% endfor %}
    {% endmacro %}
    TWIG;
    $this->assertSame(
      ['items', 'level'],
      $this->linter->macroParams('{% macro nav(items, level) %}'),
    );
    $findings = $this->linter->lint($twig, NULL, [], []);
    $this->assertSame([], $this->checks($findings, 'undeclared_var'));
  }

  /**
   * A macro parameter carrying a default value is still a parameter.
   */
  public function testMacroParamDefaultsAreStripped(): void {
    $this->assertSame(
      ['items', 'level'],
      $this->linter->macroParams('{% macro nav(items, level = 0) %}'),
    );
  }

  /**
   * A genuine typo is still reported.
   */
  public function testUndeclaredVarIsReported(): void {
    $findings = $this->linter->lint('{% if lnik %}x{% endif %}', NULL, ['link'], []);
    $this->assertCount(1, $this->checks($findings, 'undeclared_var'));
  }

  /**
   * The condition capture must not run past the end of its own tag.
   *
   * The regression: `(.+?)` under `/s` matched across `%}` until it found a
   * `%}` that happened to be followed by `<a`, so the captured "condition"
   * was most of the template and two identical conditions compared unequal.
   */
  public function testMatchingTagConditionsAreNotReported(): void {
    $twig = <<<'TWIG'
    {% if heading or description %}
      <div class="head">{{ heading.title }}</div>
    {% endif %}
    {% for item in items %}
      {% if item.link and item.link.access %}
        <a class="card" href="{{ neo_uri(item.link.uri) }}" target="{{ item.link.target }}">
      {% else %}
        <div class="card">
      {% endif %}
        <span>{{ item.text }}</span>
      {% if item.link and item.link.access %}
        </a>
      {% else %}
        </div>
      {% endif %}
    {% endfor %}
    TWIG;
    $this->assertSame([], $this->linter->mismatchedTagConditions($twig));
  }

  /**
   * An open and close that disagree produce mismatched markup.
   */
  public function testMismatchedTagConditionsAreReported(): void {
    $twig = <<<'TWIG'
    {% if item.link and item.link.access %}
      <a href="{{ neo_uri(item.link.uri) }}" target="{{ item.link.target }}">
    {% else %}
      <div>
    {% endif %}
      <span>{{ item.text }}</span>
    {% if item.link %}
      </a>
    {% else %}
      </div>
    {% endif %}
    TWIG;
    $found = $this->linter->mismatchedTagConditions($twig);
    $this->assertCount(1, $found);
    $this->assertSame('a', $found[0]['tag']);
    $this->assertSame('item.link and item.link.access', $found[0]['open']);
    $this->assertSame('item.link', $found[0]['close']);
  }

  /**
   * An <a> with no {% else %} branch is not a conditional tag pair.
   */
  public function testUnconditionalAnchorIsIgnored(): void {
    $twig = '{% if heading.anchor %}<a name="{{ heading.anchor }}"></a>{% endif %}';
    $this->assertSame([], $this->linter->mismatchedTagConditions($twig));
  }

  /**
   * A self-closed anchor earlier in the file must not become the open tag.
   *
   * The regression: the tag-body alternation allowed `{% … %}` to contain
   * anything, so from the heading's `<a name=…></a>` the body ran on through
   * `{% endif %}` and the whole carousel until it reached a `>` followed by
   * `{% else %}`. That paired the heading anchor's condition against the
   * card's, and reported a correct template as mismatched.
   */
  public function testEarlierAnchorIsNotPairedWithLaterTag(): void {
    $twig = <<<'TWIG'
    {% if heading.anchor %}<a name="{{ heading.anchor }}" title="{{ heading.title }}"></a>{% endif %}
    <div{{ heading.size }}><h2>{{ heading.title }}</h2></div>
    {% for item in items %}
      {% if item.link and item.link.access %}
        <a class="card" href="{{ neo_uri(item.link.uri) }}" target="{{ item.link.target }}">
      {% else %}
        <div class="card">
      {% endif %}
        <span>{{ item.text }}</span>
      {% if item.link and item.link.access %}
        </a>
      {% else %}
        </div>
      {% endif %}
    {% endfor %}
    TWIG;
    $this->assertSame([], $this->linter->mismatchedTagConditions($twig));
  }

  /**
   * A `spacing` prop with no `gap` prop is the pre-`gap` carrier.
   */
  public function testLegacySectionCarrierIsReported(): void {
    $yml = ['props' => ['properties' => ['spacing' => ['type' => 'spacing']]]];
    $twig = '<div{{ attributes }}><div class="container-content py-component">x</div></div>';
    $findings = $this->linter->lint($twig, $yml, ['spacing'], []);
    $messages = $this->messages($findings, 'legacy_section_carrier');
    $this->assertCount(1, $messages);
    // The hand-written carrier is named, so the fix is obvious.
    $this->assertStringContainsString('py-component', $messages[0]);
  }

  /**
   * A migrated component reports nothing.
   */
  public function testGapPropSatisfiesTheCarrierCheck(): void {
    $yml = [
      'props' => [
        'properties' => [
          'spacing' => ['type' => 'spacing'],
          'gap' => ['type' => 'gap'],
        ],
      ],
    ];
    $twig = '<div{{ attributes }}><div class="container-content">x</div></div>';
    $findings = $this->linter->lint($twig, $yml, ['spacing', 'gap'], []);
    $this->assertSame([], $this->checks($findings, 'legacy_section_carrier'));
    $this->assertSame([], $this->checks($findings, 'channel_aware_inner_spacing'));
  }

  /**
   * A component with no `spacing` prop is not a stacking section.
   */
  public function testNonSectionIsNotAskedForGap(): void {
    $yml = ['props' => ['properties' => ['title' => ['type' => 'string']]]];
    $findings = $this->linter->lint('<div>x</div>', $yml, ['title'], []);
    $this->assertSame([], $this->checks($findings, 'legacy_section_carrier'));
  }

  /**
   * On a migrated component a base-size carrier is channel-aware inner spacing.
   */
  public function testChannelAwareInnerSpacingIsReported(): void {
    $yml = [
      'props' => [
        'properties' => [
          'spacing' => ['type' => 'spacing'],
          'gap' => ['type' => 'gap'],
        ],
      ],
    ];
    $twig = '<div{{ attributes }}><div class="mt-component">x</div></div>';
    $findings = $this->linter->lint($twig, $yml, ['spacing', 'gap'], []);
    $this->assertCount(1, $this->checks($findings, 'channel_aware_inner_spacing'));

    // component-spacing-reset is the documented immunisation.
    $safe = '<div{{ attributes }}><div class="component-spacing-reset"><div class="mt-component">x</div></div></div>';
    $findings = $this->linter->lint($safe, $yml, ['spacing', 'gap'], []);
    $this->assertSame([], $this->checks($findings, 'channel_aware_inner_spacing'));
  }

  /**
   * The relative size variants are immune and must not be flagged.
   */
  public function testRelativeSpacingVariantsAreNotCarriers(): void {
    $this->assertSame([], $this->linter->channelSpacingUtilities('<div class="mt-component-sm py-component-lg">x</div>'));
    $this->assertSame(['py-component'], $this->linter->channelSpacingUtilities('<div class="py-component">x</div>'));
  }

  /**
   * The title is optional, so printing it unguarded emits an empty tag.
   */
  public function testUnguardedHeadingTitleIsReported(): void {
    $props = ['heading' => ['type' => 'heading']];
    $twig = '<h2>{{ heading.title }}</h2>';
    $this->assertSame(['heading'], $this->linter->unguardedHeadingTitles($twig, $props));
  }

  /**
   * Guarding the title anywhere is taken as the author having considered it.
   */
  public function testGuardedHeadingTitleIsNotReported(): void {
    $props = ['heading' => ['type' => 'heading']];
    $twig = <<<'TWIG'
    {% if heading.anchor %}<a name="{{ heading.anchor }}" title="{{ heading.title }}"></a>{% endif %}
    {% if heading.title %}<h2>{{ heading.title }}</h2>{% endif %}
    TWIG;
    $this->assertSame([], $this->linter->unguardedHeadingTitles($twig, $props));
  }

  /**
   * A non-heading prop named `title` is not a heading shape.
   */
  public function testOnlyHeadingShapesAreChecked(): void {
    $props = ['heading' => ['type' => 'string']];
    $this->assertSame([], $this->linter->unguardedHeadingTitles('<h2>{{ heading.title }}</h2>', $props));
  }

  /**
   * The addClass() helper takes one array; extras are dropped silently.
   */
  public function testMultiArgAddClassIsReported(): void {
    $this->assertTrue($this->linter->multiArgAddClass("{{ attributes.addClass('a', 'b') }}"));
  }

  /**
   * The correct forms must not be flagged.
   */
  public function testCorrectAddClassFormsAreNotReported(): void {
    $this->assertFalse($this->linter->multiArgAddClass("{{ attributes.addClass(['a', 'b']) }}"));
    $this->assertFalse($this->linter->multiArgAddClass('{{ attributes.addClass(classes) }}'));
    $this->assertFalse($this->linter->multiArgAddClass("{{ attributes.addClass('a') }}"));
  }

  /**
   * A bg-base-0 section root silently keeps the doubled gap.
   */
  public function testNonCollapsingSurfaceIsReported(): void {
    $twig = "{% set classes = ['bg-base-0', 'component-bg'] %}<div{{ attributes.addClass(classes) }}></div>";
    $this->assertSame(['bg-base-0'], $this->linter->nonCollapsingSurfaces($twig));
  }

  /**
   * The same token on an inner element is a card, not a section surface.
   *
   * Only a class group that also carries the `component-bg` marker is a
   * background section; a card painting bg-base-0 inside one never took part
   * in seam collapsing and is not a finding.
   */
  public function testNonCollapsingSurfaceOffTheRootIsIgnored(): void {
    $twig = <<<'TWIG'
    {% set classes = ['bg-default', 'component-bg'] %}
    <div{{ attributes.addClass(classes) }}><div class="card bg-base-0">x</div></div>
    TWIG;
    $this->assertSame([], $this->linter->nonCollapsingSurfaces($twig));
  }

  /**
   * Foreground tokens are only readable over their own paired background.
   */
  public function testOrphanContentTokenIsReported(): void {
    $twig = '<form class="bg-accent-900"><input class="text-base-900-content"></form>';
    $found = $this->linter->orphanContentTokens($twig);
    $this->assertCount(1, $found);
    $this->assertSame('text-base-900-content', $found[0]['token']);
    $this->assertSame('bg-base-900', $found[0]['background']);
  }

  /**
   * A correctly paired token is not a finding.
   */
  public function testPairedContentTokenIsNotReported(): void {
    $twig = '<div class="bg-accent-500 text-accent-500-content">x</div>';
    $this->assertSame([], $this->linter->orphanContentTokens($twig));
  }

  /**
   * A variant prefix does not hide the pairing.
   */
  public function testPrefixedContentTokenResolvesItsBackground(): void {
    $twig = '<div class="bg-primary text-primary-content placeholder:text-primary-content">x</div>';
    $this->assertSame([], $this->linter->orphanContentTokens($twig));
  }

  /**
   * Twig comments never apply a class, so documentation must not trip a check.
   */
  public function testCommentsAreIgnored(): void {
    $twig = '{# Never write bg-base-0 with component-bg, or py-component. #}<div class="bg-default">x</div>';
    $this->assertSame([], $this->linter->nonCollapsingSurfaces($twig));
    $this->assertSame([], $this->linter->channelSpacingUtilities($twig));
  }

  /**
   * Existing detectors keep working after the move off the command class.
   */
  public function testMovedDetectorsStillWork(): void {
    $this->assertTrue($this->linter->hasDynamicClasses('<div class="bg-{{ color }}-500">'));
    $this->assertFalse($this->linter->hasDynamicClasses('<div class="bg-primary">'));
    $this->assertSame(
      ['text-accent-500', 'hover:bg-primary-600'],
      $this->linter->numberedRoleShadeClasses('<div class="text-accent-500 hover:bg-primary-600 text-primary">'),
    );
    // Base is scheme-scoped and deliberately not matched.
    $this->assertSame([], $this->linter->numberedRoleShadeClasses('<div class="bg-base-100">'));
    $this->assertSame(
      ['item.link'],
      $this->linter->hrefsWithoutTarget('<a href="{{ neo_uri(item.link.uri, item.link.options) }}">x</a>'),
    );
    $this->assertSame(
      [],
      $this->linter->hrefsWithoutTarget('<a href="{{ neo_uri(item.link.uri) }}" target="{{ item.link.target }}">x</a>'),
    );
  }

  /**
   * Returns the findings matching a check id.
   *
   * @param array $findings
   *   Findings as returned by lint().
   * @param string $check
   *   The check id to filter on.
   *
   * @return array
   *   The matching findings.
   */
  private function checks(array $findings, string $check): array {
    return array_values(array_filter(
      $findings,
      static fn (array $f): bool => $f['check'] === $check,
    ));
  }

  /**
   * Returns the messages of the findings matching a check id.
   *
   * @param array $findings
   *   Findings as returned by lint().
   * @param string $check
   *   The check id to filter on.
   *
   * @return string[]
   *   The matching messages.
   */
  private function messages(array $findings, string $check): array {
    return array_map(
      static fn (array $f): string => $f['message'],
      $this->checks($findings, $check),
    );
  }

}
