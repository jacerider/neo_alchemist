## INTRODUCTION

The Neo | Alchemist module is a DESCRIBE_THE_MODULE_HERE.

The primary use case for this module is:

- Use case #1
- Use case #2
- Use case #3

## REQUIREMENTS

DESCRIBE_MODULE_DEPENDENCIES_HERE

## INSTALLATION

Install as you would normally install a contributed Drupal module.
See: https://www.drupal.org/node/895232 for further information.

## CONFIGURATION
- Configuration step #1
- Configuration step #2
- Configuration step #3

## MAINTAINERS

Current maintainers for Drupal 10:

- FIRST_NAME LAST_NAME (NICKNAME) - https://www.drupal.org/u/NICKNAME

## NOTES

```php
$manager = \Drupal::service('plugin.manager.sdc');
ksm($manager->getDefinitions());
```

```twig
{{ include('front:superman', {title: 'Superman!', color: 'primary', test: true, items: ['yes', 'no'], sequence: [{title: 'Red'}, {title: 'Blue'}]}) }}

{% embed 'front:superman' with {title: 'Superman!', color: 'secondary', test: true, items: ['yes', 'no'], sequence: [{title: 'Red'}, {title: 'Blue'}]} %}
  {% block first %}
    <div>First slot</div>
  {% endblock %}
{% endembed %}
```
