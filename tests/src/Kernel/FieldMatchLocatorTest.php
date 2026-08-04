<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use PHPUnit\Framework\Attributes\Group;

/**
 * What the field picker offers, in what order, and to whom.
 *
 * The picker replaced a pair of `<select>`s whose contents were whatever
 * MatcherField returned, in whatever order. Nothing about that needed pinning
 * because nothing about it was a decision. This layer makes three decisions
 * that do: which matches are offered at all (the access check and the
 * exclusion list), how they rank (an unranked list opens on link templates
 * nobody wants), and how the flat list regroups into a browsable tree.
 *
 * @see \Drupal\neo_alchemist\FieldMatchLocator
 */
#[Group('neo_alchemist')]
class FieldMatchLocatorTest extends FieldMatchKernelTestBase {

  /**
   * A sensitive field type is never offered, in either mode.
   *
   * MatcherField::EXCLUDED_FIELD_TYPES exists because matching works on data
   * types, which are too coarse to tell a password hash from a title. The
   * "offer every field" mode used to skip the check — so a sort key or a
   * "render with field formatter" target could be the password column.
   */
  public function testExcludedFieldTypesAreNeverOffered(): void {
    $shape = $this->titleShape();

    foreach ([FALSE, TRUE] as $all) {
      $keys = array_keys($this->locator()->getMatches($shape, $all));
      $this->assertNotContains('pass', $keys, sprintf('The password field is offered with $all = %s.', var_export($all, TRUE)));
      $this->assertNull(
        $this->locator()->label($shape, 'pass', $all),
        'A password key does not validate as a legal match either.',
      );
    }
    // The mode is otherwise doing its job. It is not a superset — it walks one
    // reference level fewer than shape matching does — but it keeps whole
    // fields a string prop could never consume.
    $unfiltered = array_keys($this->locator()->getMatches($shape, TRUE));
    $matched = array_keys($this->locator()->getMatches($shape, FALSE));
    $this->assertContains('status', $unfiltered, 'A boolean is offered when every field is on the table.');
    $this->assertNotContains('status', $matched, 'But not to a prop that cannot hold it.');
  }

  /**
   * A timestamp field is offered to a string prop.
   *
   * The created/changed/timestamp field types all expose a single `value`
   * property of data type `timestamp`, and these lists are compared by plugin
   * id — so despite Timestamp extending IntegerData in PHP, `integer` in
   * StringShape's supports_field_props did not cover them and "Authored on"
   * could not be bound to a heading supertitle. The value arrives as a raw
   * epoch int (StringShape is the one scalar shape that does not coerce),
   * which is what the `date` value modifier is for.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentShape\StringShape
   * @see \Drupal\neo_alchemist\Plugin\ComponentValue\DateValue
   */
  public function testTimestampFieldsAreOfferedToStringProps(): void {
    $matched = array_keys($this->locator()->getMatches($this->titleShape(), FALSE));

    $this->assertContains('created', $matched, 'Authored on is bindable to a string prop.');
    $this->assertContains('changed', $matched, 'And so is any other timestamp field.');
    // Matched as a whole field, not as a `:value` property suffix — both the
    // shape and the field have exactly one property, so they pair directly.
    $this->assertNotContains('created:value', $matched);
  }

  /**
   * Nothing resolves for a user who cannot edit the component.
   */
  public function testResolveShapeRequiresComponentAccess(): void {
    $this->assertNotNull($this->locator()->resolveShape('na_heading', 'heading', 'heading~title'));

    $this->setUpCurrentUser(['uid' => 2], []);
    $this->assertNull(
      $this->locator()->resolveShape('na_heading', 'heading', 'heading~title'),
      'A user without update access on the component resolves no shape.',
    );
  }

  /**
   * An address that does not exist resolves to nothing rather than guessing.
   */
  public function testResolveShapeRejectsUnknownAddresses(): void {
    $this->assertNull($this->locator()->resolveShape('no_such_component', 'heading', 'heading~title'));
    $this->assertNull($this->locator()->resolveShape('na_heading', 'no_such_prop', 'heading~title'));
    $this->assertNull($this->locator()->resolveShape('na_heading', 'heading', 'heading~nope'));
  }

