<?php

/**
 * @file
 * Provides token definitions for the Neo Alchemist module.
 */

declare(strict_types=1);

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList;
use Drupal\neo_image\NeoImageStyle;

// /**
//  * Provides definitions for Neo Alchemist tokens.
//  *
//  * These tokens are used to represent entities in a smart way, such as
//  * providing a title or description based on the content of the entity.
//  *
//  * @return array
//  *   An array of token definitions.
//  */
// function neo_alchemist_tokens_definitions() {
//   $data = [];
//   $data['title'] = [
//     'name' => t('Neo Alchemist: Smart Title'),
//     'description' => t('Will determine the best title to use for representing this token.'),
//     'type' => 'neo_alchemist',
//   ];
//   $data['description'] = [
//     'name' => t('Neo Alchemist: Smart Description'),
//     'description' => t('Will determine the best description to use for representing this token.'),
//     'type' => 'neo_alchemist',
//   ];
//   $data['image'] = [
//     'name' => t('Neo Alchemist: Smart Image'),
//     'description' => t('Will determine the best image to use for representing this token. By default, this will return an exact image of 1200x630 pixels. You can specify the effect as parameters, e.g., [neo:image:crop:1200:630].'),
//     'type' => 'neo_alchemist',
//   ];
//   $data['image:logo'] = [
//     'name' => t('Neo Alchemist: Smart Logo'),
//     'description' => t('Will determine the site logo to use for representing this token.'),
//     'type' => 'neo_alchemist',
//   ];
//   $data['image:width'] = [
//     'name' => t('Neo Alchemist: Smart Image Width'),
//     'description' => t('Will determine the best image width to use for representing this token.'),
//     'type' => 'neo_alchemist',
//   ];
//   $data['image:height'] = [
//     'name' => t('Neo Alchemist: Smart Image Height'),
//     'description' => t('Will determine the best image height to use for representing this token.'),
//     'type' => 'neo_alchemist',
//   ];
//   return $data;
// }

// /**
//  * Implements hook_token_info().
//  */
// function neo_alchemist_token_info() {
//   return [
//     'types' => [
//       'neo' => [
//         'name' => t('Neo'),
//         'description' => t('Tokens provided by Neo.'),
//       ],
//     ],
//     'tokens' => [
//       'neo' => neo_alchemist_tokens_definitions(),
//     ],
//   ];
// }

// /**
//  * Implements hook_tokens().
//  */
// function neo_alchemist_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
//   $cache = &drupal_static(__FUNCTION__);
//   if ($type !== 'neo') {
//     return [];
//   }
//   $info = [];
//   foreach ($data as $dataType => $value) {
//     if ($value instanceof ContentEntityInterface) {
//       $alchemistFieldName = neo_alchemist_tokens_field_name($value);
//       if (!$alchemistFieldName || !$value->hasField($alchemistFieldName)) {
//         continue;
//       }
//       $info[$dataType] = $alchemistFieldName;
//       break;
//     }
//   }
//   if (!$info) {
//     return [];
//   }
//   $replacements = [];
//   $definitions = neo_alchemist_tokens_definitions();
//   foreach ($tokens as $name => $original) {
//     foreach ($info as $dataType => $alchemistFieldName) {
//       $entity = $data[$dataType];
//       $params = explode(':', $name);
//       $name = array_shift($params);
//       if (!isset($definitions[$name])) {
//         continue;
//       }
//       $key = implode(':', array_merge([
//         $entity->getEntityTypeId(),
//         $entity->id(),
//         $dataType,
//         $alchemistFieldName,
//         $name,
//       ], $params));
//       if (isset($cache[$key])) {
//         $replacements[$original] = $cache[$key];
//         continue 2;
//       }
//       $field = $entity->get($alchemistFieldName);
//       if ($field->isEmpty()) {
//         continue;
//       }
//       $value = match ($name) {
//         'title' => neo_alchemist_tokens_title($field, $entity),
//         'description' => neo_alchemist_tokens_description($field, $entity),
//         'image' => neo_alchemist_tokens_image($field, $entity, $params),
//         default => NULL,
//       };
//       $cache[$key] = $value;
//       if ($value) {
//         $replacements[$original] = $value;
//         continue 2;
//       }
//     }
//   }
//   return $replacements;
// }

// /**
//  * Retrieves a title for token replacement from a Neo component tree.
//  *
//  * This function attempts to find a title in the following order:
//  * 1. From a 'heading' component with a non-empty 'title' value in the provided
//  *    field.
//  * 2. From the current route's page title.
//  * 3. From the entity's label.
//  *
//  * @param \Drupal\neo\Plugin\Field\FieldType\NeoComponentTreeList $field
//  *   The Neo component field containing potential heading components.
//  * @param \Drupal\Core\Entity\ContentEntityInterface $entity
//  *   The entity being processed.
//  *
//  * @return string|null
//  *   The resolved title value or NULL if no title could be found.
//  *
//  * @see hook_neo_token_title_alter()
//  */
// function neo_alchemist_tokens_title(NeoComponentTreeList $field, ContentEntityInterface $entity) {
//   $value = NULL;

