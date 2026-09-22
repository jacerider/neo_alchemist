<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Value;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\BreadcrumbValue;
use Drupal\neo_alchemist\Shape\ComponentShapeContextInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * What the two "hide" checkboxes do to the trail the builder handed over.
 *
 * The pair is not symmetrical, and the asymmetry is the whole subject. Core's
 * path-based builder never puts the current page in the trail, so `hide_home`
 * removes a crumb that is there while `hide_current` suppresses one this
 * plugin would otherwise add. The two therefore have to be decided from
 * different arrays: the first from what survived the shift, the second from
 * whether the builder produced a trail at all.
 *
 * Reading both off the shifted array is the bug this class pins. A page one
 * level deep whose parent path is not itself routable gets `[Home]` and
 * nothing else, so "hide home" emptied it, and with it the current-page crumb
 * the site builder had explicitly asked for — the prop resolved to nothing and
 * the component rendered no breadcrumb at all.
 *
 * The genuinely empty trail still has to stay empty: the front page is the
 * case, and the class docblock on processingModeDefault() is about exactly
 * that, since `block` means nothing downstream can refill it.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\BreadcrumbValue
 */
#[Group('neo_alchemist')]
class BreadcrumbValueTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * The title the resolver reports for the page under test.
   */
  private const PAGE_TITLE = 'Leadership';

  /**
   * The current-page crumb, which is titled but deliberately unlinked.
   */
  private const CURRENT = [
    '_current' => ['title' => self::PAGE_TITLE, 'url' => []],
  ];

  /**
   * The editor route a preview actually renders from.
   */
  private const EDITOR_PATH = '/node/12/alchemist/full/preview';

  /**
   * The aliased path of the page the preview is standing in for.
   */
  private const LIVE_PATH = '/about-us/leadership';

  /**
   * The router request context the plugin is given.
   */
  private RequestContext $requestContext;

  /**
   * What the breadcrumb manager saw, recorded during the ::build() call.
   *
   * @var array|null
   */
  private ?array $builtFor = NULL;

  /**
   * Builds the plugin over a fixed trail.
   *
   * @param \Drupal\Core\Link[] $links
   *   The links the breadcrumb manager reports for the current route.
   * @param bool $hide_home
   *   The `hide_home` setting.
   * @param bool $hide_current
   *   The `hide_current` setting.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $previewed
   *   The entity an editor is previewing, or NULL for a live render.
   */
  private function plugin(array $links, bool $hide_home, bool $hide_current, ?ContentEntityInterface $previewed = NULL): BreadcrumbValue {
    $breadcrumb = new Breadcrumb();
    if ($links) {
      $breadcrumb->setLinks($links);
    }
    $manager = $this->createMock(BreadcrumbBuilderInterface::class);
    $manager->method('build')->willReturnCallback(
      function (RouteMatchInterface $route_match) use ($breadcrumb): Breadcrumb {
        // Recorded from inside the call, which is the only moment the swapped
        // path is supposed to be in place.
        $this->builtFor = [
          'route' => $route_match->getRouteName(),
          'node' => $route_match->getParameter('node'),
          'path' => $this->requestContext->getPathInfo(),
        ];
        return $breadcrumb;
      },
    );

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteName')->willReturn('neo_alchemist.preview');
    $route_match->method('getRouteObject')->willReturn(new Route('/node/{node}/alchemist/full/preview'));

    $title_resolver = $this->createMock(TitleResolverInterface::class);
    $title_resolver->method('getTitle')->willReturn(self::PAGE_TITLE);

    $route_provider = $this->createMock(RouteProviderInterface::class);
    $route_provider->method('getRouteByName')->willReturn(new Route('/node/{node}'));

    $this->requestContext = new RequestContext();
    $this->requestContext->setPathInfo(self::EDITOR_PATH);

    return new BreadcrumbValue(
      'breadcrumb',
      [],
      $this->shape($previewed),
      ['hide_home' => $hide_home, 'hide_current' => $hide_current],
      $manager,
      Request::create(self::EDITOR_PATH),
      $route_match,
      $title_resolver,
      $route_provider,
      $this->requestContext,
      $this->configFactory(),
    );
  }

  /**
   * A config factory reporting node 1 as the front page, as this site does.
   */
  private function configFactory(): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('page.front')->willReturn('/node/1');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('system.site')->willReturn($config);
    return $factory;
  }

  /**
   * A saved node standing in for the entity an editor is previewing.
   *
   * @param int $id
   *   The node id, which decides whether it is the site's front page.
   */
  private function savedNode(int $id = 12): ContentEntityInterface {
    $url = Url::fromRoute('entity.node.canonical', ['node' => $id]);
    $generator = $this->createMock(UrlGeneratorInterface::class);
    $generator->method('getPathFromRoute')->willReturn('node/' . $id);
    $generator->method('generateFromRoute')->willReturn(self::LIVE_PATH);
    $url->setUrlGenerator($generator);

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('isNew')->willReturn(FALSE);
    $entity->method('hasLinkTemplate')->willReturn(TRUE);
    $entity->method('toUrl')->willReturn($url);
    $entity->method('label')->willReturn(self::PAGE_TITLE);
    return $entity;
  }

  /**
   * A shape on a live (non-preview) render of a node.
   *
   * Preview swaps in a placeholder title, which would hide whether the
   * current-page crumb was reached at all.
   */
  private function shape(?ContentEntityInterface $previewed): ComponentShapePluginInterface {
    $component = $this->createMock(ComponentInterface::class);
    $component->method('isPreview')->willReturn($previewed !== NULL);
    $entity = $previewed ?? $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    if ($previewed) {
      $component->method('getTargetEntity')->willReturn($previewed);
    }

    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $context->method('getComponent')->willReturn($component);
    $context->method('getEntity')->willReturn($entity);

    return $this->shapeDouble([$context]);
  }

  /**
   * A crumb as the plugin emits it, for the expectations below.
   *
   * @param string $title
   *   The link text.
   * @param string $uri
   *   The uri the link resolves to.
   */
  private static function crumb(string $title, string $uri): array {
    return [
      'title' => $title,
      'url' => ['title' => $title, 'uri' => $uri, 'options' => []],
    ];
  }

  /**
   * The trail core builds for a page whose parent path is routable.
   */
  private function fullTrail(): array {
    return [
      new Link('Home', Url::fromRoute('<front>')),
      $this->nodeLink('About Us', 11),
    ];
  }

  /**
   * The trail core builds when the parent path is not a page: Home alone.
   */
  private static function homeOnlyTrail(): array {
    return [new Link('Home', Url::fromRoute('<front>'))];
  }

  /**
   * The trail a menu-based builder hands over: ancestors, and no Home.
   *
   * Menu_breadcrumb only prepends Home when its `add_home` option is set, so
   * this is what an ordinary site using it produces.
   */
  private function menuTrail(): array {
    return [$this->nodeLink('About Us', 11)];
  }

  /**
   * A crumb pointing at a node, carrying the generator a url check needs.
   *
   * @param string $title
   *   The link text.
   * @param int $id
   *   The node id.
   */
  private function nodeLink(string $title, int $id): Link {
    $url = Url::fromRoute('entity.node.canonical', ['node' => $id]);
    $generator = $this->createMock(UrlGeneratorInterface::class);
    $generator->method('getPathFromRoute')->willReturn('node/' . $id);
    $url->setUrlGenerator($generator);
    return new Link($title, $url);
  }

  /**
   * Hiding home does not take the current page with it.
   *
   * The regression: `[Home]` shifted to `[]`, and the current-page crumb was
   * gated on the shifted array, so the prop resolved to nothing.
   */
  public function testHideHomeKeepsCurrentWhenHomeIsTheOnlyCrumb(): void {
    $value = $this->plugin(self::homeOnlyTrail(), TRUE, FALSE)->provideDefaultValue([]);

    $this->assertSame(self::CURRENT, $value);
  }

  /**
   * Hiding home with the current page hidden too really does empty the trail.
   */
  public function testHideHomeAndCurrentEmptiesHomeOnlyTrail(): void {
    $value = $this->plugin(self::homeOnlyTrail(), TRUE, TRUE)->provideDefaultValue([]);

    $this->assertSame([], $value);
  }

  /**
   * A deeper trail drops home and keeps the ancestors plus the current page.
   */
  public function testHideHomeKeepsAncestorsAndCurrent(): void {
    $value = $this->plugin($this->fullTrail(), TRUE, FALSE)->provideDefaultValue([]);

    $this->assertSame([
      self::crumb('About Us', 'route:entity.node.canonical;node=11'),
    ] + self::CURRENT, $value);
  }

  /**
   * Keeping home keeps it, and the current page still follows the ancestors.
   */
  public function testKeepingHomeKeepsTheWholeTrail(): void {
    $value = $this->plugin($this->fullTrail(), FALSE, FALSE)->provideDefaultValue([]);

    $this->assertSame([
      self::crumb('Home', 'route:<front>'),
      self::crumb('About Us', 'route:entity.node.canonical;node=11'),
    ] + self::CURRENT, $value);
  }

  /**
   * A route the builder produced nothing for renders no breadcrumb.
   *
   * The front page is the case. `hide_current` is off, so only the emptiness
   * of the trail itself can be what suppresses the current-page crumb.
   */
  public function testNoTrailStaysEmptyEvenWhenShowingTheCurrentPage(): void {
    $value = $this->plugin([], FALSE, FALSE)->provideDefaultValue([]);

    $this->assertSame([], $value);
  }

  /**
   * Hiding home leaves a trail that has no home crumb alone.
   *
   * The regression this pins: the shift was unconditional, so on a site whose
   * builder does not prepend Home it removed the first real ancestor and the
   * breadcrumb collapsed to the current page.
   */
  public function testHideHomeKeepsMenuTrailWithoutHomeCrumb(): void {
    $value = $this->plugin($this->menuTrail(), TRUE, FALSE)->provideDefaultValue([]);

    $this->assertSame([
      self::crumb('About Us', 'route:entity.node.canonical;node=11'),
    ] + self::CURRENT, $value);
  }

  /**
   * Live, the manager is asked about the request that is actually happening.
   */
  public function testLiveRenderAsksAboutTheCurrentRoute(): void {
    $this->plugin($this->fullTrail(), TRUE, FALSE)->provideDefaultValue([]);

    $this->assertSame([
      'route' => 'neo_alchemist.preview',
      'node' => NULL,
      'path' => self::EDITOR_PATH,
    ], $this->builtFor);
  }

  /**
   * A preview asks about the host entity's page, not the editor's own route.
   *
   * Both halves move: the route match the builders are offered, and the path
   * the core path-based builder walks regardless of that route match.
   */
  public function testPreviewAsksAboutTheHostEntityPage(): void {
    $node = $this->savedNode();

    $this->plugin($this->fullTrail(), TRUE, FALSE, $node)->provideDefaultValue([]);

    $this->assertSame('entity.node.canonical', $this->builtFor['route']);
    $this->assertSame(self::LIVE_PATH, $this->builtFor['path']);
    $this->assertSame($node, $this->builtFor['node'], 'The route parameter is upcast, as a real request would leave it.');
  }

  /**
   * The swapped path is put back, so the rest of the render is unaffected.
   */
  public function testPreviewRestoresTheRequestContext(): void {
    $this->plugin($this->fullTrail(), TRUE, FALSE, $this->savedNode())->provideDefaultValue([]);

    $this->assertSame(self::EDITOR_PATH, $this->requestContext->getPathInfo());
  }

  /**
   * A preview resolves the host page's trail, not the editor's.
   *
   * The regression in full: previewing node 12 used to surface the admin
   * trail — the node, "Layout", "Layout for Leadership: Full" — none of which
   * the visitor will ever see.
   */
  public function testPreviewResolvesTheHostPageTrail(): void {
    $value = $this->plugin($this->fullTrail(), TRUE, FALSE, $this->savedNode())->provideDefaultValue([]);

    $this->assertSame([
      self::crumb('About Us', 'route:entity.node.canonical;node=11'),
    ] + self::CURRENT, $value);
  }

  /**
   * Previewing the front page renders no breadcrumb, as the front page does.
   *
   * Core skips the breadcrumb by asking whether the CURRENT request is the
   * front page, which in a preview is the editor and never is.
   */
  public function testPreviewingTheFrontPageBuildsNothing(): void {
    $value = $this->plugin($this->fullTrail(), TRUE, FALSE, $this->savedNode(1))->provideDefaultValue([]);

    $this->assertSame([], $value);
    $this->assertNull($this->builtFor, 'The manager is never consulted for the front page.');
  }

}
