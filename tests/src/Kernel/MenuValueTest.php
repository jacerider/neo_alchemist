<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist_test\TestMenuItemAlter;
use Drupal\neo_alchemist_test\TestMenuLinkTree;
use Drupal\system\Entity\Menu;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the `menu` value provider and its documented item-alter contract.
 *
 * This site's mega menus resolve through MenuValue, and the contract other
 * modules build on is hook_neo_alchemist_menu_value_item_alter(): extra keys
 * on $entry survive into the component prop, setting $entry to NULL drops that
 * one item, and cacheability belongs on the shape. A refactor that alters
 * before recursing, or that array_merges instead of assigning, changes every
 * mega menu silently.
 *
 * The menu link tree service is replaced with a stub so a canned `#items`
 * structure can be fed straight into the mapping — real menu_link_content
 * entities would drag in routes, an active trail and access results that the
 * mapping does not care about. The hook, the shape, the prop resolution and
 * the cacheability merge are all real.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MenuValue
 * @see \Drupal\neo_alchemist_test\TestMenuLinkTree
 */
#[Group('neo_alchemist')]
class MenuValueTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The menu prop's `url` child stores into a link field item, and its
    // enum'd `target` resolves to list_string.
    'link',
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The stubbed menu link tree.
   */
  private TestMenuLinkTree $menuTree;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TestMenuItemAlter::reset();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);

    Menu::create(['id' => 'na-test-menu', 'label' => 'NA test menu'])->save();

    $this->menuTree = new TestMenuLinkTree();
    $this->container->set('menu.link_tree', $this->menuTree);
  }

  /**
   * A built menu tree with a parent, a nested child, and a sibling.
   */
  private function cannedBuild(): array {
    return [
      '#items' => [
        'parent' => [
          'title' => 'Parent',
          'url' => Url::fromUri('internal:/parent', [
            'attributes' => ['title' => 'Parent description', 'data-icon' => 'acorn'],
          ]),
          'in_active_trail' => TRUE,
          'is_expanded' => TRUE,
          'is_collapsed' => FALSE,
          'below' => [
            'child' => [
              'title' => 'Child',
              'url' => Url::fromUri('internal:/child'),
              'in_active_trail' => FALSE,
              'is_expanded' => FALSE,
              'is_collapsed' => FALSE,
            ],
          ],
        ],
        'sibling' => [
          'title' => 'Sibling',
          'url' => Url::fromUri('internal:/sibling'),
          'in_active_trail' => FALSE,
          'is_expanded' => FALSE,
          'is_collapsed' => FALSE,
        ],
      ],
      '#cache' => [
        'tags' => ['config:system.menu.na-test-menu'],
        'contexts' => ['route.menu_active_trails:na-test-menu'],
      ],
    ];
  }

  /**
   * Builds the fixture component with the menu provider on its `links` prop.
   *
   * @param array $settings
   *   Provider settings, merged over the menu id.
   */
  private function buildComponent(array $settings = []): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_menu_host')) {
      Component::create([
        'id' => 'na_menu_host',
        'label' => 'Menu host fixture',
        'description' => 'Menu host fixture',
        'component' => 'neo_alchemist_test:na_menu_host',
        'status' => TRUE,
      ])->save();
    }
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_menu_host')
      ->set('settings.props.links.plugins.links', [
        'menu' => [
          'id' => 'menu',
          // $settings first: PHP's + keeps the left operand for duplicate
          // keys, so the caller's overrides have to lead.
          'settings' => $settings + ['menu_id' => 'na-test-menu'],
        ],
      ])
      ->save();
    $storage->resetCache(['na_menu_host']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_menu_host');
    return $component;
  }

  /**
   * Resolves the `links` prop from a canned build.
   */
  private function resolveLinks(array $settings = []): array {
    $this->menuTree->build = $this->cannedBuild();
    return $this->buildComponent($settings)->getPropValues()['links'] ?? [];
  }

  /**
   * Built items map into the menu prop shape, children included.
   */
  public function testItemsMapIntoThePropShape(): void {
    $links = $this->resolveLinks();

    $this->assertCount(2, $links, 'Both root items resolved.');
    $this->assertSame('Parent', $links[0]['title']);
    $this->assertSame('Parent description', $links[0]['description'], 'The link attribute title becomes the description.');
    $this->assertSame('acorn', $links[0]['icon'], 'The data-icon attribute becomes the icon.');
    $this->assertSame('base:parent', $links[0]['url']['uri']);
    $this->assertSame('Parent', $links[0]['url']['title']);
    $this->assertTrue($links[0]['in_active_trail']);
    $this->assertTrue($links[0]['is_expanded']);

    $this->assertSame('Sibling', $links[1]['title']);
    $this->assertSame('', $links[1]['description'] ?? '', 'A link with no attributes gets an empty description.');
  }

  /**
   * A non-linking route leaves the item with no url — but keeps the item.
   *
   * `<nolink>`, `<none>` and `<button>` mark a menu item that groups its
   * children without being a destination. toUriString() would hand twig the
   * truthy string `route:<nolink>`, which neo_uri() resolves to '' — so the
   * heading renders as `<a href="">` and the browser follows it back to the
   * current page. MenuValue blanks the uri instead, and the shape pipeline
   * then drops the whole empty `url` object, so twig sees no `url` at all and
   * `{% if item.url.uri %}` is false. That guard is the contract every menu
   * component branches on.
   *
   * The item itself must survive, children included. `url` is deliberately
   * absent from the menu prop-def's `items.required` list: while it was
   * required, ArrayShape::buildValue() unset the whole delta the moment the
   * url resolved empty, and a nolink column heading plus every link under it
   * silently disappeared from the rendered menu.
   */
  public function testNonLinkingRoutesDropTheUrlButKeepTheItem(): void {
    $this->menuTree->build = [
      '#items' => [
        'nolink' => [
          'title' => 'About',
          'url' => Url::fromRoute('<nolink>', [], [
            'attributes' => ['title' => 'Grouping heading', 'data-icon' => 'acorn'],
          ]),
          'in_active_trail' => FALSE,
          'is_expanded' => TRUE,
          'is_collapsed' => FALSE,
          'below' => [
            'child' => [
              'title' => 'Our Company',
              'url' => Url::fromUri('internal:/our-company'),
              'in_active_trail' => FALSE,
              'is_expanded' => FALSE,
              'is_collapsed' => FALSE,
            ],
            'nested_nolink' => [
              'title' => 'Nested heading',
              'url' => Url::fromRoute('<none>'),
              'in_active_trail' => FALSE,
              'is_expanded' => FALSE,
              'is_collapsed' => FALSE,
            ],
          ],
        ],
        'button' => [
          'title' => 'Trigger',
          'url' => Url::fromRoute('<button>'),
          'in_active_trail' => FALSE,
          'is_expanded' => FALSE,
          'is_collapsed' => FALSE,
        ],
      ],
      '#cache' => [
        'tags' => ['config:system.menu.na-test-menu'],
        'contexts' => ['route.menu_active_trails:na-test-menu'],
      ],
    ];
    $links = $this->buildComponent()->getPropValues()['links'] ?? [];

    // The guard every menu template writes. Empty for all three routes, at
    // any depth — never the truthy string `route:<nolink>`.
    $this->assertEmpty($links[0]['url']['uri'] ?? NULL, '<nolink> leaves no uri to link to.');
    $this->assertEmpty($links[1]['url']['uri'] ?? NULL, '<button> leaves no uri to link to.');
    $this->assertEmpty($links[0]['below'][1]['url']['uri'] ?? NULL, '<none> leaves no uri at any depth.');
    $this->assertArrayNotHasKey('url', $links[0], 'The empty url object is dropped, not passed through half-built.');

    // The item itself survives. This is the regression that made a nolink
    // column heading and everything under it vanish from the footer.
    $this->assertCount(2, $links, 'Both root items survived — a non-link is still a menu item.');
    $this->assertSame('About', $links[0]['title']);
    $this->assertSame('Grouping heading', $links[0]['description'], 'A non-link keeps its description.');
    $this->assertSame('acorn', $links[0]['icon'], 'A non-link keeps its icon.');
    $this->assertCount(2, $links[0]['below'], 'A non-link keeps its children — that is what it groups.');

    // A routed sibling in the same tree is untouched.
    $this->assertSame('base:our-company', $links[0]['below'][0]['url']['uri']);
  }

  /**
   * Nested children recurse into a `below` list of the same shape.
   */
  public function testNestedChildrenRecurse(): void {
    $links = $this->resolveLinks();

    $this->assertArrayHasKey('below', $links[0], 'The parent carries its children.');
    $this->assertCount(1, $links[0]['below']);
    $this->assertSame('Child', $links[0]['below'][0]['title']);
    $this->assertSame('base:child', $links[0]['below'][0]['url']['uri']);
    $this->assertArrayNotHasKey('below', $links[1], 'A childless item gets no below key.');
  }

  /**
   * The hook fires for nested items too, not just the roots.
   */
  public function testHookFiresForNestedItems(): void {
    $this->resolveLinks();

    $this->assertContains('Child', TestMenuItemAlter::$seenTitles, 'The alter hook ran for the nested child.');
    $this->assertContains('Parent', TestMenuItemAlter::$seenTitles);
    $this->assertContains('Sibling', TestMenuItemAlter::$seenTitles);
  }

  /**
   * Extra keys a hook adds survive into the component prop verbatim.
   */
  public function testHookExtraKeysSurviveIntoTheProp(): void {
    TestMenuItemAlter::$mode = TestMenuItemAlter::MODE_ENRICH;

    $links = $this->resolveLinks();

    $this->assertSame('BADGE:Parent', $links[0]['badge'] ?? NULL, 'The hook-added key reached the prop.');
    $this->assertSame('BADGE:Child', $links[0]['below'][0]['badge'] ?? NULL, 'Nested items keep their hook-added keys too.');
  }

  /**
   * Setting $entry to NULL drops that one item and nothing else.
   */
  public function testHookVetoDropsOnlyThatItem(): void {
    TestMenuItemAlter::$mode = TestMenuItemAlter::MODE_VETO;
    TestMenuItemAlter::$vetoTitle = 'Sibling';

    $links = $this->resolveLinks();

    $this->assertCount(1, $links, 'The vetoed item was dropped.');
    $this->assertSame('Parent', $links[0]['title'], 'The surviving item is re-indexed from zero.');
  }

  /**
   * A veto can drop a nested child without touching its parent.
   */
  public function testHookVetoDropsNestedChild(): void {
    TestMenuItemAlter::$mode = TestMenuItemAlter::MODE_VETO;
    TestMenuItemAlter::$vetoTitle = 'Child';

    $links = $this->resolveLinks();

    $this->assertCount(2, $links, 'The root items are untouched.');
    $this->assertSame([], $links[0]['below'] ?? [], 'The vetoed child left an empty below list.');
  }

  /**
   * Cacheability a hook adds through the shape reaches the component.
   */
  public function testHookCacheabilityBubblesToTheComponent(): void {
    TestMenuItemAlter::$mode = TestMenuItemAlter::MODE_CACHEABILITY;
    $this->menuTree->build = $this->cannedBuild();
    $component = $this->buildComponent();

    $component->getPropValues();

    $this->assertContains(
      TestMenuItemAlter::CACHE_TAG,
      $component->getCacheableMetadata()->getCacheTags(),
      'The hook-added cache tag reached the component.',
    );
  }

  /**
   * The menu entity and the built tree both contribute cacheability.
   */
  public function testMenuAndTreeCacheabilityMerged(): void {
    $this->menuTree->build = $this->cannedBuild();
    $component = $this->buildComponent();

    $component->getPropValues();
    $metadata = $component->getCacheableMetadata();

    $this->assertContains('config:system.menu.na-test-menu', $metadata->getCacheTags(), 'The menu entity tag is present.');
    $this->assertContains('route.menu_active_trails:na-test-menu', $metadata->getCacheContexts(), 'The active-trail context from the built tree survived.');
  }

  /**
   * An unknown menu leaves the incoming value alone.
   */
  public function testUnknownMenuLeavesValueAlone(): void {
    $this->menuTree->build = $this->cannedBuild();
    $links = $this->buildComponent(['menu_id' => 'does-not-exist'])->getPropValues()['links'] ?? [];

    // The prop falls back to its schema examples rather than resolving a menu.
    $this->assertSame('Title 1', $links[0]['title'] ?? NULL, 'With no menu to load the provider is a pass-through.');
  }

  /**
   * A start level deeper than the active trail resolves to nothing.
   *
   * Mirrors SystemMenuBlock: with level > 1 only the active trail's subtree at
   * that level renders, so a page with no ancestor that deep has nothing to
   * show — and must not fall back to rendering the whole menu.
   *
   * Note the value is reset to [] BEFORE the level check, so this path also
   * discards whatever came in: the prop resolves empty and is dropped from the
   * props handed to SDC, rather than falling back to the schema examples.
   */
  public function testLevelDeeperThanActiveTrailResolvesEmpty(): void {
    $this->menuTree->build = $this->cannedBuild();
    $this->menuTree->activeTrail = ['menu_link_content:one' => 'menu_link_content:one'];

    $values = $this->buildComponent(['level' => 3])->getPropValues();

    $this->assertArrayNotHasKey('links', $values, 'Nothing renders when the trail is shallower than the start level.');
  }

  /**
   * Expanding all items clears the trail-derived expanded parents.
   *
   * This is what makes a header or mega menu render its full tree rather than
   * only the current page's branch.
   */
  public function testExpandAllItemsClearsExpandedParents(): void {
    $this->menuTree->build = $this->cannedBuild();
    $this->menuTree->activeTrail = ['menu_link_content:one' => 'menu_link_content:one'];

    $this->buildComponent(['expand_all_items' => TRUE])->getPropValues();

    $this->assertSame([], $this->menuTree->capturedParameters->expandedParents, 'Expanded parents were reset so the whole tree loads.');
    $this->assertSame(1, $this->menuTree->capturedParameters->minDepth, 'The default start level is 1.');
  }

  /**
   * A relative depth caps the maximum tree depth.
   */
  public function testDepthCapsMaxDepth(): void {
    $this->menuTree->build = $this->cannedBuild();

    $this->buildComponent(['level' => 1, 'depth' => 2])->getPropValues();

    $this->assertSame(2, $this->menuTree->capturedParameters->maxDepth, 'level 1 + depth 2 caps at depth 2.');
  }

}
