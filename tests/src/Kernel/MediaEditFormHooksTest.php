<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\media_library\MediaLibraryState;
use Drupal\neo_alchemist\Hook\MediaEditFormHooks;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that a media item edited from an Alchemist prop saves over AJAX.
 *
 * @see \Drupal\neo_alchemist\Hook\MediaEditFormHooks
 */
#[Group('neo_alchemist')]
final class MediaEditFormHooksTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'filter',
    'text',
    'media',
    'media_test_source',
    'media_library',
    'views',
    'options',
    'neo_settings',
    'neo_alchemist',
  ];

  /**
   * The media item whose edit form is built.
   */
  protected MediaInterface $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['field', 'system', 'filter', 'image', 'file', 'media', 'media_library']);
    $type = $this->createMediaType('test');
    $this->media = Media::create([
      'bundle' => $type->id(),
      'name' => 'Cat',
    ]);
    $this->media->save();
  }

  /**
   * Tests the form an Alchemist prop's Edit button opens.
   */
  public function testAlchemistOpenerIsWired(): void {
    $form = $this->buildEditForm('neo_alchemist.media_library.opener', 'drupal_modal');
    $callback = $form['actions']['submit']['#ajax']['callback'] ?? NULL;
    $this->assertIsArray($callback);
    $this->assertInstanceOf(MediaEditFormHooks::class, $callback[0]);
    $this->assertSame('ajaxCallback', $callback[1]);
    $this->assertSame('true', $form['actions']['submit']['#attributes']['data-disable-refocus']);
    $this->assertFalse($form['actions']['delete']['#access'], 'Delete is hidden in the modal.');
  }

  /**
   * Tests that every other way of reaching the form is left alone.
   */
  public function testOtherRequestsUntouched(): void {
    // Core's own opener belongs to core, or to neo_media_library_edit.
    $form = $this->buildEditForm('media_library.opener.field_widget', 'drupal_modal');
    $this->assertArrayNotHasKey('#ajax', $form['actions']['submit']);

    // The edit page itself, not a modal.
    $form = $this->buildEditForm('neo_alchemist.media_library.opener', NULL);
    $this->assertArrayNotHasKey('#ajax', $form['actions']['submit']);

    // No media library state at all.
    $this->pushRequest(['_wrapper_format' => 'drupal_modal']);
    $form = $this->container->get('entity.form_builder')->getForm($this->media, 'edit');
    $this->assertArrayNotHasKey('#ajax', $form['actions']['submit']);
  }

  /**
   * Tests that a successful save refreshes the prop's widget and closes.
   */
  public function testCallbackRefreshesWidget(): void {
    $form = $this->buildEditForm('neo_alchemist.media_library.opener', 'drupal_ajax');
    $hooks = $form['actions']['submit']['#ajax']['callback'][0];
    $response = $hooks->ajaxCallback($form, new FormState());
    $commands = $response->getCommands();
    $this->assertCount(2, $commands);
    $invoke = (new InvokeCommand('[data-media-library-widget-update="hero-image"]', 'trigger', ['mousedown']))->render();
    $this->assertSame($invoke, $commands[0]);
    $this->assertSame((new CloseModalDialogCommand())->render(), $commands[1]);
  }

  /**
   * Builds the media edit form under a request carrying a media library state.
   *
   * @param string $opener_id
   *   The opener the state names.
   * @param string|null $wrapper_format
   *   The request's wrapper format, or NULL for a plain page request.
   *
   * @return array
   *   The built form.
   */
  protected function buildEditForm(string $opener_id, ?string $wrapper_format): array {
    $state = MediaLibraryState::create($opener_id, ['test'], 'test', 1, [
      'field_widget_id' => 'hero-image',
    ]);
    $query = $state->all();
    if ($wrapper_format) {
      $query['_wrapper_format'] = $wrapper_format;
    }
    $this->pushRequest($query);
    return $this->container->get('entity.form_builder')->getForm($this->media, 'edit');
  }

  /**
   * Makes a request for the media edit page, with a query, the current one.
   */
  protected function pushRequest(array $query): void {
    $request_stack = $this->container->get('request_stack');
    $request = Request::create('/media/' . $this->media->id() . '/edit', 'GET', $query);
    $request->setSession($request_stack->getCurrentRequest()->getSession());
    $request_stack->push($request);
  }

}
