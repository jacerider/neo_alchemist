<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Template\Attribute;
use Drupal\neo\NeoLinkitFormatterTrait;
use Drupal\neo_alchemist\NonLinkingUri;

/**
 * Shared URL-shape behavior.
 *
 * Used by UrlShapeBase (scalar URL/URI shapes) and by structured-object
 * shapes like LinkShape that can't extend UrlShapeBase because they need a
 * different parent class.
 */
trait UrlShapeTrait {

  use NeoLinkitFormatterTrait;

  /**
   * Get the default widget settings.
   *
   * @return array
   *   The default widget settings.
   */
  protected function getDefaultWidgetSettings(): array {
    return [
      'icon' => FALSE,
      'target' => TRUE,
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getDefaultSchemaValue(): mixed {
    $value = parent::getDefaultSchemaValue();
    if (!is_array($value)) {
      $value = [
        'uri' => $value,
      ];
    }
    // A component author writes a non-linking heading as `url: '<nolink>'` in
    // an examples block. That bare form is not a valid uri, so neo_uri() would
    // fall through to its `/` fallback and the editor preview would show a
    // link to the front page where the live render shows none.
    //
    // Guarded on the key existing: assigning unconditionally would introduce a
    // NULL `uri` where the example had none, and the prop-def types `uri` as
    // `string` — SDC prop validation rejects a NULL outright.
    if (array_key_exists('uri', $value)) {
      $value['uri'] = NonLinkingUri::normalize($value['uri']);
    }
    $value['options'] = $value['options'] ?? [];
    $value['icon'] = $value['icon'] ?? '';
    $value['target'] = in_array($value['target'] ?? NULL, ['_self', '_blank'], TRUE) ? $value['target'] : '_self';
    $value['access'] = $value['access'] ?? TRUE;
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldItemValue(): array {
    if (!$this->isFieldItemEmpty()) {
      /** @var \Drupal\link\Plugin\Field\FieldType\LinkItem $item */
      $item = $this->fieldItem;
      $value = $item->getValue();
      try {
        // As an object, so the decision carries its cache contexts. This is
        // the authoritative access check for every link on the site, and it
        // chooses between an <a> and a plain wrapper — cached with no context
        // to vary on, an editor's answer is served to anonymous.
        $access = $item->getUrl()->access(NULL, TRUE);
        $this->addCacheableDependency($access);
        $value['access'] = $access->isAllowed();
      }
      catch (\Throwable $e) {
        // Url::fromUri() may TypeError when the underlying uri is not a string
        // (e.g. a misconfigured matcher mapped a whole link field onto the
        // uri subproperty). Treat that as inaccessible rather than fatal.
        $value['access'] = $value['access'] ?? TRUE;
      }
      return $value;
    }
    return [];
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $value = parent::preRenderValue($value, $attributes);
    if (!empty($value)) {
      // Check if we have Linkit data attributes in options['attributes'].
      // The Neo Link widget stores them there, but getLinkitUrl() expects
      // them directly in options for proper entity URL substitution.
      $fieldItem = $this->getFieldItem();
      if ($url = $this->getLinkitUrl($fieldItem)) {
        $value['uri'] = $url->toString();
      }

      // A link field saved as <nolink>/<none>/<button> — what the Neo link
      // widget writes for a "no destination" choice — arrives here as the
      // truthy string `route:<nolink>`. Blank it so templates that guard on
      // `uri` render a non-link instead of an <a href="">.
      if (array_key_exists('uri', $value)) {
        $value['uri'] = NonLinkingUri::normalize($value['uri']);
      }

      // A link field with no link text stores `title` as NULL, and every
      // link-shaped prop-def types it as `string` — SDC prop validation
      // rejects a NULL outright and white-screens the whole page. This is the
      // norm rather than the exception: UrlShape disables the widget's title
      // input entirely, and an entity link field bound through a value
      // provider only carries a title when the site builder enabled one.
      if (array_key_exists('title', $value) && is_null($value['title'])) {
        $value['title'] = '';
      }

      // Use target if passed in with the options.
      $value = $this->liftTargetFromOptions($value);
      // Guarantee a value in the target enum. A blank or otherwise invalid
      // target (e.g. '0' from a formatter's "no target" option, or a value
      // supplied by a value provider) would fail SDC prop validation and
      // white-screen the page, so fall back to '_self'.
      if (!in_array($value['target'] ?? NULL, ['_self', '_blank'], TRUE)) {
        $value['target'] = '_self';
      }
      unset($value['options']['attributes']);
    }
    return $value;
  }

  /**
   * Promotes the link widget's target attribute to the shape's target value.
   *
   * The Neo Link widget records "open in a new window" as
   * options.attributes.target, while the shape exposes it to twig as a
   * top-level `target`. This must run before anything backfills `target` from
   * the schema default — once that fills in '_self' the attribute is no longer
   * distinguishable from an authored choice and would be silently discarded.
   *
   * @param mixed $value
   *   The link value.
   *
   * @return mixed
   *   The value, with `target` filled in from the options attributes when the
   *   widget recorded one and nothing has set `target` yet.
   */
  protected function liftTargetFromOptions(mixed $value): mixed {
    if (is_array($value) && empty($value['target']) && !empty($value['options']['attributes']['target'])) {
      $value['target'] = $value['options']['attributes']['target'];
    }
    return $value;
  }

  /**
   * Matches the field definition type with the entity field definition type.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   *
   * @return bool
   *   TRUE if the field definition types match, FALSE otherwise.
   */
  public function supportsFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool {
    return $entityFieldDefinition->getType() === 'link';
  }

}
