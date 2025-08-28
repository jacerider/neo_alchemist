<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Drush\Generators;

use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_alchemist\ComponentPropDefPluginManager;
use Drupal\neo_alchemist\ComponentShapePluginManager;
use Drupal\neo_alchemist\Drush\Generators\Validator\NeoComponentExists;
use DrupalCodeGenerator\Attribute\Generator;
use DrupalCodeGenerator\GeneratorType;
use DrupalCodeGenerator\Asset\AssetCollection;
use DrupalCodeGenerator\Command\BaseGenerator;
use DrupalCodeGenerator\Utils;
use DrupalCodeGenerator\Validator\Optional;
use Symfony\Component\Console\Question\Question;
use DrupalCodeGenerator\Validator\Chained;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates a Neo component.
 */
#[Generator(
  name: 'neo-component-prop',
  description: 'Generates Neo component prop',
  aliases: ['neoacp'],
  type: GeneratorType::THEME_COMPONENT,
)]
final class NeoComponentPropGenerator extends BaseGenerator implements ContainerInjectionInterface, NeoComponentPropGeneratorInterface {

  use NeoComponentPropGeneratorTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ComponentPluginManager $pluginManagerSdc,
    private readonly LibraryDiscoveryInterface $libraryDiscovery,
    private readonly ComponentPropDefPluginManager $propDefManager,
    private readonly ComponentShapePluginManager $shapeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('module_handler'),
      $container->get('theme_handler'),
      $container->get('plugin.manager.sdc'),
      $container->get('library.discovery'),
      $container->get('plugin.manager.neo_component_prop_def'),
      $container->get('plugin.manager.neo_component_shape'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function generate(array &$vars, AssetCollection $assets): void {
    $vars['name'] = 'Front';
    $this->askQuestions($vars);
    $this->generateAssets($vars, $assets);
  }

  /**
   * Collects user answers.
   */
  private function askQuestions(array &$vars): void {
    $ir = $this->createInterviewer($vars);
    $vars['machine_name'] = $themeId = $ir->askMachineName();
    $neoComponentDefinitions = array_filter($this->pluginManagerSdc->getDefinitions(), function ($definition) use ($themeId) {
      return !empty($definition['neo']) && substr($definition['id'], 0, strlen($themeId)) === $themeId;
    });
    $neoComponentIds = [];
    foreach ($neoComponentDefinitions as $definition) {
      $neoComponentIds[] = str_replace($themeId . ':', '', $definition['id']);
    }
    $validator = new Chained(
      new Optional(new NeoComponentExists($themeId, $this->pluginManagerSdc)),
    );

    $question = new Question('Component to update?');
    $question->setValidator($validator);
    $question->setAutocompleterValues($neoComponentIds);
    $componentMachineName = $this->io()->askQuestion($question);
    $componentId = $themeId . ':' . $componentMachineName;

    $componentDefinition = $neoComponentDefinitions[$componentId];
    $vars['component_name'] = $componentDefinition['name'];
    $vars['component_machine_name'] = $componentMachineName;

    $yaml = Yaml::decode(file_get_contents($componentDefinition['_discovered_file_path']));

    $parents = $this->getParentProps($yaml['props'], [], 'props.properties');
    $parentOptions = array_map(function ($data) {
      return implode(' → ', array_map(function ($item) {
        return $item['name'];
      }, $data));
    }, $parents);
    $parentOptions = ['props.properties' => 'Component root'] + $parentOptions;
    $parent = $ir->choice('Parent property?', $parentOptions, 'Component root');

    $vars['component_yaml'] = $componentDefinition['_discovered_file_path'];
    $vars['component_path'] = $parent;
    $vars['component_parents'] = $parents[$parent] ?? [];
    $vars['component_props'] = [];
    do {
      $propParents = [];
      $parentsOverride = NULL;
      foreach ($vars['component_parents'] as $name => $data) {
        // Try to handle arrays. The item key is used by default and may not
        // be correct in all instances.
        if ($data['type'] === 'array') {
          $parentsOverride = ['item'];
        }
        elseif ($parentsOverride) {
          $parentsOverride[] = $name;
        }
        $propParents[$name] = $data['name'];
      }
      $prop = $this->askProp($vars, $ir, $propParents, $parentsOverride);
      $vars['component_props'][] = $prop;
    } while ($ir->confirm('Root: Add another prop?'));
    $vars['component_props'] = \array_filter($vars['component_props'] ?? []);
  }

  /**
   * Create the assets that the framework will write to disk later on.
   *
   * @psalm-param array{component_has_css: bool, component_has_js: bool} $vars
   *   The answers to the CLI questions.
   */
  private function generateAssets(array $vars, AssetCollection $assets): void {
    $yaml = Yaml::decode(file_get_contents($vars['component_yaml']));
    $twigPath = str_replace('.component.yml', '.twig', $vars['component_yaml']);
    $path = $vars['component_path'];

    $props = $this->processComponentProps($vars['component_props']);
    foreach ($props as $name => $prop) {
      NestedArray::setValue($yaml, array_merge(explode('.', $path), [$name]), $prop);
    }
    $twigContent = $this->processComponentTwig($vars['component_props']);
    if ($twigContent && file_exists($twigPath)) {
      $twigContent = Markup::create(implode("\n", $twigContent) . "\n");
      $twig = file_get_contents($twigPath);
      $twig .= "\n" . $twigContent;
      file_put_contents($twigPath, $twig);
    }

    file_put_contents($vars['component_yaml'], Yaml::encode($yaml));
  }

  /**
   * Recursively processes component props to generate Twig content.
   */
  private function processComponentProps(array $props) {
    $processed = [];
    foreach ($props as $prop) {
      $name = $prop['name'];
      unset($prop['name'], $prop['twig']);
      if (!empty($prop['properties'])) {
        $prop['properties'] = $this->processComponentProps($prop['properties']);
      }
      elseif (!empty($prop['items']['properties'])) {
        $prop['items']['properties'] = $this->processComponentProps($prop['items']['properties']);
      }
      $processed[$name] = array_filter($prop);
    }
    return $processed;
  }

  /**
   * Recursively processes component props to generate Twig content.
   */
  private function processComponentTwig(array $props, int $spacing = 0) {
    $processed = [];
    foreach ($props as $prop) {
      $propTwig = [];
      $propTwig[] = str_repeat(' ', $spacing) . '{# ' . $prop['title'] . ': Start #}';
      $twig = $prop['twig'] ?? NULL;
      $contentSpacing = $spacing + 2;
      if ($twig['prefix'] ?? FALSE) {
        if (!is_array($twig['prefix'])) {
          $twig['prefix'] = [$twig['prefix']];
        }
        foreach ($twig['prefix'] as $item) {
          $partContent = (string) $item;
          $partContent = str_repeat(' ', $spacing) . $partContent;
          $propTwig[] = $partContent;
        }
      }
      if ($twig['content'] ?? FALSE) {
        if (!is_array($twig['content'])) {
          $twig['content'] = [$twig['content']];
        }
        foreach ($twig['content'] as $item) {
          $partContent = (string) $item;
          $partContent = str_repeat(' ', $contentSpacing) . $partContent;
          $propTwig[] = $partContent;
        }
      }
      if (!empty($prop['properties'])) {
        $propTwig = array_merge($propTwig, $this->processComponentTwig($prop['properties'], $contentSpacing));
      }
      elseif (!empty($prop['items']['properties'])) {
        $propTwig = array_merge($propTwig, $this->processComponentTwig($prop['items']['properties'], $contentSpacing));
      }
      if ($twig['suffix'] ?? FALSE) {
        if (!is_array($twig['suffix'])) {
          $twig['suffix'] = [$twig['suffix']];
        }
        foreach ($twig['suffix'] as $item) {
          $partContent = (string) $item;
          $partContent = str_repeat(' ', $spacing) . $partContent;
          $propTwig[] = $partContent;
        }
      }
      $propTwig[] = str_repeat(' ', $spacing) . '{# ' . $prop['title'] . ': End #}';
      $processed = array_merge($processed, $propTwig);
    }
    return $processed;
  }

  /**
   * Recursively collects parent property paths.
   */
  private function getParentProps(array $data, array $parents = [], string $path = ''): array {
    $result = [];

    if (empty($data['properties'])) {
      return $result;
    }

    foreach ($data['properties'] as $name => $prop) {
      $currentPath = $path ? "$path.$name" : $name;
      $label = $prop['label'] ?? Utils::camelize($name);

      // Only add to parents if it's an object or array with properties.
      if (
        ($prop['type'] === 'object' && !empty($prop['properties'])) ||
        ($prop['type'] === 'array' && !empty($prop['items']['properties']))
      ) {

        if ($prop['type'] === 'object') {
          $currentPath .= '.properties';
        }
        elseif ($prop['type'] === 'array') {
          $currentPath .= '.items.properties';
        }

        $result[$currentPath] = $parents;
        $result[$currentPath][$name] = [
          'type' => $prop['type'],
          'name' => $label,
        ];

        // Add current item to parents chain for children.
        $newParents = $result[$currentPath];
        // $newParents = array_merge($parents, [$label]);
        // Recurse into object properties.
        if ($prop['type'] === 'object' && !empty($prop['properties'])) {
          $result += $this->getParentProps($prop, $newParents, $currentPath);
        }
        // Recurse into array item properties.
        elseif ($prop['type'] === 'array' && !empty($prop['items']['properties'])) {
          $result += $this->getParentProps($prop['items'], $newParents, $currentPath);
        }
      }
    }

    return $result;
  }

}
