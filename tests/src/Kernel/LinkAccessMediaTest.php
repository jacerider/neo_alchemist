<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\LinkAccess;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins link access for a link to a media item.
 *
 * With `media.settings:standalone_url` off, Drupal's default, core routes
 * `entity.media.canonical` to the media edit form. A route check on an
 * `entity:media/N` link therefore asked for `media.update`. Editors passed
 * it and everyone else got a plain-text title where the download button
 * should have been (JOST-422). A canonical link is decided by view access.
 *
 * @see \Drupal\neo_alchemist\LinkAccess
 */
#[Group('neo_alchemist')]
class LinkAccessMediaTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use SdcPreviewStoreTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'media_test_source',
    'link',
    'options',
    'linkit',
    'neo_settings',
    'neo_icon',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The published media item.
   */
  private MediaInterface $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['image', 'media']);
    $this->config('media.settings')->set('standalone_url', FALSE)->save();
    // Rebuilt so the canonical route reflects the setting above.
    $this->container->get('router.builder')->rebuild();

    // User 1 bypasses access, which would make every assertion pass.
    $this->createUser([]);

    $type = $this->createMediaType('test');
    $this->media = Media::create(['bundle' => $type->id(), 'name' => 'Handout']);
    $this->media->save();
  }

  /**
   * A viewer who cannot edit the media item may still follow its link.
   */
  public function testViewerMayFollowMediaLink(): void {
    $this->setCurrentUser(['view media']);
    $url = Url::fromUri('entity:media/' . $this->media->id());

    $this->assertFalse($url->access(), 'Precondition: the route check alone denies.');
    $this->assertTrue(LinkAccess::check($url)->isAllowed());
    $this->assertTrue($this->resolveLink($url->toUriString())['access']);
  }

  /**
   * A visitor who cannot view the media item still sees it denied.
   */
  public function testNonViewerIsDenied(): void {
    $this->setCurrentUser([]);
    $uri = 'entity:media/' . $this->media->id();

    $this->assertFalse(LinkAccess::check(Url::fromUri($uri))->isAllowed());
    $this->assertFalse($this->resolveLink($uri)['access']);
  }

  /**
   * An unpublished media item is denied to a viewer.
   */
  public function testUnpublishedMediaIsDenied(): void {
    $this->media->setUnpublished()->save();
    $this->setCurrentUser(['view media']);

    $url = Url::fromUri('entity:media/' . $this->media->id());
    $this->assertFalse(LinkAccess::check($url)->isAllowed());
  }

  /**
   * The decision varies on the context it depends on.
   */
  public function testAccessDecisionCarriesItsCacheContexts(): void {
    $this->setCurrentUser(['view media']);
    $access = LinkAccess::check(Url::fromUri('entity:media/' . $this->media->id()));

    $this->assertContains('user.permissions', $access->getCacheContexts());
  }

  /**
   * Resolves the fixture's `link` prop from a uri, through to pre-render.
   *
   * @param string $uri
   *   The authored uri.
   *
   * @return array
   *   The resolved value.
   */
  private function resolveLink(string $uri): array {
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
        'link' => [
          'ref' => 'link',
          'value' => ['uri' => $uri, 'title' => 'Handout', 'options' => []],
          'options' => ['link' => ['default' => 0]],
        ],
      ],
    ]);

    return $component->getPropShapes()['link']->getPropValue(new Attribute());
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
