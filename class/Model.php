<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Abstract base model providing field definition, sanitization, and data retrieval.
 *
 * Subclasses define their fields via the $model property array, where each entry
 * describes the field's sanitization type ('s'), optional max value, and optional
 * maxlength. The class then handles sanitizing, retrieving, and exposing data in a
 * consistent manner.
 *
 * @package ShortPixel
 */
abstract class Model
{
  /**
   * Field definitions for the model.
   *
   * Each key is a field name; the value is an options array with at minimum:
   *   - 's' (string): sanitization type — 'string', 'int', 'boolean', 'array',
   *     'exception', or 'skip'.
   *   - 'max' (int, optional): maximum allowed numeric value.
   *   - 'maxlength' (int, optional): maximum string length.
   *
   * @var array<string, array<string, mixed>>
   */
  protected $model = [];

  /**
   * Collects all field values defined in the model and returns them as an associative array.
   *
   * String values have slashes stripped before being returned.
   *
   * @return array<string, mixed> Associative array of field name => current value.
   */
  public function getData()
  {
    $data = array();
    foreach($this->model as $item => $options)
    {

        $value = $this->{$item};

        if (isset($this->model[$item]) && $this->model[$item]['s'] == 'string')
        {
          if (false === is_null($value))
          {
            $value = stripslashes($value);
          }
        }

        $data[$item] = $value;

    }
    return $data;
  }

  /**
   * Returns the list of public field names defined in the model.
   *
   * @return string[] Array of field name strings.
   */
  public function getModel()
  {
    return array_keys($this->model); // only the variable names are public.
  }

  /**
   * Sanitizes a single field value according to its model definition.
   *
   * Dispatches to the appropriate sanitization helper based on the field's 's' type.
   * Returns null for unknown fields or 'skip' fields, and passes through values
   * typed as 'exception' without modification.
   *
   * @param string $name  The field name.
   * @param mixed  $value The raw value to sanitize.
   * @return mixed|null Sanitized value, or null if the field is unknown or should be skipped.
   */
  protected function sanitize($name, $value)
  {
    if (! isset($this->model[$name]))
    {
      return null;
    }

    // if no sanitize method is set, default to strictest string.
    $sanitize = isset($this->model[$name]['s']) ? $this->model[$name]['s'] : 'string';
    switch($sanitize)
    {
      case "string":
        $value = $this->sanitizeString($value);
        $value = $this->checkMaxLength($name, $value);
      break;
      case "int":
        $value = $this->sanitizeInteger($value);
        $value = $this->checkMax($name, $value);
      break;
      case "boolean":
        $value = $this->sanitizeBoolean($value);
      break;
      case 'array':
      case 'Array':
        $value = $this->sanitizeArray($value);
				if (is_null($value))
				{
					Log::addWarn('Field ' . $name . ' is of type Array, but Array not provided');
				}
      break;
      case 'exception': // for exceptional situations. The data will not be sanitized! Need to do this elsewhere.
        return $value;
      break;
      case 'skip': // skips should not be in any save candidate and not be sanitized.
        return null;
      break;
    }

    return $value;
  }

  /** Sanitize the passed post data against the model attribute formats.
  *
  *   @param array   $post    The Post data.
  *   @param boolean $missing If fields are missing, include them empty in the output.
  *   @return array Sanitized Post Data.
  */
  public function getSanitizedData($post, $missing = true)
  {
      $postData = array();
      foreach($post as $name => $value)
      {
          $name = sanitize_text_field($name);
          $value = $this->sanitize($name, $value);
          if ($value !== null)
            $postData[$name] = $value;
          else {
            Log::addWarn("Provided field $name not part of model " . get_class($this) );
          }
      }

      if ($missing)
      {
          $model_fields = $this->getModel();
          $post_fields = array_keys($postData);

          $missing_fields = array_diff($model_fields, $post_fields);
          foreach($missing_fields as $index => $field_name)
          {
            $field_name = sanitize_text_field($field_name);
            $type = $this->getType($field_name);
            if ($type === 'boolean')
            {
              $postData[$field_name] = 0;
            }
            elseif ($type !== false && $type !== 'skip')
            {
              $postData[$field_name] = '';
            }

          }
      }

      return $postData;
  }


  /**
   * Returns the sanitization type string for a named field.
   *
   * @param string $name The field name to look up.
   * @return string|false|null The type string (e.g. 'string', 'int'), false if no type
   *                           is set, or null if the field is not in the model.
   */
  public function getType($name)
  {
    if (! isset($this->model[$name]))
    {
      return null;
      Log::addWarn("Provided field $name not part of model " . get_class($this) );
    }


     $type = isset($this->model[$name]['s']) ? $this->model[$name]['s'] : false;
     return $type;
  }

  /**
   * Sanitizes a value as a plain text string using WordPress's sanitize_text_field().
   *
   * @param mixed $string The value to sanitize.
   * @return string Sanitized string.
   */
  public function sanitizeString($string)
  {
    return (string) sanitize_text_field($string);
  }

  /**
   * Sanitizes a value as an integer using intval().
   *
   * @param mixed $int The value to sanitize.
   * @return int Integer representation of the value.
   */
  public function sanitizeInteger($int)
  {
    return intval($int);
  }

  /**
   * Clamps an integer value to the maximum defined for the field in the model.
   *
   * @param string $name  The field name.
   * @param int    $value The value to check.
   * @return int The original value, or the model's max if the value exceeds it.
   */
  protected function checkMax($name, $value)
  {
      if (false === isset($this->model[$name]['max']))
      {
         return $value;
      }

      if ($value > $this->model[$name]['max'])
      {
         return $this->model[$name]['max'];
      }

      return $value;
  }

  /**
   * Truncates a string value to the maximum length defined for the field in the model.
   *
   * @param string $name  The field name.
   * @param string $value The string value to check.
   * @return string The original value, or a truncated copy if it exceeds maxlength.
   */
  protected function checkMaxLength($name, $value)
  {
    if (false === isset($this->model[$name]['maxlength']))
    {
       return $value;
    }

    $maxlength = $this->model[$name]['maxlength'];

    if (strlen($value) > $maxlength)
    {
       $value = substr($value, 0, $maxlength);
    }

    return $value;

  }

  /**
   * Sanitizes a value as a boolean (truthy/falsy).
   *
   * @param mixed $bool The value to evaluate.
   * @return bool True if truthy, false otherwise.
   */
  public function sanitizeBoolean($bool)
  {
      return ($bool) ? true : false;
  }

  /**
   * Recursively sanitizes an array, applying sanitizeString() to all keys and leaf values.
   *
   * Returns null if the input is not an array.
   *
   * @param mixed $array The value to sanitize.
   * @return array<string, mixed>|null Sanitized array, or null if input was not an array.
   */
  public function sanitizeArray($array)
  {
      if (! is_array($array))
      {
        return null;
      }
      $new_array = array();
      foreach($array as $key => $value)
      {
			$newkey = $this->sanitizeString($key);

			if (true === is_array($value))
			{
				 $newval = $this->sanitizeArray($value);
			}
			else {
				  $newval = $this->sanitizeString($value);
			}

			$new_array[$newkey] = $newval ;

      }

      return $new_array;
  }

}
