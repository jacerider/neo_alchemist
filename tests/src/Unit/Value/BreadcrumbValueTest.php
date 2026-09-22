<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Value;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\BreadcrumbValue;
use Drupal\neo_alchemist\Shape\ComponentShapeContextInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use PHPUnit\Framework\Attributes\Group;
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
   * Builds the plugin over a fixed trail.
   *
   * @param \Drupal\Core\Link[] $links
   *   The links the breadcrumb manager reports for the current route.
   * @param bool $hide_home
   *   The `hide_home` setting.
   * @param bool $hide_current
   *   The `hide_current` setting.
   */
  private function plugin(array $links, bool $hide_home, bool $hide_current): BreadcrumbValue {
    $breadcrumb = new Breadcrumb();
    if ($links) {
      $breadcrumb->setLinks($links);
    }
    $manager = $this->createMock(BreadcrumbBuilderInterface::class);
    $manager->method('build')->willReturn($breadcrumb);

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteName')->willReturn('entity.node.canonical');
    $route_match->method('getRouteObject')->willReturn(new Route('/node/{node}'));

    $title_resolver = $this->createMock(TitleResolverInterface::class);
    $title_resolver->method('getTitle')->willReturn(self::PAGE_TITLE);

    return new BreadcrumbValue(
      'breadcrumb',
      [],
      $this->shape(),
      ['hide_home' => $hide_home, 'hide_current' => $hide_current],
      $manager,
      Request::create('/about-us/leadership'),
      $route_match,
      $title_resolver,
    );
  }

  /**
   * A shape on a live (non-preview) render of a node.
   *
   * Preview swaps in a placeholder title, which would hide whether the
   * current-page crumb was reached at all.
   */
  private function shape(): ComponentShapePluginInterface {
    $component = $this->createMock(ComponentInterface::class);
    $component->method('isPreview')->willReturn(FALSE);
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');

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
  private static function fullTrail(): array {
    return [
      new Link('Home', Url::fromRoute('<front>')),
      new Link('About Us', Url::fromRoute('entity.node.canonical', ['node' => 11])),
    ];
  }

  /**
   * The trail core builds when the parent path is not a page: Home alone.
   */
  private static function homeOnlyTrail(): array {
    return [new Link('Home', Url::fromRoute('<front>'))];
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
    $value = $this->plugin(self::fullTrail(), TRUE, FALSE)->provideDefaultValue([]);

    $this->assertSame([
      self::crumb('About Us', 'route:entity.node.canonical;node=11'),
    ] + self::CURRENT, $value);
  }

  /**
   * Keeping home keeps it, and the current page still follows the ancestors.
   */
  public function testKeepingHomeKeepsTheWholeTrail(): void {
    $value = $this->plugin(self::fullTrail(), FALSE, FALSE)->provideDefaultValue([]);

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

}
