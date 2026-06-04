<?php
namespace ShortPixel\Model\Image;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Metadata container for a single thumbnail (or retina variant) within WordPress media.
 *
 * Stores optimization state, sizes, timestamps, and auxiliary file information for one
 * image size. Used as the base class for ImageMeta (the main-image metadata object).
 *
 * @package ShortPixel\Model\Image
 */
class ImageThumbnailMeta
{
	/** @var int|null Primary key of the record in the shortpixel_postmeta table, or null if not yet saved. */
	public $databaseID = null;
	/** @var int Optimization status code (see ImageModel::FILE_STATUS_* constants). */
  public $status = 0;
	/** @var int|null Compression type used (0 = lossless, 1 = lossy, 2 = glossy), or null if unknown. */
  public $compressionType;
	/** @var int|null File size in bytes after optimization, or null if not yet optimized. */
  public $compressedSize;
	/** @var int|null File size in bytes before optimization, or null if not recorded. */
  public $originalSize;
//  public $improvement;

	/** @var bool Whether EXIF data was preserved during the last optimization. */
  public $did_keepExif  = false;

	/** @var bool Whether CMYK color profile was converted to RGB during optimization. */
  public $did_cmyk2rgb = false;

	/** @var int|bool Whether the image was resized during optimization. */
  public $resize;
	/** @var int|null Width in pixels the image was resized to, or null if not resized. */
  public $resizeWidth;
	/** @var int|null Height in pixels the image was resized to, or null if not resized. */
  public $resizeHeight;
	/** @var string|null Human-readable resize type (e.g. 'Cover', 'Contain'), or null. */
	public $resizeType;
	/** @var int|null Original image width in pixels before any resize, or null. */
  public $originalWidth;
	/** @var int|null Original image height in pixels before any resize, or null. */
  public $originalHeight;

  /** @var int|null Unix timestamp when the image was added to the SPIO queue. */
  public $tsAdded;
  /** @var int|null Unix timestamp when the image was last optimized. */
  public $tsOptimized;
  /** @var string|int|null WebP companion file name, FILETYPE_BIGGER constant, or null if absent. */
  public $webp;
  /** @var string|int|null AVIF companion file name, FILETYPE_BIGGER constant, or null if absent. */
  public $avif;

  /** @var string|null File path used for unlisted (extra, non-WordPress-registered) thumbnail images. */
  public $file; // **Only for unlisted images. This defines an unlisted image */

  // Only for customImageModel! Exception to prevent having to create a whole class. Second var here, warrants a subclass.
  /** @var float|null Saved compression improvement percentage, used only by CustomImageModel. */
  public $customImprovement;


  public function __construct()
  {
     $this->tsAdded = time(); // default
  }


  /**
   * Populate this object's properties from a plain stdClass object (e.g. loaded from DB).
   *
   * Skips the customImprovement field and only copies properties that exist on this class.
   *
   * @param object $object Source object containing property values to import.
   * @return void
   */
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


  /**
   * Export this object's properties to a plain stdClass for database storage.
   *
   * Skips the customImprovement field and handles the convertMeta sub-object by
   * serialising it via its own toClass() method.
   *
   * @return \stdClass
   */
  public function toClass()
  {
     $class = new \stdClass;
     $vars = get_object_vars($this);

     foreach($vars as $property => $value) // only used by media library.
     {
       if ($property == 'customImprovement')
       {  continue;  }

			 if ($property == 'convertMeta' && is_null($this->convertMeta))
			 {
				 	continue;
			 }
			 elseif ($property == 'convertMeta') {
			 		$class->$property = $this->$property->toClass();
					continue;
			 }
      // if (is_null($value)) // don't save default / values without init.
       //   continue;


       $class->$property = $this->$property;
     }

     return $class;
  }
}
