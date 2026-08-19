<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Utility\Token;
use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\ComponentShapeContextInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\TokenValue;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the `token` value modifier's template substitution.
 *
 * The plugin weaves the incoming value into a site-builder template via the
 * `[value]` placeholder and then resolves entity tokens. The value it is
 * handed comes from whatever ran before it in the pipeline, so it is not
 * guaranteed to be a string — and casting a non-scalar into the template
 * emitted an "Array to string conversion" warning and substituted the literal
 * "Array" into rendered output.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\TokenValue
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueTokenTrait
 */
#[Group('neo_alchemist')]
class TokenValueTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * The token service double, recording what it was asked to replace.
   */
  private ?string $replacedTemplate = NULL;

  /**
   * Builds the plugin with a stubbed shape and token service.
   *
   * The token service echoes the template back so the assertions are about
   * this plugin's substitution, not about core's token replacement.
   *
   * @param string $template
   *   The configured template.
   */
  private function plugin(string $template): TokenValue {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');

    // Resolving tokens is entirely a question of what the shape is attached
    // to, so the context role is the whole of what it answers.
    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $context->method('getEntity')->willReturn($entity);
    $context->method('getTargetEntityType')->willReturn('node');
    $shape = $this->shapeDouble([$context]);

    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnCallback(function (string $text): string {
      $this->replacedTemplate = $text;
      return str_replace('[node:title]', 'RESOLVED TITLE', $text);
    });

    $container = new ContainerBuilder();
    $container->set('token', $token);
    \Drupal::setContainer($container);

    return new TokenValue('token', [], $shape, ['value' => $template]);
  }

  /**
   * Scalars are woven into the template unchanged.
   *
   * @return array
   *   Cases of [incoming value, expected substitution].
   */
  public static function scalarCases(): array {
    return [
      'string' => ['AUTHORED', 'before AUTHORED after'],
      'falsy string zero' => ['0', 'before 0 after'],
      'integer zero' => [0, 'before 0 after'],
      'integer' => [42, 'before 42 after'],
      'float' => [1.5, 'before 1.5 after'],
      'TRUE' => [TRUE, 'before 1 after'],
      'FALSE' => [FALSE, 'before  after'],
      'NULL' => [NULL, 'before  after'],
      'empty string' => ['', 'before  after'],
    ];
  }

  /**
   * A scalar value is substituted for the placeholder.
   */
  #[DataProvider('scalarCases')]
  public function testScalarSubstitution(mixed $value, string $expected): void {
    $plugin = $this->plugin('before [value] after');

    $this->assertSame($expected, $plugin->modifyValue($value));
  }

  /**
   * A non-scalar value contributes nothing instead of the literal "Array".
   *
   * Reachable whenever a provider earlier in the pipeline hands an array to a
   * string prop the token modifier is attached to. Before the fix this emitted
   * an "Array to string conversion" warning — which failOnWarning turns into a
   * failure — and rendered "before Array after" to the page.
   */
  public function testNonScalarValueContributesNothing(): void {
    $plugin = $this->plugin('before [value] after');

    $result = $plugin->modifyValue(['src' => 'a.png', 'alt' => 'A']);

    $this->assertSame('before  after', $result, 'The array contributed nothing to the template.');
    $this->assertStringNotContainsString('Array', (string) $result, 'The literal "Array" never reaches rendered output.');
  }

  /**
   * An object value is treated the same way.
   */
  public function testObjectValueContributesNothing(): void {
    $plugin = $this->plugin('before [value] after');

    $this->assertSame('before  after', $plugin->modifyValue(new \stdClass()));
  }

  /**
   * A template with no placeholder ignores the incoming value.
   */
  public function testTemplateWithoutPlaceholderIgnoresValue(): void {
    $plugin = $this->plugin('static text');

    $this->assertSame('static text', $plugin->modifyValue('IGNORED'));
  }

  /**
   * Entity tokens are resolved after the placeholder is substituted.
   */
  public function testTokensResolvedAfterSubstitution(): void {
    $plugin = $this->plugin('[node:title] — [value]');

    $result = $plugin->modifyValue('SECTION');

    $this->assertSame('RESOLVED TITLE — SECTION', $result);
    $this->assertSame('[node:title] — SECTION', $this->replacedTemplate, 'The value was woven in before token replacement ran.');
  }

  /**
   * A template with nothing token-like never loads the entity.
   *
   * The trait short-circuits on templates containing no "[", which is what
   * keeps a plain prefix/suffix-style template from resolving an entity on
   * every render.
   */
  public function testTemplateWithoutTokensSkipsReplacement(): void {
    $plugin = $this->plugin('no tokens here');

    $plugin->modifyValue('X');

    $this->assertNull($this->replacedTemplate, 'Token replacement was skipped entirely.');
  }

}
