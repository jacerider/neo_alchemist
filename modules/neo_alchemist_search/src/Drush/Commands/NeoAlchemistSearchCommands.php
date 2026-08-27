<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search\Drush\Commands;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_alchemist_search\ComponentTextExtractor;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reports what the search text extractor can and cannot see.
 *
 * The failure mode this module has to defend against is silence. A layout can
 * legitimately contribute no text at all, so an empty result never looks wrong
 * on its own — which also means a value plugin that ought to name a field but
 * doesn't would go unnoticed. Printing what each bundle resolves to, alongside
 * the plugins that declared nothing, is how that becomes visible before it
 * reaches an index.
 */
final class NeoAlchemistSearchCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs a NeoAlchemistSearchCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads sample entities.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Finds the bundles carrying a component tree.
   * @param \Drupal\neo_alchemist_search\ComponentTextExtractor $extractor
   *   Resolves bindings and extracts text.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'entity_field.manager')]
    private readonly EntityFieldManagerInterface $entityFieldManager,
    #[Autowire(service: 'neo_alchemist_search.text_extractor')]
    private readonly ComponentTextExtractor $extractor,
  ) {
    parent::__construct();
  }

  /**
   * Reports the entity fields each Alchemist layout exposes to search.
   */
  #[CLI\Command(name: 'neo:alchemist:search:report', aliases: ['neoa-search-report'])]
  #[CLI\Option(name: 'bundle', description: 'Limit to one bundle, as entity_type:bundle.')]
  #[CLI\Usage(name: 'drush neoa-search-report', description: 'Report every bundle carrying a component tree.')]
  #[CLI\Usage(name: 'drush neoa-search-report --bundle=node:career', description: 'Report one bundle.')]
  public function report(array $options = ['bundle' => NULL]): int {
    $filter = is_string($options['bundle'] ?? NULL) ? $options['bundle'] : NULL;
    $map = $this->entityFieldManager->getFieldMapByFieldType('neo_component_tree');
    foreach ($map as $entityTypeId => $fields) {
      foreach ($fields as $fieldName => $info) {
        foreach ($info['bundles'] ?? [] as $bundle) {
          if ($filter !== NULL && $filter !== $entityTypeId . ':' . $bundle) {
            continue;
          }
          $entity = $this->sampleEntity($entityTypeId, $bundle);
          if ($entity === NULL) {
            $this->io()->writeln(sprintf('<comment>%s.%s.%s — no entity to sample</comment>', $entityTypeId, $bundle, $fieldName));
            continue;
          }

          $set = $this->extractor->bindingsFor($entity, $fieldName);
          $this->io()->section(sprintf('%s.%s.%s', $entityTypeId, $bundle, $fieldName));

          if ($set->descriptors === []) {
            $this->io()->writeln('  <comment>no bound fields</comment>');
          }
          foreach ($set->descriptors as $descriptor) {
            $this->io()->writeln(sprintf(
              '  %-46s <info>%s</info>/%s via %s',
              $descriptor->fieldKey,
              $descriptor->componentId,
              $descriptor->shapeId,
              $descriptor->pluginId,
            ));
          }

          if ($set->silent !== []) {
            $this->io()->writeln(sprintf('  <comment>declare no fields: %s</comment>', $this->format($set->silent)));
          }

          $texts = $this->extractor->extract($entity);
          $this->io()->writeln(sprintf(
            '  <info>sample</info> %s (entity %s): %d run(s)%s',
            $entity->label() ?? '',
            $entity->id(),
            count($texts),
            $texts === [] ? ' <comment>— nothing extracted</comment>' : '',
          ));
          foreach (array_slice($texts, 0, 3) as $text) {
            $this->io()->writeln('    · ' . mb_strimwidth($text, 0, 96, '…'));
          }
        }
      }
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Formats a plugin tally for display.
   *
   * @param array<string, int> $tally
   *   Plugin ids and counts.
   *
   * @return string
   *   A comma-separated summary.
   */
  private function format(array $tally): string {
    ksort($tally);
    $parts = [];
    foreach ($tally as $id => $count) {
      $parts[] = $id . ' (' . $count . ')';
    }
    return implode(', ', $parts);
  }

  /**
   * Loads one entity of a bundle to resolve and sample against.
   *
   * @param string $entityTypeId
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   An entity, or NULL when the bundle has no content yet.
   */
  private function sampleEntity(string $entityTypeId, string $bundle): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $definition = $this->entityTypeManager->getDefinition($entityTypeId);
    $bundleKey = $definition->getKey('bundle');
    $query = $storage->getQuery()->accessCheck(FALSE)->range(0, 1);
    if ($bundleKey) {
      $query->condition($bundleKey, $bundle);
    }
    $ids = $query->execute();
    if ($ids === []) {
      return NULL;
    }
    $entity = $storage->load(reset($ids));
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

}
