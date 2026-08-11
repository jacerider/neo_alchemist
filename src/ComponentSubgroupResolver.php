<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Resolves the sub-group a component belongs to within its group.
 *
 * A component group opts into a second level of grouping by declaring a
 * "subgroup" strategy in MODULE.neo_component_groups.yml. The only supported
 * strategy is "target_entity", which splits a group by each component's target
 * entity type and bundle — data every component already stores, so no
 * additional configuration is required.
 *
 * @see \Drupal\neo_alchemist\ComponentGroupPluginManager
 */
final class ComponentSubgroupResolver {

  use StringTranslationTrait;

  /**
   * The sub-group strategy that splits by target entity type and bundle.
   */
  public const STRATEGY_TARGET_ENTITY = 'target_entity';

  /**
   * The sub-group id used for components without any entity targeting.
   */
  public const ANY_ID = '_any';

  /**
   * Memoized bundle label lookups, keyed by entity type id.
   *
   * @var array<string, array<string, string>>
   */
  protected array $bundleLabels = [];

  /**
   * Constructs a ComponentSubgroupResolver object.
   */
  public function __construct(
    protected ComponentGroupPluginManager $componentGroupManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {}

  /**
   * Whether a group is split into sub-groups.
   *
   * @param string $groupId
   *   The component group id.
   *
   * @return bool
   *   TRUE if the group declares a supported sub-group strategy.
   */
  public function hasSubgroups(string $groupId): bool {
    $definitions = $this->componentGroupManager->getDefinitions();
    return ($definitions[$groupId]['subgroup'] ?? '') === self::STRATEGY_TARGET_ENTITY;
  }

  /**
   * Resolves the sub-group of a component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component.
   *
   * @return array|null
   *   An array with 'id', 'label' and 'sort' keys, or NULL when the
   *   component's group is not sub-grouped.
   */
  public function resolve(ComponentInterface $component): ?array {
    if (!$this->hasSubgroups($component->getGroup())) {
      return NULL;
    }
    return $this->resolveTargetEntity($component->getTargetEntityTypeId(), $component->getTargetEntityBundle());
  }

  /**
   * Resolves a sub-group from a target entity type and bundle.
   *
   * @param string|null $entityTypeId
   *   The target entity type id, or NULL/empty for any entity type.
   * @param string|null $bundle
   *   The target bundle, or NULL/empty for any bundle.
   *
   * @return array
   *   An array with 'id', 'label' and 'sort' keys.
   */
  public function resolveTargetEntity(?string $entityTypeId, ?string $bundle): array {
    if (!$entityTypeId) {
      // Sorted last: a component in a content-specific group that targets
      // nothing is the exception, not a peer of the real targets.
      return [
        'id' => self::ANY_ID,
        'label' => (string) $this->t('(All)'),
        'sort' => [1, '', ''],
      ];
    }

    $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    $entityTypeLabel = $definition ? (string) $definition->getLabel() : $entityTypeId;
    // The icon elements stringify to rendered markup, so they can only be
    // concatenated into a value that stays flagged as safe — a plain string
    // would be escaped on output, leaking the markup (and, with twig debug on,
    // the THEME DEBUG comments) into the label. The undecorated labels are what
    // the id and sort keys are built from.
    $entityTypeIcon = $definition ? neo_icon_entity_type($definition) : neo_icon($entityTypeLabel);

    if (!$bundle) {
      return [
        'id' => $entityTypeId . ':',
        'label' => Markup::create($entityTypeIcon . ' › ' . $this->t('(any bundle)')),
        // The empty bundle sorts first within its entity type.
        'sort' => [0, $entityTypeLabel, ''],
      ];
    }

    $bundleLabel = $this->getBundleLabel($entityTypeId, $bundle);
    $bundleIcon = neo_icon($bundleLabel, NULL, NULL, ['entity.' . $entityTypeId]);
    return [
      'id' => $entityTypeId . ':' . $bundle,
      'label' => Markup::create($entityTypeIcon . ' › ' . $bundleIcon),
      'sort' => [0, $entityTypeLabel, $bundleLabel],
    ];
  }

  /**
   * Sorts sub-groups keyed by sub-group id.
   *
   * @param array $subgroups
   *   Sub-groups keyed by id. Each value must carry the 'sort' key produced by
   *   ::resolve().
   *
   * @return array
   *   The sorted sub-groups, keys preserved.
   */
  public function sortSubgroups(array $subgroups): array {
    uasort($subgroups, function (array $a, array $b) {
      $sortA = $a['sort'] ?? [0, '', ''];
      $sortB = $b['sort'] ?? [0, '', ''];
      return $sortA[0] <=> $sortB[0]
        ?: strnatcasecmp((string) $sortA[1], (string) $sortB[1])
        ?: strnatcasecmp((string) $sortA[2], (string) $sortB[2]);
    });
    return $subgroups;
  }

  /**
   * Gets a human readable bundle label.
   *
   * @param string $entityTypeId
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   *
   * @return string
   *   The bundle label, falling back to the machine name.
   */
  public function getBundleLabel(string $entityTypeId, string $bundle): string {
    if (!isset($this->bundleLabels[$entityTypeId])) {
      $this->bundleLabels[$entityTypeId] = array_map(
        fn ($info) => (string) ($info['label'] ?? ''),
        $this->entityTypeBundleInfo->getBundleInfo($entityTypeId)
      );
    }
    return $this->bundleLabels[$entityTypeId][$bundle] ?? $bundle;
  }

  /**
   * Builds a human readable label for a component's entity targeting.
   *
   * Used outside of sub-grouping — e.g. the "Scope" column of the component
   * listing — so machine names never leak into the UI.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component.
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The target label, safe for output.
   */
  public function getTargetLabel(ComponentInterface $component): MarkupInterface|string {
    $entityTypeId = $component->getTargetEntityTypeId();
    if (!$entityTypeId) {
      return $this->t('All');
    }
    $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    // The icon element already renders the label next to its icon, so the
    // plain label must not be appended a second time.
    $label = (string) ($definition ? neo_icon_entity_type($definition) : neo_icon($entityTypeId));
    if ($bundle = $component->getTargetEntityBundle()) {
      $label .= ' › ' . neo_icon($this->getBundleLabel($entityTypeId, $bundle), NULL, NULL, ['entity.' . $entityTypeId]);
    }
    return Markup::create($label);
  }

  /**
   * Builds the plain text equivalent of ::getTargetLabel().
   *
   * The listing indexes its rows for client side filtering, so the target has
   * to be searchable without the icon markup being matched against.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component.
   *
   * @return string
   *   The target label, without markup.
   */
  public function getTargetTextLabel(ComponentInterface $component): string {
    $entityTypeId = $component->getTargetEntityTypeId();
    if (!$entityTypeId) {
      return (string) $this->t('All');
    }
    $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    $label = $definition ? (string) $definition->getLabel() : $entityTypeId;
    if ($bundle = $component->getTargetEntityBundle()) {
      $label .= ' › ' . $this->getBundleLabel($entityTypeId, $bundle);
    }
    return $label;
  }

}
