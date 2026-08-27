<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist_search\Kernel;

use Drupal\Tests\neo_alchemist\Kernel\HybridFieldKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers which value plugins are followed to a field, and which are not.
 *
 * This is where most of a typical site's searchable text comes from. A locked
 * layout stores nothing per entity, so if the resolver stops recognising the
 * plugin that feeds a prop, that bundle silently drops out of search results
 * with nothing to show for it — the authored extractor will still be returning
 * an empty array, exactly as it is supposed to.
 */
#[Group('neo_alchemist_search')]
final class BindingResolverTest extends HybridFieldKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
    'neo_alchemist_search',
  ];

  /**
   * A plugin that reads a field on the host entity becomes a binding.
   */
  public function testHostFieldBindingIsFollowed(): void {
    $this->bindPlugin('entity', ['field' => 'field_body', 'field_fallback' => 'field_summary']);

    $keys = $this->resolvedKeys();
    $this->assertContains('field_body', $keys);
    // The fallback is shown when the primary is empty, so it is on the page
    // just as often.
    $this->assertContains('field_summary', $keys);
  }

  /**
   * A reference binding is followed exactly one hop.
   */
  public function testReferenceBindingIsFollowedOneHop(): void {
    $this->bindPlugin('entity_reference', [
      'entity' => 'field_ref:entity',
      'shape_fields' => [
        'title' => ['field' => 'name'],
        'link' => ['field' => '_entity:link:canonical'],
      ],
    ]);

    $keys = $this->resolvedKeys();
    $this->assertContains('field_ref:entity.name', $keys);
    // A canonical URL is not text, and the pseudo-field would not resolve to a
    // readable field anyway.
    $this->assertNotContains('field_ref:entity._entity:link:canonical', $keys);
  }

  /**
   * A key crossing more than one reference is refused.
   *
   * Two hops is where "fields describing this entity" turns into "fields
   * describing something else entirely", which is the sprawl that made
   * indexing rendered output unusable.
   */
  public function testMultiHopBindingIsRefused(): void {
    $this->bindPlugin('entity_reference', [
      'entity' => 'field_ref:entity',
      'shape_fields' => ['title' => ['field' => 'field_other:entity.name']],
    ]);

    $this->assertSame([], $this->resolvedKeys());
  }

  /**
   * A heading binding follows its per-field source and nothing else.
   */
  public function testHeadingBindingFollowsFieldSourceOnly(): void {
    $this->bindPlugin('heading', [
      'title_field' => 'field_headline',
      'title_page' => TRUE,
      'title_value' => 'Literal chrome',
      'subtitle_empty' => TRUE,
      'subtitle_field' => 'field_never_shown',
    ]);

    $keys = $this->resolvedKeys();
    $this->assertContains('field_headline', $keys);
    // A sub-prop configured to render nothing contributes nothing.
    $this->assertNotContains('field_never_shown', $keys);
  }

  /**
   * A provider that reads no host-entity field contributes nothing.
   */
  public function testCrossEntityProvidersAreSkipped(): void {
    $this->bindPlugin('views', []);

    $set = $this->resolve();
    $this->assertSame([], $set->descriptors);
    $this->assertArrayHasKey('views', $set->silent, 'A views provider reads no host field and says so by not declaring one.');
  }

  /**
   * A token string contributes the fields it names, not its literal text.
   */
  public function testTokenBindingContributesReferencedField(): void {
    $this->bindPlugin('token', ['value' => 'View [term:field_name_singular] Projects']);

    $keys = $this->resolvedKeys();
    $this->assertContains('field_name_singular', $keys);
    $this->assertCount(1, $keys, 'The literal words around a token are bundle-constant chrome.');
  }

  /**
   * A token naming entity metadata rather than a field contributes nothing.
   */
  public function testTokenBindingIgnoresNonFieldTokens(): void {
    $this->bindPlugin('token', ['value' => '[node:title] — [node:url]']);

    $this->assertSame([], $this->resolvedKeys());
  }

  /**
   * An inactive prop is not on the page, so its bindings are not read.
   */
  public function testInactivePropIsIgnored(): void {
    $this->bindPlugin('entity', ['field' => 'field_body']);
    $this->config('neo_alchemist.neo_component.na_region_host')
      ->set('settings.props.body.active', FALSE)
      ->save();
    $this->resetFieldCaches('na_region_host');

    $this->assertSame([], $this->resolvedKeys());
  }

  /**
   * Editing a component rebuilds the memoised bindings.
   *
   * The resolver caches per component, so a stale entry would keep indexing a
   * field the layout no longer shows — and keep missing the one it now does.
   */
  public function testEditingComponentInvalidatesCache(): void {
    $this->bindPlugin('entity', ['field' => 'field_before']);
    $this->assertContains('field_before', $this->resolvedKeys());

    $this->bindPlugin('entity', ['field' => 'field_after']);
    // What a component save triggers in a real request: the persistent cache
    // is dropped by its config tag, the in-request memo by this.
    $this->container->get('neo_alchemist_search.binding_resolver')->reset();
    $keys = $this->resolvedKeys();
    $this->assertContains('field_after', $keys);
    $this->assertNotContains('field_before', $keys);
  }

  /**
   * Writes a value plugin onto a fixture component's prop, via raw config.
   *
   * Raw config rather than the entity API, because Component::preSave()
   * regenerates settings.props from the live shapes and wipes hand-set plugin
   * configuration.
   *
   * @param string $pluginId
   *   The value plugin id.
   * @param array $settings
   *   Its settings.
   * @param string $componentId
   *   The component to write to.
   * @param string $shapeId
   *   The prop and shape to bind.
   */
  private function bindPlugin(string $pluginId, array $settings, string $componentId = 'na_region_host', string $shapeId = 'body'): void {
    $this->config("neo_alchemist.neo_component.$componentId")
      ->set("settings.props.$shapeId.plugins.$shapeId.$pluginId", [
        'id' => $pluginId,
        'settings' => $settings,
      ])
      // Saved as trusted data so the kernel test's schema checker stays out of
      // the way. These fixtures deliberately include settings shapes no schema
      // describes — including a plugin that does not exist — because handling
      // exactly that is what is under test.
      ->save(TRUE);
    $this->resetFieldCaches($componentId);
  }

  /**
   * Resolves the fixture component's bindings.
   *
   * @return \Drupal\neo_alchemist_search\Binding\BindingSet
   *   The resolved set.
   */
  private function resolve() {
    return $this->container->get('neo_alchemist_search.binding_resolver')
      ->resolve('na_region_host');
  }

  /**
   * The field keys the fixture component declares.
   *
   * @return string[]
   *   The resolved field keys.
   */
  private function resolvedKeys(): array {
    return array_map(
      static fn ($descriptor) => $descriptor->fieldKey,
      $this->resolve()->descriptors,
    );
  }

}
