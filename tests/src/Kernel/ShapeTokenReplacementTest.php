<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Render\RenderContext;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\linkit\Entity\Profile;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins token replacement in every free-text value, with no plugin attached.
 *
 * It runs on every site's saved content, so what it must NOT do matters as
 * much as what it does: bracketed text that is not a registered token stays,
 * an option key is never touched, and a value lands escaped the way its
 * destination needs (plain for Twig, HTML-escaped inside markup, URL-encoded
 * in a query).
 *
 * @see \Drupal\neo_alchemist\Shape\ShapeTokenReplacer
 */
#[Group('neo_alchemist')]
class ShapeTokenReplacementTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'link',
    'options',
    'file',
    // Live sites run Linkit, which rebuilds a link's URL from the field item,
    // query string included.
    'linkit',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The host node.
   */
  private NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    // Linkit's default profile, so a link to an entity path is substituted.
    $this->installConfig(['system', 'filter', 'node', 'linkit']);
    $this->config('system.site')->set('name', 'Cossetta')->save();
    NodeType::create(['type' => 'room', 'name' => 'Room'])->save();
    // No filters, so the test sees exactly what replacement produced.
    FilterFormat::create(['format' => 'full', 'name' => 'Full', 'filters' => []])->save();
    $this->node = Node::create(['type' => 'room', 'title' => "Frank's <b>& Co", 'status' => 1]);
    $this->node->save();
  }

  /**
   * A string reads the host and stays plain text: Twig escapes it.
   */
  public function testStringReadsHostAsPlainText(): void {
    $values = $this->resolve(['text' => $this->string('Reserve [node:title]')]);

    $this->assertSame("Reserve Frank's <b>& Co", (string) $values['text']);
  }

  /**
   * Bracketed text of no registered type is left as written.
   */
  public function testUnregisteredBracketsAreKept(): void {
    $values = $this->resolve(['text' => $this->string('[Note: call ahead] at [site:name]')]);

    $this->assertSame('[Note: call ahead] at Cossetta', $values['text']);
  }

  /**
   * A registered token that resolves to nothing is removed.
   */
  public function testUnresolvedRegisteredTokenIsCleared(): void {
    $values = $this->resolve(['text' => $this->string('Room [node:no-such-token]')]);

    $this->assertSame('Room ', $values['text']);
  }

  /**
   * An enum value is an option key and is never replaced.
   */
  public function testEnumStringTakesNoTokens(): void {
    $values = $this->resolve(['choice' => $this->string('two', 'choice')]);

    $this->assertSame('two', (string) $values['choice']);
  }

  /**
   * Inside markup, a token value is HTML-escaped.
   */
  public function testMarkupEscapesTokenValues(): void {
    $html = $this->renderValue($this->resolve([
      'body' => [
        'ref' => 'markup',
        'value' => ['value' => '<p><strong>[node:title]</strong></p>'],
        'options' => ['body' => ['default' => 0]],
      ],
    ])['body']);

    $this->assertStringContainsString('<p><strong>Frank&#039;s &lt;b&gt;&amp; Co</strong></p>', $html);
  }

  /**
   * A row link encodes query tokens and leaves path tokens raw.
   */
  public function testRowLinkUriEncodesQueryTokens(): void {
    $nid = $this->node->id();
    $values = $this->resolve([
      'links' => [
        'ref' => 'array',
        'value' => [
          [
            'link' => [
              'uri' => 'internal:/rooms/[node:nid]?room=[node:nid]&name=[node:title]',
              'title' => 'Reserve [node:title]',
              'options' => [],
            ],
          ],
          ['link' => ['uri' => 'internal:/catering', 'title' => 'All rooms', 'options' => []]],
        ],
        'options' => ['links' => ['default' => 0]],
      ],
    ]);

    $this->assertSame("internal:/rooms/$nid?room=$nid&name=Frank%27s%20%3Cb%3E%26%20Co", $values['links'][0]['link']['uri']);
    $this->assertSame("Reserve Frank's <b>& Co", (string) $values['links'][0]['link']['title']);
    $this->assertSame('internal:/catering', $values['links'][1]['link']['uri']);
  }

  /**
   * A row link resolved from the prop's Default Value replaces its tokens.
   *
   * A default row is built without the link's uri and title child shapes, so
   * the link itself has to replace them. This is the saved-component button
   * that sends a room page's visitor to the inquiry form with the room. The
   * path resolves to an entity, so Linkit rebuilds the URL, and the query it
   * copies back must be the resolved one.
   */
  public function testDefaultValueRowLinkReplacesTokens(): void {
    $nid = $this->node->id();
    $link = $this->resolveDefaultRowLink("internal:/node/$nid?room=[node:nid]", 'Reserve [node:title]');

    $this->assertSame("/node/$nid?room=$nid", $link['uri'] ?? NULL);
    $this->assertSame("Reserve Frank's <b>& Co", (string) ($link['title'] ?? ''));
  }

  /**
   * A tokenized link still gets Linkit's substitution, here a file's URL.
   *
   * Linkit is not only a canonical URL builder: a file matcher substitutes the
   * file's own URL for the entity link. Replacing the tokens must not cost the
   * link that.
   */
  public function testTokenizedLinkKeepsLinkitSubstitution(): void {
    $file = File::create(['uri' => 'public://handout.pdf', 'filename' => 'handout.pdf']);
    $file->save();
    $profile = Profile::load('default');
    $profile->addMatcher([
      'id' => 'entity:file',
      'weight' => 1,
      'settings' => ['substitution_type' => 'file'],
    ]);
    $profile->save();

    $link = $this->resolveDefaultRowLink('entity:file/' . $file->id() . '?room=[node:nid]', 'Handout');

    // The file's URL, not the entity link, with the resolved query.
    $this->assertStringEndsWith('/files/handout.pdf?room=' . $this->node->id(), $link['uri'] ?? '');
    $this->assertStringNotContainsString('entity:', $link['uri'] ?? '');
  }

  /**
   * Resolves a links row from the prop's Default Value.
   *
   * @param string $uri
   *   The row link's stored uri.
   * @param string $title
   *   The row link's stored title.
   *
   * @return array
   *   The resolved link.
   */
  private function resolveDefaultRowLink(string $uri, string $title): array {
    $component = $this->buildComponent([]);
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $component->id())
      ->set('settings.props.links.plugins.links.default', [
        'id' => 'default',
        'settings' => [
          'field_type' => 'map',
          'default' => [
            ['link' => ['uri' => $uri, 'title' => $title, 'options' => []]],
          ],
          'options' => ['links' => ['empty' => FALSE, 'default' => FALSE]],
        ],
      ])
      ->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $storage->resetCache([$component->id()]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($component->id());
    $component->setPreview(TRUE);
    $values = $this->inRenderContext(fn () => $component->getPropValues());
    return $values['links'][0]['link'] ?? [];
  }

  /**
   * A url holds its uri and title in one value and replaces both.
   */
  public function testUrlReplacesUriAndTitle(): void {
    $nid = $this->node->id();
    $values = $this->resolve([
      'url' => [
        'ref' => 'url',
        'value' => ['uri' => 'internal:/inquiry?room=[node:nid]', 'title' => '[node:title]', 'options' => []],
        'options' => ['url' => ['default' => 0]],
      ],
    ]);

    $this->assertSame("internal:/inquiry?room=$nid", $values['url']['uri']);
    $this->assertSame("Frank's <b>& Co", $values['url']['title']);
  }

  /**
   * A token read from a row link invalidates with the entity it read.
   */
  public function testRowTokenCarriesEntityCacheTag(): void {
    $component = $this->buildComponent([
      'links' => [
        'ref' => 'array',
        'value' => [
          ['link' => ['uri' => 'internal:/inquiry?room=[node:nid]', 'title' => 'Inquire', 'options' => []]],
        ],
        'options' => ['links' => ['default' => 0]],
      ],
    ]);
    $this->inRenderContext(fn () => $component->getPropValues());

    $this->assertContains('node:' . $this->node->id(), $component->getCacheableMetadata()->getCacheTags());
  }

  /**
   * With no real host, entity tokens clear and global tokens still resolve.
   */
  public function testNoHostClearsEntityTokens(): void {
    $values = $this->resolve(['text' => $this->string('[node:title] at [site:name]')], FALSE);

    $this->assertSame(' at Cossetta', $values['text']);
  }

  /**
   * A stored string prop value.
   *
   * @param string $value
   *   The value.
   * @param string $prop
   *   The prop it is stored for.
   *
   * @return array
   *   The stored prop.
   */
  private function string(string $value, string $prop = 'text'): array {
    return ['ref' => 'string', 'value' => ['value' => $value], 'options' => [$prop => ['default' => 0]]];
  }

  /**
   * Resolves the fixture's prop values.
   *
   * @param array $props
   *   Stored prop values keyed by prop name.
   * @param bool $host
   *   Whether the component previews against the host node.
   *
   * @return array
   *   The resolved values.
   */
  private function resolve(array $props, bool $host = TRUE): array {
    $component = $this->buildComponent($props, $host);
    return $this->inRenderContext(fn () => $component->getPropValues());
  }

  /**
   * Builds the fixture in preview with the given prop values.
   *
   * @param array $props
   *   Stored prop values keyed by prop name.
   * @param bool $host
   *   Whether the component previews against the host node.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The component.
   */
  private function buildComponent(array $props, bool $host = TRUE): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'Token fixture',
      'description' => 'Token fixture',
      'component' => 'neo_alchemist_test:na_token_probe',
      'status' => TRUE,
      'target_entity_type' => $host ? 'node' : NULL,
    ]);
    $component->save();
    $id = $component->id();
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id)
      ->set('settings.props.body.plugins.body.formatted_text', [
        'id' => 'formatted_text',
        'settings' => ['format' => 'full'],
      ])
      ->save();
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);
    if ($host) {
      $component->setTargetPreviewEntity((string) $this->node->id());
    }
    $component->setPreview(TRUE);
    $this->setPreviewValues($component, ['props' => $props]);
    return $component;
  }

  /**
   * Runs a callable in a render context: formatted_text renders.
   *
   * @param callable $callable
   *   The callable.
   *
   * @return mixed
   *   What it returned.
   */
  private function inRenderContext(callable $callable): mixed {
    return $this->container->get('renderer')->executeInRenderContext(new RenderContext(), $callable);
  }

  /**
   * Renders a value to HTML.
   *
   * @param mixed $value
   *   A render array or a string.
   *
   * @return string
   *   The HTML.
   */
  private function renderValue(mixed $value): string {
    if (!is_array($value)) {
      return (string) $value;
    }
    return (string) $this->container->get('renderer')->renderInIsolation($value);
  }

}
