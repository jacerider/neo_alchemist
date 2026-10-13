<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Audit\Check;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\Audit\ComponentAuditCheckInterface;

/**
 * Headings, links and buttons with nothing to announce.
 *
 * Each needs a name: its text, an image's alt text inside it, or an
 * aria-label, aria-labelledby or title. One hidden from assistive technology
 * (aria-hidden="true") is left alone.
 */
class EmptyElementsCheck implements ComponentAuditCheckInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'empty_elements';
  }

  /**
   * {@inheritdoc}
   */
  public function check(\DOMDocument $document): array {
    $issues = [];
    $query = '//h1|//h2|//h3|//h4|//h5|//h6|//a[@href]|//button';
    foreach ((new \DOMXPath($document))->query($query) as $element) {
      /** @var \DOMElement $element */
      if ($this->isHidden($element) || $this->hasName($element)) {
        continue;
      }
      $issues[] = [
        'message' => match (TRUE) {
          $element->tagName === 'a' => (string) $this->t('A link has no text or accessible name.'),
          $element->tagName === 'button' => (string) $this->t('A button has no text or accessible name.'),
          default => (string) $this->t('An empty @tag: a heading with no text.', ['@tag' => $element->tagName]),
        },
        'element' => $element,
      ];
    }
    return $issues;
  }

  /**
   * Whether an element, or one around it, is hidden from assistive tech.
   */
  protected function isHidden(\DOMElement $element): bool {
    for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
      if ($node->getAttribute('aria-hidden') === 'true') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether an element has something to be announced by.
   */
  protected function hasName(\DOMElement $element): bool {
    foreach (['aria-label', 'aria-labelledby', 'title'] as $attribute) {
      if (trim($element->getAttribute($attribute)) !== '') {
        return TRUE;
      }
    }
    if (trim(preg_replace('/\s+/u', ' ', $element->textContent) ?? '') !== '') {
      return TRUE;
    }
    foreach ($element->getElementsByTagName('img') as $image) {
      if (trim($image->getAttribute('alt')) !== '') {
        return TRUE;
      }
    }
    foreach ($element->getElementsByTagName('svg') as $svg) {
      if (trim($svg->getAttribute('aria-label')) !== '' || $svg->getElementsByTagName('title')->length) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