//   $query = $field->getQuery()
//     ->filterByRef('heading')
//     ->filterNonEmpty();
//   if ($shape = $query->first()) {
//     $shapeValue = $shape->getValue();
//     if (!empty($shapeValue['title'])) {
//       $value = $shapeValue['title'];
//     }
//   }

//   if (!$value) {
//     $request = \Drupal::request();
//     $route = $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
//     if ($route) {
//       $title = \Drupal::service('title_resolver')->getTitle($request, $route);
//       $value = token_render_array_value($title);
//     }
//     else {
//       $value = $entity->label();
//     }
//   }

//   // Allow modules to alter the title before using.
//   \Drupal::moduleHandler()->alter('neo_token_title', $value, $entity);

//   return $value;
// }

// /**
//  * Retrieves a description text for token substitution in Neo Alchemist.
//  *
//  * This function attempts to find an appropriate description text based on the
//  * entity type:
//  * - For taxonomy terms, it uses the term description.
//  * - For other entity types, it defaults to NULL.
//  *
//  * The description can be altered by other modules using the
//  * 'neo_token_description' hook. If no description is found or provided by
//  * altering, falls back to the site slogan.
//  *
//  * @param \Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList $field
//  *   The Neo component field.
//  * @param \Drupal\Core\Entity\ContentEntityInterface $entity
//  *   The content entity containing the Neo component field.
//  *
//  * @return string|null
//  *   The description text for token substitution, or NULL if none is available.
//  */
// function neo_alchemist_tokens_description(NeoComponentTreeList $field, ContentEntityInterface $entity) {
//   $value = match($entity->getEntityTypeId()) {
//     'taxonomy_term' => $entity->get('description')->value,
//     default => NULL,
//   };

//   // Allow modules to alter the description before using.
//   \Drupal::moduleHandler()->alter('neo_token_description', $description, $entity);

//   return $value ?: \Drupal::config('system.site')->get('slogan');
// }

// /**
//  * Gets the best image URL to represent an entity for token replacement.
//  *
//  * This function searches for an appropriate image in the following order:
//  * 1. Media reference fields of type 'image'
//  * 2. Neo component tree image components (except on front page)
//  * 3. Neo favicon module images (largest available)
//  *
//  * @param \Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList $field
//  *   The Neo Alchemist component tree field.
//  * @param \Drupal\Core\Entity\ContentEntityInterface $entity
//  *   The entity being processed.
//  * @param array $params
//  *   Additional parameters.
//  *
//  * @return string|null
//  *   A formatted image URL or NULL if no suitable image found.
//  */
// function neo_alchemist_tokens_image(NeoComponentTreeList $field, ContentEntityInterface $entity, array $params = []): ?string {
//   $op = $params[0] ?? NULL;
//   if (in_array($op, ['width', 'height'])) {
//     // If the first parameter is 'width' or 'height', we will return the
//     // respective dimension of the image.
//     $params = array_slice($params, 1);
//   }
//   $url = neo_alchemist_tokens_image_url($field, $entity, $params);
//   if (!$url) {
//     return NULL;
//   }
//   return match ($op) {
//     'width' => ((string) getimagesize($url)[0]) ?? NULL,
//     'height' => ((string) getimagesize($url)[1]) ?? NULL,
//     default => $url,
//   };
// }

// /**
//  * Gets the best image URL to represent an entity for token replacement.
//  *
//  * This function searches for an appropriate image in the following order:
//  * 1. Media reference fields of type 'image'
//  * 2. Neo component tree image components (except on front page)
//  * 3. Neo favicon module images (largest available)
//  *
//  * @param \Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList $field
//  *   The Neo Alchemist component tree field.
//  * @param \Drupal\Core\Entity\ContentEntityInterface $entity
//  *   The entity being processed.
//  * @param array $params
//  *   Additional parameters.
//  *
//  * @return string|null
//  *   A formatted image URL or NULL if no suitable image found.
//  */
// function neo_alchemist_tokens_image_url(NeoComponentTreeList $field, ContentEntityInterface $entity, array $params = []): ?string {
//   $cache = &drupal_static(__FUNCTION__);
//   $key = implode(':', [
//     $entity->getEntityTypeId(),
//     $entity->id(),
//     $field->getFieldDefinition()->getName(),
//   ]);
//   $uri = $cache[$key] ?? NULL;
//   if (!$uri) {
//     $uri = NULL;
//     $moduleHandler = \Drupal::moduleHandler();
//     $isFront = \Drupal::service('path.matcher')->isFrontPage();

//     // Try to get a media image from the entity's fields.
//     $uri = neo_alchemist_tokens_entity_to_media_image_uri($entity);

//     // Try component images if not on front page and no URI found yet.
//     if (!$uri && !$isFront) {
//       $shape = $field->getQuery()
//         ->filterByRef('image')
//         ->filterNonEmpty()
//         ->first();

