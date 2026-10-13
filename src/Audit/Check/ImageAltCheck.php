<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Audit\Check;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\Audit\ComponentAuditCheckInterface;

/**
 * Images without usable alternative text.
 *
 * Every image needs an alt attribute: text describing it, or empty for one
 * that is decorative. A file name is neither.
 */
class ImageAltCheck implements ComponentAuditCheckInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'image_alt';
  }

  /**
   * {@inheritdoc}
   */
  public function check(\DOMDocument $document): array {
    $issues = [];
    foreach ($document->getElementsByTagName('img') as $image) {
      if (!$image->hasAttribute('alt')) {
        $issues[] = [
          'message' => (string) $this->t('An image has no alt attribute. Describe it, or give it alt="" if it is decorative.'),
          'element' => $image,
        ];
        continue;
      }
      $alt = trim($image->getAttribute('alt'));
      $file = basename((string) parse_url($image->getAttribute('src'), PHP_URL_PATH));
      if ($alt !== '' && (preg_match('/\.(jpe?g|png|gif|webp|avif|svg)$/i', $alt) || $alt === $file)) {
        $issues[] = [
          'message' => (string) $this->t('An image’s alt text is its file name, "@alt". Describe what it shows.', ['@alt' => $alt]),
          'element' => $image,
        ];
      }
    }
    return $issues;
  }

}
