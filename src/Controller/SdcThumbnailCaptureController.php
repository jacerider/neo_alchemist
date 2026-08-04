<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\SdcThumbnailWriter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives a captured PNG and writes it into the SDC's own directory.
 *
 * The body is the raw PNG the in-iframe rasterizer produced — the capture
 * hands back a Blob, so there is nothing to encode, and going through a
 * multipart upload would only add PHP's $_FILES machinery and its separate
 * (lower) upload_max_filesize limit to a payload we already hold.
 *
 * Deliberately thin: every decision lives in the writer, so the button on the
 * preview workspace and this endpoint agree on what is possible.
 *
 * Writability is checked here rather than as a route access check. It is an
 * environment condition, not an authorization decision about the user, and an
 * access check would answer with a Drupal 403 HTML page — turning the most
 * likely real failure ("your component directory is not writable, here is
 * which one") into an unparseable blob by the time it reaches fetch().
 *
 * @see \Drupal\neo_alchemist\SdcThumbnailWriter
 * @see \Drupal\neo_alchemist\Form\SdcPreviewForm::actions()
 */
final class SdcThumbnailCaptureController extends ControllerBase {

  public function __construct(
    private readonly SdcThumbnailWriter $writer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist.sdc_thumbnail_writer'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(string $component, Request $request): JsonResponse {
    try {
      $result = $this->writer->write($component, $request->getContent());
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['message' => $e->getMessage()], $e->getCode() ?: 400);
    }
    catch (\RuntimeException $e) {
      $this->getLogger('neo_alchemist')->error('Thumbnail capture failed for @component: @message', [
        '@component' => $component,
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse(['message' => $e->getMessage()], $e->getCode() ?: 500);
    }

    $this->getLogger('neo_alchemist')->notice('Wrote component thumbnail @path.', [
      '@path' => $result['path'],
    ]);
    return new JsonResponse(['status' => 'ok'] + $result);
  }

}
