<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ChildrenMatch;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * A source of entities for a children-match mapping.
 *
 * The mapping itself — the Property/Source table, the pseudo fields, the
 * published policy, the delta-versus-property-map decision — belongs to
 * \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper and is identical for every
 * producer. A source supplies only the part that genuinely varies: which
 * entities to iterate, and the form that configures how they are found.
 *
 * Implement this instead of mixing in mapping machinery. The mapper is a
 * service, so a source declares it as a constructor argument and cannot
 * silently omit a collaborator the mapping needs.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchFieldSourceInterface
 */
interface ChildrenMatchSourceInterface extends PluginInspectionInterface {

  /**
   * Resolves the entities to iterate.
   *
   * Called once per mapping. Implementations that execute a query or walk a
   * view should do it here rather than in provideDefaultValue(), so the work
   * happens exactly once.
   *
   * @return \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult
   *   The resolved entities, or one of the two empty outcomes. Choosing
   *   between them is the whole of a source's render-time contract — read
   *   ChildrenMatchResult before picking one.
   */
  public function getChildrenMatchEntities(): ChildrenMatchResult;

  /**
   * Builds the form that configures how the entities are found.
   *
   * The mapper calls this first and appends the mapping table to whatever it
   * added, so the source's own controls keep their position, their #parents
   * and their ajax wrappers.
   *
   * @param array $form
   *   The provider subform, by reference. Its '#id' is the ajax wrapper the
   *   mapping table refreshes into and must already be set.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope|null
   *   The entity type and bundle the mapping table binds against, or NULL when
   *   the source is not configured far enough to map anything yet — in which
   *   case no mapping table is shown.
   */
  public function buildChildrenMatchSourceForm(array &$form, FormStateInterface $form_state): ?ChildrenMatchScope;

}
