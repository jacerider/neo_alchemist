<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeValueInterface;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\ValueProviderSearch;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the provide phase of the value pipeline as an isolated unit.
 *
 * The search threads a seed through an ordered provider list and returns the
 * value the prop resolves to. These assertions are about that value — which
 * provider's answer wins, whether an empty result falls through or blocks —
 * driven by real providers with a preset value and no container. The same
 * outcomes are pinned end-to-end by the GetDefaultValue* and
 * ComponentValueProcessingMode* kernel tests; here they cost milliseconds.
 *
 * @see \Drupal\neo_alchemist\ValueProviderSearch
 * @see \Drupal\Tests\neo_alchemist\Kernel\GetDefaultValueOrderTest
 * @see \Drupal\Tests\neo_alchemist\Kernel\GetDefaultValueEmptyProducerTest
 */
#[Group('neo_alchemist')]
class ValueProviderSearchTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * Builds a shape whose emptiness contract the search consults.
   *
   * Only the value role is answered: deciding whether a produced value counts
   * as empty is the one thing the search asks its shape, and the type says so.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
   *   The shape double.
   */
  private function shape(): ComponentShapePluginInterface {
    $value = $this->shapeRole(ComponentShapeValueInterface::class);
    $value->method('isProvidedValueEmpty')->willReturnCallback(
      static fn (mixed $provided): bool => $provided === NULL || $provided === '' || $provided === [],
    );
    return $this->shapeDouble([$value]);
  }

  /**
   * Builds a provider that produces a preset value.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The shape the provider decides a value for — the same one handed to the
   *   search, as it is in production.
   * @param mixed $provided
   *   The value the provider produces on the provide pass.
   * @param string|null $mode
   *   The configured processing mode, or NULL for the plugin default
   *   (stop_when_found).
   *
   * @return \Drupal\Tests\neo_alchemist\Unit\SearchProbeProvider
   *   The provider.
   */
  private function provider(ComponentShapePluginInterface $shape, mixed $provided, ?string $mode = NULL): SearchProbeProvider {
    $configuration = $mode !== NULL ? ['processing_mode' => $mode] : [];
    $provider = new SearchProbeProvider('probe', ['group' => 'providers'], $shape, $configuration);
    $provider->provided = $provided;
    return $provider;
  }

  /**
   * Runs the search over an ordered provider list.
   *
   * @param \Drupal\neo_alchemist\Value\ComponentValuePluginInterface[] $instances
   *   The ordered providers.
   * @param mixed $seed
   *   The value the search starts from.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapeValueInterface $shape
   *   The shape whose emptiness contract governs the fall-through.
   *
   * @return mixed
   *   The value the search settled on.
   */
  private function search(array $instances, mixed $seed, ComponentShapeValueInterface $shape): mixed {
    return (new ValueProviderSearch())->search($instances, $seed, $shape);
  }

  /**
   * The list order is the order providers run in.
   *
   * Two non-claiming providers each overwrite the threaded value, so the last
   * one in the list is the one whose value stands.
   */
  public function testConfiguredOrderIsRunOrder(): void {
    $shape = $this->shape();
    $a = $this->provider($shape, 'a', ComponentValueProcessingModeInterface::MODE_CONTINUE);
    $b = $this->provider($shape, 'b', ComponentValueProcessingModeInterface::MODE_CONTINUE);

    $this->assertSame('b', $this->search([$a, $b], 'seed', $shape), 'With [a, b] the last provider wins.');
    $this->assertSame('a', $this->search([$b, $a], 'seed', $shape), 'Reversing the list reverses which value wins.');
  }

  /**
   * A claim halts the search: nothing after the claiming provider runs.
   *
   * The second provider would overwrite the value if it ran; that its answer
   * never appears is how we know the search stopped.
   */
  public function testClaimHaltsTheSearch(): void {
    $shape = $this->shape();
    $first = $this->provider($shape, 'first');
    $second = $this->provider($shape, 'second');

    $this->assertSame('first', $this->search([$first, $second], 'seed', $shape), 'The claim at the first provider stops the search.');
  }

  /**
   * An empty, non-claiming producer leaves the threaded value intact.
   *
   * The regression this guards: attaching a provider whose source is empty
   * must never leave a prop worse off than not attaching it.
   */
  public function testEmptyNonClaimingProducerLeavesThreadedValueIntact(): void {
    $shape = $this->shape();
    $empty = $this->provider($shape, '');

    $this->assertSame('seed', $this->search([$empty], 'seed', $shape), 'The seed the empty producer was handed is carried on unchanged.');
  }

  /**
   * An empty producer falls through and a later producer still runs.
   */
  public function testEmptyProducerDoesNotStarveTheNext(): void {
    $shape = $this->shape();
    $empty = $this->provider($shape, '');
    $filled = $this->provider($shape, 'real');

    $this->assertSame('real', $this->search([$empty, $filled], 'seed', $shape), 'The empty producer falls through and the next one fills the value.');
  }

  /**
   * A claimed empty blocks: the empty value stands and halts the search.
   */
  public function testClaimedEmptyBlocks(): void {
    $shape = $this->shape();
    $blocking = $this->provider($shape, '', ComponentValueProcessingModeInterface::MODE_BLOCK);
    $later = $this->provider($shape, 'late', ComponentValueProcessingModeInterface::MODE_CONTINUE);

    $this->assertSame('', $this->search([$blocking, $later], 'seed', $shape), 'Block mode claims the empty value, so nothing later fills it.');
  }

  /**
   * A veto's emptiness is kept.
   *
   * A veto claims the value while producing it, saying "nothing is the answer".
   * Its empty result stands rather than falling through to the seed, and the
   * search halts.
   */
  public function testVetoEmptinessIsKept(): void {
    $shape = $this->shape();
    $veto = $this->provider($shape, '');
    $veto->vetoes = TRUE;
    $later = $this->provider($shape, 'late', ComponentValueProcessingModeInterface::MODE_CONTINUE);

    $this->assertSame('', $this->search([$veto, $later], 'seed', $shape), 'The veto keeps its empty answer and stops the search.');
  }

  /**
   * With no providers the seed is returned unchanged.
   */
  public function testNoProvidersReturnsTheSeed(): void {
    $shape = $this->shape();

    $this->assertSame('seed', $this->search([], 'seed', $shape), 'Nothing to consult, so the seed stands.');
  }

  /**
   * A producer carries nothing from one prop's search into the next.
   *
   * Producers are memoised on the collection and reused across props, so a
   * claim raised while resolving one prop must not taint the next. This runs
   * the SAME instances through the search twice with no reset in between: on
   * the first prop the first producer claims and halts (its neighbour never
   * runs); on the second prop the same producer comes up empty, so it must fall
   * through and let its neighbour fill the value. If a claim were stored on the
   * instance, the second search would still see the first producer as claimed —
   * skip the mode, keep the empty value and break — and resolve to '' instead.
   */
  public function testProducerCarriesNothingBetweenSearches(): void {
    $shape = $this->shape();
    $first = $this->provider($shape, 'X');
    $second = $this->provider($shape, 'Y');
    $instances = [$first, $second];

    // First prop: the first producer finds a value, claims it, and halts.
    $this->assertSame('X', $this->search($instances, 'seed', $shape), 'The first producer claims and stops the search.');

    // Same instances, second prop: the first producer now finds nothing.
    $first->provided = '';
    $this->assertSame('Y', $this->search($instances, 'seed', $shape), 'No claim leaked: the empty producer falls through and its neighbour fills the value.');
  }

}