  /**
   * Search matches every term, against label and reference path together.
   *
   * Single-substring search returned nothing for "roles label", because no
   * one string contains both: the label carries the field, the path carries
   * the reference route that reached it.
   */
  public function testSearchAndsTermsAcrossLabelAndPath(): void {
    $shape = $this->titleShape();

    $this->assertSame(['name'], array_column($this->locator()->search($shape, 'name'), 'value'));

    $keys = array_column($this->locator()->search($shape, 'roles label'), 'value');
    $this->assertContains('roles._entity:label', $keys, 'A term matching the path and a term matching the label combine.');

    $this->assertSame([], $this->locator()->search($shape, 'name zzzz'), 'Terms are ANDed, not ORed.');
  }

  /**
   * An empty query opens on real content, not on plumbing.
   *
   * Half a real site's match list is link templates and revision plumbing, and
   * their labels sort first — "(Link) Canonical" beats "Name" on "(" alone. A
   * picker that opens on that is useless before you type.
   */
  public function testSearchRanksContentAboveSystemAndLinkTemplates(): void {
    $keys = array_column($this->locator()->search($this->titleShape()), 'value');

    $position = static fn (string $needle): int => (int) array_search($needle, $keys, TRUE);
    $firstLink = NULL;
    foreach ($keys as $i => $key) {
      if (str_contains($key, '_entity:link:')) {
        $firstLink = $i;
        break;
      }
    }

    $this->assertNotNull($firstLink, 'The fixture has link-template matches to rank.');
    $this->assertLessThan($firstLink, $position('name'), 'A real field outranks every link template.');
    $this->assertLessThan($firstLink, $position('uuid'), 'Even system plumbing outranks link templates.');
    $this->assertLessThan($position('uuid'), $position('name'), 'Real content outranks system plumbing.');
  }

  /**
   * A field on the entity itself outranks the same field a hop away.
   */
  public function testSearchRanksNearerMatchesFirst(): void {
    // `_entity:icon` rather than `_entity:label`: MatcherField only synthesises
    // a label match for entity types declaring a label key, and `user` does
    // not — it resolves its display name through a callback instead.
    $keys = array_column($this->locator()->search($this->titleShape(), 'icon'), 'value');
    $direct = array_search('_entity:icon', $keys, TRUE);
    $hopped = array_search('roles._entity:icon', $keys, TRUE);

    $this->assertNotFalse($direct);
    $this->assertNotFalse($hopped);
    $this->assertLessThan($hopped, $direct, 'The zero-hop match comes first.');
  }

  /**
   * The flat list regroups into one pane per reference hop.
   */
  public function testBrowseSplitsTheFlatListIntoPanes(): void {
    $shape = $this->titleShape();

    $root = $this->locator()->browse($shape);
    $this->assertSame('User', $root['entity']);
    $this->assertContains('name', array_column($root['leaves'], 'value'));
    $this->assertSame(['roles'], array_column($root['refs'], 'path'), 'The one reference is offered as a doorway.');
    $this->assertSame('Roles', $root['refs'][0]['label'], 'The hop is named by its field label, not its machine name.');
    $this->assertSame('User Role', $root['refs'][0]['target'], 'And says what it leads to.');
    $this->assertGreaterThan(0, $root['refs'][0]['count']);

    // A leaf of the root pane is never also listed as a hop from it.
    $this->assertNotContains('roles', array_column($root['leaves'], 'value'));
  }

  /**
   * A pane's fields are grouped, and say which group they are in.
   *
   * Within a pane the order is content, then system plumbing, then link
   * templates, alphabetical inside each. Run together that is three A-Z
   * sequences in a row — you scroll past "Vocabulary" onto "Language", then
   * past "Weight" onto "(Link) Alchemist" — which reads as no order at all.
   * The column draws a boundary wherever the tier changes, so the tier has to
   * survive into the response rather than being stripped as a sort key.
   */
  public function testBrowseLeavesCarryTheirTierAndAreGroupedByIt(): void {
    $leaves = $this->locator()->browse($this->titleShape())['leaves'];

    $this->assertNotEmpty($leaves);
    foreach ($leaves as $leaf) {
      $this->assertArrayHasKey('tier', $leaf, 'The column needs this to draw its group boundaries.');
    }

    $tiers = array_column($leaves, 'tier');
    $sorted = $tiers;
    sort($sorted);
    $this->assertSame($sorted, $tiers, 'Tiers appear in blocks, never interleaved.');

    // And the ranking is real: this fixture has content and link templates.
    $this->assertContains(0, $tiers);
    $this->assertContains(2, $tiers);
    $byTier = [];
    foreach ($leaves as $leaf) {
      $byTier[$leaf['tier']][] = $leaf['label'];
    }
    foreach ($byTier as $tier => $labels) {
      $alphabetical = $labels;
      sort($alphabetical);
      $this->assertSame($alphabetical, $labels, "Tier {$tier} is alphabetical within itself.");
    }
  }

