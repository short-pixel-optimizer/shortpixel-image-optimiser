<?php
namespace ShortPixel\Model\Image;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Metadata container for a main (non-thumbnail) image in the ShortPixel system.
 *
 * Extends ImageThumbnailMeta with error messaging, legacy-conversion tracking, and a
 * dedicated ImageConvertMeta sub-object for format-conversion state (e.g. PNG-to-JPG).
 *
 * @package ShortPixel\Model\Image
 */
// Base Class for ImageMeta
class ImageMeta extends ImageThumbnailMeta
{

  /** @var string|null Human-readable error message from the last failed optimization attempt. */
  public $errorMessage;
  /** @var bool Whether this image was migrated from a legacy metadata format. */
  public $wasConverted = false; // Was converted from legacy format

	/** @var ImageConvertMeta Sub-object tracking file-format conversion state. */
	protected $convertMeta;


	public function __construct()
	{
		parent::__construct();
		$this->convertMeta = new ImageConvertMeta();

	}

	/**
	 * Populate this object's properties from a plain stdClass (e.g. loaded from DB).
	 *
	 * Handles the nested convertMeta sub-object and maps legacy png2jpg properties to
	 * the modern ImageConvertMeta representation before delegating to the parent.
	 *
	 * @param object $object Source object containing property values to import.
	 * @return void
	 */
	public function fromClass($object)
	{
		if (property_exists($object, 'convertMeta'))
		{

			$this->convertMeta->fromClass($object->convertMeta);
			unset($object->convertMeta);
		}
		// legacy.
		if (property_exists($object, 'tried_png2jpg') && $object->tried_png2jpg)
		{
			 $this->convertMeta()->setTried($object->tried_png2jpg);
		}
		elseif (property_exists($object, 'did_png2jpg')  && $object->did_png2jpg)
		{
			 $this->convertMeta()->setFileFormat('png');
			 $this->convertMeta()->setConversionDone();

		}

		parent::fromClass($object);
	}


	/**
	 * Return the ImageConvertMeta sub-object for this image's conversion state.
	 *
	 * @return ImageConvertMeta
	 */
	public function convertMeta()
	{
		 return $this->convertMeta;
	}

} // class
