<?php
namespace ShortPixel\Model\Image;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Holds metadata about a file format conversion (e.g. PNG-to-JPG or HEIC-to-JPG).
 *
 * Tracks whether a conversion was attempted, succeeded, which source format was
 * involved, and whether the converted file's backup should be omitted in favour
 * of keeping only the original-format backup.
 *
 * @package ShortPixel\Model\Image
 */
class ImageConvertMeta
{

	/** @var string|null Original file format extension before conversion (e.g. 'png', 'heic'). */
	 protected $fileFormat; // png / heic etc
	/** @var bool Whether the conversion has been completed successfully. */
	 protected $isConverted = false;
	/** @var bool Whether a placeholder file was created in place of the original during conversion. */
	 protected $placeholder = false;
	/** @var string|false Base name of the replacement image file, or false if not set. */
	 protected $replacementImageBase = false;
	// protected $doConversion = false;
	/** @var bool|mixed Whether a conversion was already attempted (may hold a checksum value). */
	 protected $triedConversion = false;
	/** @var int|false Error code from a failed conversion attempt, or false if no error. */
	 protected $errorReason = false;
	/** @var bool When true, only the original-format file is backed up; the converted file is not backed up again. */
	 protected $omitBackup = true; // Don't backup the converted image (again), keeping only the original format. if not, make a backup of the converted file and treat that as the default backup/restore
	 protected $numberBase = 0;

	 public function __construct()
	 {

	 }

	 /**
	  * Whether the conversion to the new format has been completed.
	  *
	  * @return bool
	  */
	 public function isConverted()
	 {
		 	return $this->isConverted;
	 }

	 /**
	  * Whether a conversion was already attempted for this image.
	  *
	  * @return bool|mixed False if never attempted, or the stored checksum/value.
	  */
	 public function didTry()
	 {
		   return $this->triedConversion;
	 }

	 /**
	  * Record that a conversion was attempted, storing an arbitrary value (e.g. checksum).
	  *
	  * @param mixed $value Value to store as the tried-conversion marker.
	  * @return void
	  */
	 public function setTried($value)
	 {
		  $this->triedConversion = $value;
	 }

	 /**
	  * Mark the conversion as completed and configure backup behaviour.
	  *
	  * @param bool $omitBackup When true (default), only the original format file is
	  *                         backed up; the converted file is not backed up separately.
	  * @return void
	  */
	 public function setConversionDone($omitBackup = true)
	 {
		  $this->isConverted = true;
			$this->omitBackup = $omitBackup;
	 }

	 /**
	  * Store an error code resulting from a failed conversion attempt.
	  *
	  * @param int $code Error code constant (e.g. from Converter::ERROR_*).
	  * @return void
	  */
	 public function setError($code)
	 {
		  $this->errorReason = $code;
	 }

	 /**
	  * Retrieve the error code from a failed conversion, or false if none.
	  *
	  * @return int|false
	  */
	 public function getError()
	 {
		  return $this->errorReason;
	 }

	 /**
	  * Set the original file format extension (only stored once; subsequent calls are ignored).
	  *
	  * @param string $ext File extension of the source format (e.g. 'png', 'heic').
	  * @return void
	  */
	 public function setFileFormat($ext)
	 {
		  if (is_null($this->fileFormat))
		  	$this->fileFormat = $ext;
	 }

	 /**
	  * Return the original file format extension recorded before conversion.
	  *
	  * @return string|null
	  */
	 public function getFileFormat()
	 {
		  return $this->fileFormat;
	 }

	 /**
	  * Whether the converted file's backup should be omitted (only the original is kept).
	  *
	  * @return bool
	  */
	 public function omitBackup()
	 {
		  return $this->omitBackup;
	 }

	 /**
	  * Set whether a placeholder file was created for this image during conversion.
	  *
	  * @param bool $placeholder True to mark a placeholder as present, false to clear.
	  * @return void
	  */
	 // bool for now, otherwise if needed.
	 public function setPlaceHolder($placeholder = true)
	 {
		 	$this->placeholder = $placeholder;
	 }

	 /**
	  * Whether a placeholder file was created in place of the original.
	  *
	  * @return bool
	  */
	 public function hasPlaceHolder()
	 {
		  return $this->placeholder;
	 }

	 /**
	  * Set the base name of the replacement image file produced by conversion.
	  *
	  * @param string|false $name File base name (without extension), or false to clear.
	  * @return void
	  */
	 public function setReplacementImageBase($name)
	 {
		  $this->replacementImageBase = $name;

	 }

	 /**
	  * Get the base name of the replacement image file produced by conversion.
	  *
	  * @return string|false
	  */
	 public function getReplacementImageBase()
	 {
		  return $this->replacementImageBase;

	 }


	 /**
	  * Populate this object's properties from a plain stdClass object (e.g. loaded from DB).
	  *
	  * Only copies properties that exist on this class; unknown properties are ignored.
	  *
	  * @param object $object Source object containing property values to import.
	  * @return void
	  */
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

	 /**
	  * Export this object's properties to a plain stdClass for database storage.
	  *
	  * @return \stdClass
	  */
	 public function toClass()
	 {
		 $class = new \stdClass;
		 $vars = get_object_vars($this);

		 foreach($vars as $property => $value) // only used by media library.
		 {
			  $class->$property = $this->$property;
		 }
		 return $class;
	 }

}
