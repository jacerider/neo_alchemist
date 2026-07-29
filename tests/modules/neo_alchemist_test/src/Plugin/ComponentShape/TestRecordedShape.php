<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Plugin\ComponentShape\StringShape;

/**
 * A string shape for observing the value pipeline.
 *
 * Behaves exactly like a string. The recording/cache-tag plugins are NOT
 * default_plugins here on purpose: a root prop allows configurable plugins,
 * so init() would not auto-activate them anyway. Tests activate them by
 * writing `settings.props.<prop>.plugins.<shape>` raw config after the
 * component's first save — which also lets each test control the saved
 * (drag-and-drop) order the pipeline must be proven to override.
 */
#[ComponentShape(
  prop: 'test_recorded',
  label: new TranslatableMarkup('Test recorded string'),
  default_field_type: 'string',
  default_field_widget: 'string_textfield',
)]
class TestRecordedShape extends StringShape {

}
