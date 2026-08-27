<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist_search\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Value\ComponentValueFieldSourceInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The value plugins that read entity fields still say so.
 *
 * Nothing here keeps a central list in sync any more — a plugin declares
 * whether it reads host-entity fields on itself, so there is no list to drift.
 * What is still worth pinning is that the four plugins the suite ships which
 * *do* read fields have not quietly lost the declaration, because losing it is
 * silent: the layout keeps rendering, and only search results go thin.
 *
 * @see \Drupal\neo_alchemist\Value\ComponentValueFieldSourceInterface
 */
#[Group('neo_alchemist_search')]
final class ValuePluginCoverageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_search',
  ];

  /**
   * The plugins that read entity fields still declare that they do.
   *
   * What each one reports for a given configuration is pinned upstream as a
   * pure unit test; what this adds is that the declaration survives discovery,
   * so a plugin cannot be found by the manager while its interface has been
   * dropped.
   *
   * @see \Drupal\Tests\neo_alchemist\Unit\ComponentValueFieldSourceTest
   */
  public function testFieldReadingProvidersDeclareThemselves(): void {
    $manager = $this->container->get('plugin.manager.neo_component_value');
    foreach (['entity', 'entity_reference', 'heading', 'token'] as $pluginId) {
      $definition = $manager->getDefinition($pluginId);
      $this->assertTrue(
        is_a($definition['class'], ComponentValueFieldSourceInterface::class, TRUE),
        sprintf('%s reads entity fields and must declare itself a field source.', $pluginId),
      );
    }
  }

  /**
   * Providers of cross-entity or site-wide content declare no field source.
   *
   * Not an exhaustive list — the point is the shape of the answer. A views
   * result belongs to other entities and a menu is the same on every page, so
   * neither has a host-entity field to name, and reading one would put another
   * entity's text into this one's index entry.
   */
  public function testCrossEntityProvidersDeclareNothing(): void {
    $manager = $this->container->get('plugin.manager.neo_component_value');
    foreach (['views', 'menu', 'breadcrumb', 'entity_query', 'default', 'page_title'] as $pluginId) {
      $definition = $manager->getDefinition($pluginId, FALSE);
      if ($definition === NULL) {
        // Provided by a module this environment does not install.
        continue;
      }
      $this->assertFalse(
        is_a($definition['class'], ComponentValueFieldSourceInterface::class, TRUE),
        sprintf('%s does not read a host-entity field and must not declare one.', $pluginId),
      );
    }
  }

}