  /**
   * Descending names the hop taken, not the entity landed on.
   *
   * Labelling every crumb by its entity reads as "User › User" the moment a
   * reference points back at its own type, which self-referencing fields such
   * as a taxonomy parent always do.
   */
  public function testBrowseCrumbsNameTheHop(): void {
    $pane = $this->locator()->browse($this->titleShape(), 'roles');

    $this->assertSame('User Role', $pane['entity']);
    $this->assertSame(['', 'roles'], array_column($pane['crumbs'], 'path'));
    $this->assertSame(['User', 'Roles'], array_column($pane['crumbs'], 'label'));
    $this->assertSame('User Role', $pane['crumbs'][1]['entity'], 'The entity reached is the crumb subtitle.');
    $this->assertContains('roles._entity:label', array_column($pane['leaves'], 'value'));
  }

  /**
   * Browsing a path that leads nowhere yields an empty pane, not an error.
   */
  public function testBrowseUnknownPathIsEmpty(): void {
    $pane = $this->locator()->browse($this->titleShape(), 'not_a_reference');

    $this->assertSame([], $pane['leaves']);
    $this->assertSame([], $pane['refs']);
  }

  /**
   * Matching can target an entity other than the component's own.
   *
   * An entity_query sorts by fields of the type it queries, which is not the
   * type the component is attached to — and a component may target nothing at
   * all, in which case the override is the only thing that produces matches.
   */
  public function testEntityTypeOverrideChangesTheOfferedSet(): void {
    $shape = $this->titleShape();

    $default = array_keys($this->locator()->getMatches($shape, TRUE));
    $overridden = array_keys($this->locator()->getMatches($shape, TRUE, 'entity_test'));

    $this->assertContains('mail', $default, 'The component targets users.');
    $this->assertNotContains('mail', $overridden, 'The override matches somewhere else entirely.');
    $this->assertNotSame($default, $overridden);

    $pane = $this->locator()->browse($shape, '', TRUE, 'entity_test');
    $this->assertSame('Entity Test', $pane['entity'], 'Browsing follows the override too.');
  }

  /**
   * The override is part of the cache identity.
   *
   * Both calls are the same shape and the same mode; only the entity type
   * differs. Keying the cache without it would serve one entity's fields for
   * another — silently, and only after the first lookup warmed the bin.
   */
  public function testOverrideIsPartOfTheCacheKey(): void {
    $shape = $this->titleShape();

    $user = $this->locator()->getMatches($shape, TRUE);
    $other = $this->locator()->getMatches($shape, TRUE, 'entity_test');
    $userAgain = $this->locator()->getMatches($shape, TRUE);

    $this->assertSame($user, $userAgain, 'The warmed entry still answers for the original entity type.');
    $this->assertNotSame($user, $other);
  }

  /**
   * Resolved matches survive a round trip through the cache bin.
   *
   * The matcher hands back live field-definition objects, which cannot be
   * serialised into a cache bin; they are stripped on the way in. If that
   * stripping ever stops happening the failure is a fatal during cache set,
   * so assert the stored shape directly.
   */
  public function testMatchesAreCachedWithoutFieldDefinitionObjects(): void {
    $shape = $this->titleShape();
    $this->locator()->getMatches($shape);

    $cid = implode(':', [
      'neo_alchemist.field_match',
      $shape->getTargetEntityType() ?? '',
      $shape->getTargetEntityBundle() ?? '',
      $shape->getRef(),
      $shape->getFormat(),
      $shape->isRequired() ? '1' : '0',
      'matched',
    ]);
    $cached = $this->container->get('cache.data')->get($cid);

    $this->assertNotFalse($cached, 'The match list is cached under a key naming the entity type and mode.');
    $this->assertNotEmpty($cached->data);
    foreach ($cached->data as $key => $match) {
      $this->assertSame(['title', 'group'], array_keys($match), "Cached match {$key} carries only scalars.");
    }
    $this->assertContains('entity_field_info', $cached->tags, 'Adding a field invalidates the offer list.');
  }

}
