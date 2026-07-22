<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Render\BubbleableMetadata;

/**
 * Adds token replacement against the component's target entity.
 *
 * Value plugins that mix this in let a site builder enter a token template
 * (e.g. "View [term:name] Projects" or "internal:/projects?market=[term:tid]")
 * that is resolved at render time against the entity the component is attached
 * to. It is entity-type agnostic — the token type is derived from the shape's
 * target entity, so the same plugin works on term, node, user, … pages.
 *
 * Requires the "token" contrib module for entity-type-to-token-type mapping and
 * the token browser; without it, replacement still runs using the entity type
 * id as the token type and the browser is simply omitted.
 */
trait ComponentValueTokenTrait {

  /**
   * Replace tokens in a template against the component's target entity.
   *
   * Bubbles token + entity cacheability onto the shape so the rendered value
   * invalidates correctly when the source entity changes.
   *
   * @param string $template
   *   The raw template, possibly containing tokens.
   *
   * @return string
   *   The template with tokens replaced. Unresolved tokens are cleared.
   */
  protected function replaceEntityTokens(string $template): string {
    // Cheap opt-out: nothing token-like, so skip loading the entity entirely.
    if ($template === '' || !str_contains($template, '[')) {
      return $template;
    }
    $entity = $this->shape->getEntity();
    $tokenType = $this->getEntityTokenType($entity->getEntityTypeId());
    $metadata = new BubbleableMetadata();
    $replaced = \Drupal::token()->replace(
      $template,
      $tokenType ? [$tokenType => $entity] : [],
      ['clear' => TRUE],
      $metadata,
    );
    $this->shape->addCacheableDependency($metadata);
    $this->shape->addCacheableDependency($entity);
    return $replaced;
  }

  /**
   * Get the token type for an entity type.
   *
   * @param string|null $entityTypeId
   *   The entity type id. Defaults to the shape's target entity type.
   *
   * @return string|null
   *   The token type (e.g. "term", "node"), or NULL if none can be resolved.
   */
  protected function getEntityTokenType(?string $entityTypeId = NULL): ?string {
    $entityTypeId = $entityTypeId ?? $this->shape->getTargetEntityType();
    if (!$entityTypeId) {
      return NULL;
    }
    $container = \Drupal::getContainer();
    if ($container->has('token.entity_mapper')) {
      return $container->get('token.entity_mapper')->getTokenTypeForEntityType($entityTypeId);
    }
    // Fallback when the token module is absent: most entity types use their id
    // as the token type; the well-known exceptions are handled here.
    return match ($entityTypeId) {
      'taxonomy_term' => 'term',
      'taxonomy_vocabulary' => 'vocabulary',
      default => $entityTypeId,
    };
  }

  /**
   * Attach token validation to a template form element.
   *
   * @param array $element
   *   The form element (a textfield/textarea) that accepts a token template.
   *
   * @return array
   *   The element with token validation attached (unchanged when no token type
   *   can be resolved).
   */
  protected function attachTokenValidation(array $element): array {
    $tokenType = $this->getEntityTokenType();
    if (!$tokenType) {
      return $element;
    }
    $element['#element_validate'][] = 'token_element_validate';
    $element['#token_types'] = [$tokenType];
    return $element;
  }

  /**
   * Build a token browser element scoped to the shape's target entity type.
   *
   * @return array
   *   A "token_tree_link" render array, or an empty array when the token module
   *   is not installed or no token type can be resolved.
   */
  protected function buildTokenBrowser(): array {
    $tokenType = $this->getEntityTokenType();
    if (!$tokenType || !\Drupal::moduleHandler()->moduleExists('token')) {
      return [];
    }
    return [
      '#theme' => 'token_tree_link',
      '#token_types' => [$tokenType],
      '#global_types' => FALSE,
    ];
  }

}
