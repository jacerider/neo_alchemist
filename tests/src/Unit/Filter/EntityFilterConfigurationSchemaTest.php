<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Filter;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_alchemist\Plugin\ComponentFilter\EntityFilter;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Every key the entity filter defaults must be a key its schema declares.
 *
 * ComponentFilterPluginBase::setConfiguration() merges defaultConfiguration()
 * underneath the stored settings, so a key declared there is not a suggestion
 * — it is written into `settings.filters.<uuid>.plugin_settings` on the next
 * save of every component carrying the filter, whether anything reads it or
 * not. A key with no schema behind it therefore makes each of those components
 * raise a "missing schema" warning on every later save.
 *
 * That is what `entity_preview` did. Its real home is state:
 * ::configurationValidate() writes the ids there and unsets the submitted
 * value precisely because entity ids are a per-environment editing
 * convenience and do not survive a deploy — and then the default put the key
 * straight back, as NULL.
 *
 * The assertion is the general rule rather than that one key, because the
 * failure mode is silent: nothing reads the stray value, nothing breaks, and
 * the only symptom is a warning on an unrelated save. The next default added
 * without a matching schema entry would behave the same way.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentFilter\EntityFilter::defaultConfiguration()
 * @see \Drupal\neo_alchemist\Filter\ComponentFilterPluginBase::setConfiguration()
 * @see neo_alchemist_update_11011()
 */
#[Group('neo_alchemist')]
class EntityFilterConfigurationSchemaTest extends UnitTestCase {

  /**
   * The schema key the `entity` filter plugin's settings are validated against.
   */
  private const SCHEMA_TYPE = 'neo_alchemist.neo_component_filter.entity';

  /**
   * The plugin's defaults, read without its container collaborators.
   *
   * The method returns a pure literal, so the constructor's three
   * collaborators are never consulted to build it.
   *
   * @return array
   *   The default configuration.
   */
  private function defaults(): array {
    /** @var \Drupal\neo_alchemist\Plugin\ComponentFilter\EntityFilter $plugin */
    $plugin = (new \ReflectionClass(EntityFilter::class))->newInstanceWithoutConstructor();
    return $plugin->defaultConfiguration();
  }

  /**
   * The keys the module's own config schema declares for this plugin.
   *
   * Read from the shipped YAML rather than from a container, so the test needs
   * no kernel: the schema file is what Drupal validates saved config against.
   *
   * @return string[]
   *   The declared key names.
   */
  private function schemaKeys(): array {
    $path = dirname(__DIR__, 4) . '/config/schema/neo_alchemist.schema.yml';
    $this->assertFileExists($path, 'The module ships a config schema file.');
    $schema = Yaml::parseFile($path);
    $this->assertArrayHasKey(
      self::SCHEMA_TYPE,
      $schema,
      self::SCHEMA_TYPE . ' is declared in the module schema.',
    );
    return array_keys($schema[self::SCHEMA_TYPE]['mapping'] ?? []);
  }

  /**
   * No default is written into config that the schema cannot describe.
   *
   * Red/green proof performed during development: restoring
   * `'entity_preview' => NULL` to defaultConfiguration() turns this red,
   * naming that key.
   */
  public function testEveryDefaultKeyHasSchema(): void {
    $undeclared = array_diff(array_keys($this->defaults()), $this->schemaKeys());

    $this->assertSame(
      [],
      array_values($undeclared),
      'defaultConfiguration() declares key(s) with no entry in ' . self::SCHEMA_TYPE
      . ': ' . implode(', ', $undeclared)
      . '. Either add them to the schema, or keep them out of the defaults if '
      . 'they are not configuration.',
    );
  }

  /**
   * The preview ids in particular stay out, since state is where they live.
   *
   * Named on its own so the regression is findable by the key that caused it,
   * and so removing it from the defaults cannot be undone by someone reading
   * only the general rule above and adding a schema entry instead.
   */
  public function testPreviewEntitiesAreNotConfiguration(): void {
    $this->assertArrayNotHasKey('entity_preview', $this->defaults());
    $this->assertNotContains('entity_preview', $this->schemaKeys());
  }

}
