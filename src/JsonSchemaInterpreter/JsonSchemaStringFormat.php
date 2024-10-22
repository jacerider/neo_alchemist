<?php

/**
 * @file
 * Json Schema string format.
 */

declare(strict_types=1);

namespace Drupal\neo_alchemist\JsonSchemaInterpreter;

use Drupal\Core\TypedData\Type\DateTimeInterface;
use Drupal\Core\TypedData\Type\UriInterface;
use Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirement;
use Symfony\Component\Validator\Constraints\Ip;

/**
 * Json Schema string format.
 *
 * @see https://json-schema.org/understanding-json-schema/reference/string#format
 * @see https://json-schema.org/understanding-json-schema/reference/string#built-in-formats
 *
 * phpcs:disable Drupal.Files.LineLength.TooLong
 * phpcs:disable Drupal.Commenting.PostStatementComment.Found
 */
enum JsonSchemaStringFormat: string {
  // Dates and times.
  // @see https://json-schema.org/understanding-json-schema/reference/string#dates-and-times
  case DATE_TIME = 'date-time'; // RFC3339 section 5.6 — subset of ISO8601.
  case TIME = 'time'; // Since draft 7.
  case DATE = 'date'; // Since draft 7.
  case DURATION = 'duration'; // Since draft 2019-09.

  // Email addresses.
  case EMAIL = 'email'; // RFC5321 section 4.1.2.
  case IDN_EMAIL = 'idn-email'; // Since draft 7, RFC6531.

  // Hostnames.
  case HOSTNAME = 'hostname'; // RFC1123, section 2.1.
  case IDN_HOSTNAME = 'idn-hostname'; // Since draft 7, RFC5890 section 2.3.2.3.

  // IP Addresses.
  case IPV4 = 'ipv4'; // RFC2673 section 3.2.
  case IPV6 = 'ipv6'; // RFC2373 section 2.2.

  // Resource identifiers.
  case UUID = 'uuid'; // Since draft 2019-09. RFC4122.
  case URI = 'uri'; // RFC3986.
  // Because FILTER_VALIDATE_URL does not conform to RFC-3986, and cannot handle
  // relative URLs, to support the relative URLs the 'uri-reference' format must
  // be used.
  // @see \JsonSchema\Constraints\FormatConstraint::check()
  // @see \Drupal\Core\Validation\Plugin\Validation\Constraint\PrimitiveTypeConstraintValidator::validate()
  case URI_REFERENCE = 'uri-reference'; // Since draft 6, RFC3986 section 4.1.
  case IRI = 'iri'; // Since draft 7, RFC3987.
  case IRI_REFERENCE = 'iri-reference'; // Since draft 7, RFC3987.

  // URI template.
  case URI_TEMPLATE = 'uri-template'; // Since draft 7, RFC6570.

  // JSON Pointer.
  case JSON_POINTER = 'json-pointer'; // Since draft 6, RFC6901.
  case RELATIVE_JSON_POINTER = 'relative-json-pointer'; // Since draft 7.

  // Regular expressions.
  case REGEX = 'regex'; // Since draft 7, ECMA262.

  /**
   * Convert to data type shape requirements.
   */
  public function toDataTypeShapeRequirements(): DataTypeShapeRequirement {
    return match($this) {
      // Built-in formats: dates and times.
      // @see https://json-schema.org/understanding-json-schema/reference/string#dates-and-times
      // @todo Restrict to only fields with the storage setting set to \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATETIME
      // @todo Somehow allow \Drupal\Core\Field\Plugin\Field\FieldType\TimestampItem too, even though it is int-based, thanks to the use of an adapter? Infer this from \Drupal\Core\Field\FieldTypePluginManager::getGroupedDefinitions(), specifically `category = "date_time"`?
      static::DATE_TIME => new DataTypeShapeRequirement('PrimitiveType', [], DateTimeInterface::class),
      // @todo Restrict to only fields with the storage setting set to \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATE
      // @todo Somehow allow \Drupal\Core\Field\Plugin\Field\FieldType\TimestampItem too, even though it is int-based, thanks to the use of an adapter? Infer this from \Drupal\Core\Field\FieldTypePluginManager::getGroupedDefinitions(), specifically `category = "date_time"`?
      static::DATE => new DataTypeShapeRequirement('PrimitiveType', [], DateTimeInterface::class),
      // @todo Somehow allow \Drupal\Core\Field\Plugin\Field\FieldType\TimestampItem too, even though it is int-based, thanks to the use of an adapter? Infer this from \Drupal\Core\Field\FieldTypePluginManager::getGroupedDefinitions(), specifically `category = "date_time"`?
      static::TIME => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),
      static::DURATION => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),

      // Built-in formats: email addresses.
      // @see https://json-schema.org/understanding-json-schema/reference/string#email-addresses
      static::EMAIL, static::IDN_EMAIL => new DataTypeShapeRequirement('Email', []),

      // Built-in formats: hostnames.
      // @see https://json-schema.org/understanding-json-schema/reference/string#hostnames
      static::HOSTNAME, static::IDN_HOSTNAME => new DataTypeShapeRequirement('Hostname', []),

      // Built-in formats: IP addresses.
      // @see https://json-schema.org/understanding-json-schema/reference/string#ip-addresses
      static::IPV4 => new DataTypeShapeRequirement('Ip', ['version' => Ip::V4]),
      static::IPV6 => new DataTypeShapeRequirement('Ip', ['version' => Ip::V6]),

      // Built-in formats: resource identifiers.
      // @see https://json-schema.org/understanding-json-schema/reference/string#resource-identifiers
      static::UUID => new DataTypeShapeRequirement('Uuid', []),
      // TRICKY: Drupal core does not support RFC3987 aka IRIs, but it's a superset of RFC3986.
      static::URI_REFERENCE, static::URI, static::IRI, static::IRI_REFERENCE => new DataTypeShapeRequirement('PrimitiveType', [], UriInterface::class),

      // Built-in formats: URI template.
      // @see https://json-schema.org/understanding-json-schema/reference/string#uri-template
      static::URI_TEMPLATE => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),

      // Built-in formats: JSON Pointer.
      // @see https://json-schema.org/understanding-json-schema/reference/string#json-pointer
      static::JSON_POINTER, static::RELATIVE_JSON_POINTER => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),

      // Built-in formats: Regular expressions.
      // @see https://json-schema.org/understanding-json-schema/reference/string#regular-expressions
      static::REGEX => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),
    };
  }

}
