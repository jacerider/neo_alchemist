<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Template\Attribute;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins how a dynamic entity link reports an inaccessible destination.
 *
 * `_entity:link:canonical` used to resolve to nothing when the visitor could
 * not reach the entity. That erased the value rather than describing it, and
 * the erasure travelled: ChildrenMatchMapper drops a row whose every mapped
 * shape came back empty, so a list bound link-first lost the whole item — the
 * title with it — for anyone without view access. The prop-def has carried an
 * `access` boolean the entire time, and every scaffolded template is written
 * as `{% if x and x.access %}`, so the information had somewhere to go.
 *
 * Canonical therefore reports the denial and lets the component decide. Every
 * other link template still erases: an `edit-form` URI names an admin route,
 * and putting it in the markup would disclose a path and the entity's
 * existence to someone who can do nothing with either.
 *
 * @see \Drupal\neo_alchemist\Match\MatcherField::getEntityDefinitionLink()
 */
#[Group('neo_alchemist')]
class MatcherLinkAccessTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    // The link shapes' default field type, and the with-options field type
    // the `target` enum's child shape needs.
    'link',
    'options',
    // An entity's canonical URL serialises to `entity:<type>/<id>`, which the
    // pre-render stage hands to Linkit for entity-URL substitution.
    'linkit',
    'neo_settings',
    // The link title is run through adminIcon() to pick a data-icon, which
    // resolves the icon repository eagerly.
    'neo_icon',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The entity under test.
   */
  private EntityTest $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);

    // User 1 is created first and left unused: it bypasses access entirely,
    // which would make every assertion below pass for the wrong reason.
    $this->createUser([]);

    $this->entity = EntityTest::create(['name' => 'Restricted thing']);
    $this->entity->save();
  }

  /**
   * A denied canonical link keeps its value and reports access FALSE.
   */
  public function testDeniedCanonicalReportsAccessRatherThanVanishing(): void {
    $this->setCurrentUser([]);
    $value = $this->resolve('_entity:link:canonical');

    $this->assertNotSame([], $value, 'The link value survives a denial.');
    $this->assertFalse($value['access']);
    $this->assertSame('Restricted thing', $value['title']);
    $this->assertNotEmpty($value['uri']);
  }

  /**
   * An allowed canonical link is unchanged, and says so.
   */
  public function testAllowedCanonicalReportsAccessTrue(): void {
    $this->setCurrentUser(['view test entity']);
    $value = $this->resolve('_entity:link:canonical');

    $this->assertTrue($value['access']);
    $this->assertSame('Restricted thing', $value['title']);
  }

  /**
   * Title stays the first key, because a consumer reads it positionally.
   *
   * HeadingValue::getEntityFieldValue() collapses a match with
   * `while (is_array($value)) $value = reset($value)`. An `access` key ahead
   * of `title` would resolve every heading bound to a link to '' — silently,
   * and only for the visitors who cannot follow the link.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentValue\HeadingValue::getEntityFieldValue()
   */
  public function testTitleRemainsTheFirstKey(): void {
    $this->setCurrentUser([]);
    $value = $this->resolve('_entity:link:canonical');

    $this->assertSame('title', array_key_first($value));
    $this->assertSame('Restricted thing', reset($value));
  }

  /**
   * A denied admin link is still erased — its URI is not a visitor's business.
   */
  public function testDeniedAdminLinkIsStillErased(): void {
    $this->setCurrentUser([]);
    $this->assertSame([], $this->resolve('_entity:link:edit-form'));

    // And is offered normally to someone who may use it, so the narrowing
    // above did not simply disable the template.
    $this->setCurrentUser(['administer entity_test content']);
    $allowed = $this->resolve('_entity:link:edit-form');
    $this->assertNotSame([], $allowed);
    $this->assertTrue($allowed['access']);
  }

  /**
   * The access decision carries the context it varies on.
   *
   * Without this the answer is baked into a render cache with nothing to vary
   * on, and the decision made for one audience is served to another — which
   * now means an editor's live `<a>` reaching an anonymous visitor.
   */
  public function testAccessDecisionCarriesItsCacheContexts(): void {
    $this->setCurrentUser([]);
    $cacheableMetadata = new CacheableMetadata();
    $this->resolve('_entity:link:canonical', $cacheableMetadata);

    $this->assertContains('user.permissions', $cacheableMetadata->getCacheContexts());
  }

  /**
   * A denied link still reads as denied once the shape pipeline is done.
   *
   * The matcher's answer is only useful if it survives to the twig contract,
   * and there is one place it could quietly not: LinkShape extends
   * StructuredObjectShapeBase, whose ::buildValue() drops empty children and
   * then backfills the schema defaults — where UrlShapeTrait's
   * ::getDefaultSchemaValue() hard-sets `access` to TRUE. A FALSE that read as
   * "empty" there would come back out of the merge as TRUE, and every guarded
   * template would render the anchor it was written to suppress.
   *
   * Asserted on both fixture props: `link` takes that backfilling parent,
   * `url` does not, and the guard lives in the trait they share.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentShape\StructuredObjectShapeBase::buildValue()
   */
  public function testDeniedLinkStaysDeniedThroughTheShapePipeline(): void {
    $this->setCurrentUser([]);
    $uri = $this->entity->toUrl('canonical')->toUriString();

    foreach (['link', 'url'] as $prop) {
      $value = $this->resolveProp($prop, $uri);
      $this->assertFalse($value['access'], "The $prop prop reports the denial.");
      $this->assertNotEmpty($value['uri'], "The $prop prop keeps its uri.");
    }
  }

  /**
   * An allowed link resolves TRUE, so the assertion above means something.
   */
  public function testAllowedLinkResolvesTrueThroughTheShapePipeline(): void {
    $this->setCurrentUser(['view test entity']);
    $uri = $this->entity->toUrl('canonical')->toUriString();

    foreach (['link', 'url'] as $prop) {
      $this->assertTrue($this->resolveProp($prop, $uri)['access']);
    }
  }

  /**
   * Resolves a fixture prop from an authored uri, through to pre-render.
   *
   * Staged as a preview override — the config-scope way to author a value with
   * no host entity behind it — and read with ::getPropValue(), the entry point
   * that runs the pre-render stage a template's value has been through.
   *
   * @param string $prop
   *   The fixture prop: 'link' or 'url'.
   * @param string $uri
   *   The authored uri.
   *
   * @return array
   *   The resolved value.
   */
  private function resolveProp(string $prop, string $uri): array {
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
          'value' => [
            'uri' => $uri,
            'title' => 'Restricted thing',
            'options' => [],
          ],
          'options' => [$prop => ['default' => 0]],
        ],
      ],
    ]);

    return $component->getPropShapes()[$prop]->getPropValue(new Attribute());
  }

  /**
   * Resolves a matcher key against the test entity.
   *
   * @param string $key
   *   The matcher key.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   (optional) Metadata to collect into.
   *
   * @return array
   *   The resolved value.
   */
  private function resolve(string $key, ?CacheableMetadata $cacheableMetadata = NULL): array {
    return $this->container->get('neo_alchemist.matcher_field')->getEntityValue(
      entity: $this->entity,
      key: $key,
      cacheableMetadata: $cacheableMetadata ?? new CacheableMetadata(),
    );
  }

  /**
   * Switches the current user to one holding exactly these permissions.
   *
   * @param string[] $permissions
   *   The permissions to grant.
   */
  private function setCurrentUser(array $permissions): void {
    $this->container->get('account_switcher')
      ->switchTo($this->createUser($permissions));
  }

  /**
   * Creates a user with a role carrying the given permissions.
   *
   * @param string[] $permissions
   *   The permissions to grant.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  private function createUser(array $permissions): AccountInterface {
    static $index = 0;
    $index++;
    if ($permissions) {
      $role = Role::create(['id' => 'role_' . $index, 'label' => 'Role ' . $index]);
      foreach ($permissions as $permission) {
        $role->grantPermission($permission);
      }
      $role->save();
    }
    $user = User::create([
      'name' => 'user_' . $index,
      'status' => 1,
      'roles' => isset($role) ? [$role->id()] : [],
    ]);
    $user->save();
    return $user;
  }

}
