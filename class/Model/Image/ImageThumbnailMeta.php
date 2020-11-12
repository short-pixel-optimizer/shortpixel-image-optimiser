<?php
namespace ShortPixel\Model\Image;

class ImageThumbnailMeta
{
  public $status = 0;
  public $compressionType;
  public $compressedSize;
  public $originalSize;
//  public $improvement;

  public $tsAdded;
  public $tsOptimized;
  public $webp;

  public $file; // **Only for unlisted images. This defines an unlisted image */ 

//  public $has_backup;

/* WIDTH AND HEIGHT ARE IN IMAGEMODEL!
  public $width;
  public $height;
*/
  //public $name;

  public function __construct()
  {
     $this->tsAdded = time(); // default
  }

  /** Load data from basic class to prevent issues when class definitions changes over time */
  public function fromClass($object)
  {
     foreach($object as $property => $value)
     {
        if (property_exists($this, $property))
        {
          $this->$property = $value;
        }
     }
  }

  /** Save data as basic class to prevent issues when class definitions changes over time */
  public function toClass()
  {
     $class = new \stdClass;
     $vars = get_object_vars($this);

     foreach($vars as $property => $value)
     {
       $class->$property = $this->$property;
     }

     return $class;
  }
}
