<?php

declare(strict_types = 1);

namespace Drupal\neo_alchemist\Plugin\Validation\Constraint;

use Drupal\Core\Config\Schema\TypeResolver;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_alchemist\PropExpressions\Component\ComponentPropExpression;
use Drupal\neo_alchemist\PropShape\PropShape;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Enabled configurable plugin settings validator.
 *
 * @internal
 * @todo Once this works, try to subclass \Drupal\Core\Validation\Plugin\Validation\Constraint\ValidKeysConstraintValidator
 */
final class SdcPropKeysConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected readonly ComponentPluginManager $componentPluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ComponentPluginManager::class)
    );
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Symfony\Component\Validator\Exception\UnexpectedTypeException
   *   Thrown when the given constraint is not supported by this validator.
   */
  // @phpcs:disable
  // @phpstan-ignore-next-line
  public function validate($mapping, Constraint $constraint) {
    // @phpcs:enable
    if (!$constraint instanceof SdcPropKeysConstraint) {
      throw new UnexpectedTypeException($constraint, __NAMESPACE__ . '\SdcPropKeysConstraint');
    }

    if (!is_array($mapping)) {
      throw new UnexpectedValueException($mapping, 'mapping');
    }

    // Resolve any dynamic tokens, like %parent, in the SDC plugin ID.
    // @phpstan-ignore argument.type
    $sdc_plugin_id = TypeResolver::resolveDynamicTypeName("[$constraint->sdcPluginId]", $this->context->getObject());
    try {
      $sdc = $this->componentPluginManager->find($sdc_plugin_id);
    }
    catch (ComponentNotFoundException) {
      // @todo Ideally, we'd only validate this if and only if the `component` is valid. That requires conditional/sequential execution of validation constraints, which Drupal does not currently support.
      // @see https://www.drupal.org/project/drupal/issues/2820364
      return;
    }

    // Fetch the props defined in the SDC's metadata.
    $prop_shapes = PropShape::getComponentProps($sdc);
    $expected_keys = array_map(
      fn (string $component_prop_expression) => ComponentPropExpression::fromString($component_prop_expression)->propName,
      array_keys($prop_shapes)
    );

    foreach ($expected_keys as $expected_key) {
      if (!array_key_exists($expected_key, $mapping)) {
        $this->context->buildViolation($constraint->message)
          // `title` is guaranteed to exist.
          // @see \Drupal\neo_alchemist\Plugin\ComponentPluginManager::componentMeetsRequirements()
          // @phpstan-ignore-next-line
          ->setParameter('%prop_title', $sdc->metadata->schema['properties'][$expected_key]['title'])
          ->setParameter('%prop_machine_name', $expected_key)
          ->addViolation();
      }
    }
  }

}
