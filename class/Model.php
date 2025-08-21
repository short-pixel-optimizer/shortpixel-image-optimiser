<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

abstract class Model
{
  protected $model = [];

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

  public function getModel()
  {
    return array_keys($this->model); // only the variable names are public.
  }

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
  *   @param Array $post The Post data
  *   @param boolean $missing If fields are missing, include them empty in the output
  *   @return Array Sanitized Post Data
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

  public function sanitizeString($string)
  {
    return (string) sanitize_text_field($string);
  }

  public function sanitizeInteger($int)
  {
    return intval($int);
  }

  protected function checkMax($name, $value)
  {
      if (false === isset($this->model[$name]['max']))
      {
         return $value; 
      }

      return max($value, $this->model[$name]['max']);
  }

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

  public function sanitizeBoolean($bool)
  {
      return ($bool) ? true : false;
  }

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
