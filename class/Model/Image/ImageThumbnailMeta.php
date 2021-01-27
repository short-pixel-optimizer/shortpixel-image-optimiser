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


  // Only for customImageModel! Exception to prevent having to create a whole class. Second var here, warrants a subclass.
  public $customImprovement;

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
        if ($property == 'customImprovement')
        {  continue;  }

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

     foreach($vars as $property => $value) // only used by media lib.
     {
       if ($property == 'customImprovement')
       {  continue;  }

       $class->$property = $this->$property;
     }

     return $class;
  }
}