//       if ($shape && !empty($shapeValue = $shape->getValue()['uri'] ?? NULL)) {
//         $uri = $shapeValue;
//       }
//     }

//     // Allow modules to alter the URI before using.
//     \Drupal::moduleHandler()->alter('neo_token_image', $uri, $entity);

//     // Fall back to Neo Favicon if available.
//     if (!$uri && $moduleHandler->moduleExists('neo_favicon')) {
//       /** @var \Drupal\neo_favicon\FaviconManager $manager */
//       $manager = \Drupal::service('neo_favicon.manager');
//       $bestImage = [];

//       foreach ($manager->getImages() as $imageUri => $dimensions) {
//         $size = $dimensions['width'] * $dimensions['height'];
//         if (empty($bestImage) || $size > ($bestImage['size'] ?? 0)) {
//           $bestImage = [
//             'uri' => $imageUri,
//             'size' => $size,
//           ];
//         }
//       }

//       $uri = $bestImage['uri'] ?? NULL;
//     }

//     $cache[$key] = $uri;
//   }

//   // Format the URI if one was found.
//   if ($uri) {
//     // Provide default parameters if none are given. These defaults are ideal
//     // for meta tags and social media sharing.
//     if (empty($params)) {
//       $params = ['exact', '1200', '630'];
//     }
//     // Format the params for use in the NeoImageStyle.
//     // The first parameter is the option type, e.g., 'exact', 'crop', etc.
//     // The rest are the parameters passed to the style type.
//     $optionType = array_shift($params);
//     $options = [
//       $optionType => $params,
//     ];
//     $neoImageStyle = new NeoImageStyle($options);
//     // $neoImageStyle->exact(1200, 630);
//     return $neoImageStyle->toUrlFromUri($uri);
//   }

//   return NULL;
// }

// /**
//  * Find a media image URI from a content entity.
//  *
//  * This function searches through the entity's fields to find a media
//  * reference field that points to a media item of type 'image'. If such a
//  * media item is found, it retrieves the thumbnail file URI from the media
//  * item and returns it.
//  *
//  * @param \Drupal\Core\Entity\ContentEntityInterface $entity
//  *   The content entity to search for media images.
//  *
//  * @return string|null
//  *   The file URI of the media image thumbnail if found, or NULL if no
//  *   suitable media image is found.
//  */
// function neo_alchemist_tokens_entity_to_media_image_uri(ContentEntityInterface $entity): ?string {
//   $uri = NULL;

//   // Try to get a media image from the entity's fields.
//   /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface[] $mediaFields */
//   $mediaFields = array_filter($entity->getFields(), function ($field) {
//     $definition = $field->getFieldDefinition();
//     return $definition->getType() === 'entity_reference'
//       && $definition->getSetting('target_type') === 'media'
//       && !$field->isEmpty()
//       && in_array(
//         'image',
//         $definition->getSetting('handler_settings')['target_bundles'] ?? [],
//         TRUE
//       );
//   });

//   // Process media fields to find a valid image URI.
//   foreach ($mediaFields as $mediaField) {
//     foreach ($mediaField->referencedEntities() as $media) {
//       if (!$media instanceof MediaInterface || $media->bundle() !== 'image') {
//         continue;
//       }

//       $thumbnailField = $media->get('thumbnail');
//       if (!$thumbnailField->isEmpty() && ($file = $thumbnailField->entity) instanceof FileInterface) {
//         $uri = $file->getFileUri();
//         break 2;
//       }
//     }
//   }

//   return $uri;
// }

// /**
//  * Gets the name of the Neo Alchemist field for a given entity.
//  *
//  * This function searches for a field that uses the 'neo_component_tree'
//  * formatter in the entity's default view display. It caches the results to
//  * improve performance for repeated calls with the same entity type and bundle.
//  *
//  * @param \Drupal\Core\Entity\ContentEntityInterface $entity
//  *   The entity to check.
//  *
//  * @return string|null
//  *   The name of the first Neo Alchemist field found, or NULL if none exists.
//  */
// function neo_alchemist_tokens_field_name(ContentEntityInterface $entity) {
//   $cache = drupal_static(__FUNCTION__, []);
//   if (!isset($cache[$entity->getEntityTypeId()][$entity->bundle()])) {
//     $cache[$entity->getEntityTypeId()][$entity->bundle()] = NULL;
//     /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository */
//     $entity_display_repository = \Drupal::service('entity_display.repository');
//     $display = $entity_display_repository->getViewDisplay($entity->getEntityTypeId(), $entity->bundle());
//     $alchemistFields = array_filter($display->getComponents(), function ($component) {
//       return $component['type'] === 'neo_component_tree';
//     });
//     if ($alchemistFields) {
//       $fieldName = key($alchemistFields);
//       $cache[$entity->getEntityTypeId()][$entity->bundle()] = $fieldName;
//     }
//   }
//   return $cache[$entity->getEntityTypeId()][$entity->bundle()];
// }
