<?php
namespace ShortPixel;

abstract class ShortPixelModel
{
  protected $model;
  abstract function getData();

  public function getModel()
  {
    return array_keys($this->model); // only the variable names are public.
  }

  public function sanitize($name, $value)
  {
    if (! isset($this->model[$name]))
      return null;

    // if no sanitize method is set, default to strictest string.
    $sanitize = isset($this->model[$name]['s']) ? $this->model['name']['s'] : 'string';
    switch($sanitize)
    {
      case "string":
        $value = $this->sanitizeString($value);
      break;
      case "int":
        $value = $this->sanitizeInteger($value);
      break;
      case "boolean":
        $value = $this->sanitizeBoolean($value);
      break;
      case 'array':
        $value = $this->sanitizeArray($value);
      break;
      case 'skip': // skips should not be in any save candidate and not be sanitized.
        return null;
      break;
    }

    return $value;
  }

  public function getType($name)
  {
    if (! isset($this->model[$name]))
      return null;

     $type = isset($this->model[$name]['s']) ? $this->model[$name]['s'] : false;
     return $type;
  }

  public function sanitizeString($string)
  {
    return (string) sanitize_text_field($string);
  }
  public function sanitizeInteger($int)
  {
    return intval($int);
  }
  public function sanitizeBoolean($bool)
  {
      return ($bool) ? true : false;
  }

  public function sanitizeArray($array)
  {
      $new_array = array();
      foreach($array as $key => $value)
      {
        $new_array[$this->sanitizeString($key)] = $this->sanitizeString($value);
      }

      return $new_array;
  }

}
