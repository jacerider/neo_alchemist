<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Decides whether a visitor may follow a link a component renders.
 *
 * The obvious check is `Url::access()`, and for most links it is the right
 * one. It goes wrong for a link to an entity's canonical URL whose route is
 * not the entity's view page. Media is the common case: with
 * `media.settings:standalone_url` off (Drupal's default), core points
 * `entity.media.canonical` at `/media/{media}/edit`, which needs
 * `media.update`. A link stored as `entity:media/12` is never rendered as
 * that route. Linkit swaps it for the file URL at render time. But the route
 * check still denied every visitor who could not edit the media item. Editors
 * saw a working download button and customers saw plain text (JOST-422).
 *
 * A canonical link means "this entity", so the question to ask is whether
 * the visitor can view the entity. Every other URL keeps its route check.
 *
 * @see \Drupal\media\Routing\MediaRouteProvider
 * @see \Drupal\neo\Linkit\NeoLinkitResolver::getSubstitutedUrl()
 */
final class LinkAccess {

  /**
   * Checks whether an account may follow a URL.
   *
   * @param \Drupal\Core\Url $url
   *   The URL the link points at.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   (optional) The account to check. Defaults to the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result, carrying its cacheability.
   */
  public static function check(Url $url, ?AccountInterface $account = NULL): AccessResultInterface {
    if ($entity = self::canonicalEntity($url)) {
      return $entity->access('view', $account, TRUE);
    }
    $access = $url->access($account, TRUE);
    return $access instanceof AccessResultInterface ? $access : AccessResult::forbidden();
  }

  /**
   * Loads the entity a canonical entity URL points at.
   *
   * @param \Drupal\Core\Url $url
   *   The URL.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity, or NULL when the URL is not `entity.{type}.canonical` or
   *   the entity does not exist.
   */
  private static function canonicalEntity(Url $url): ?EntityInterface {
    if (!$url->isRouted() || !preg_match('/^entity\.([a-z0-9_]+)\.canonical$/', $url->getRouteName(), $matches)) {
      return NULL;
    }
    $entityTypeId = $matches[1];
    $parameter = $url->getRouteParameters()[$entityTypeId] ?? NULL;
    if ($parameter instanceof EntityInterface) {
      return $parameter;
    }
    if (!is_scalar($parameter) || $parameter === '') {
      return NULL;
    }
    $entityTypeManager = \Drupal::entityTypeManager();
    if (!$entityTypeManager->hasDefinition($entityTypeId)) {
      return NULL;
    }
    return $entityTypeManager->getStorage($entityTypeId)->load($parameter);
  }

}
