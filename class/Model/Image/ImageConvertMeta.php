<?php
namespace ShortPixel\Model\Image;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class ImageConvertMeta
{

	 protected $fileFormat; // png / heic etc
	 protected $isConverted = false;
	// protected $doConversion = false;
	 protected $triedConversion = false;
	 protected $errorReason = false;

	 public function __construct()
	 {

	 }

	 public function load($data)
	 {
		  foreach($data as $name => $val)
			{
				 if (property_exists($this, $name))
				 {
					  $this->$name = $val;
				 }
			}
	 }

	 public function save()
	 {
		  return $convertData;
	 }

	 public function isConverted()
	 {
		 	return $this->isConverted;
	 }

	 public function didTry()
	 {
		   return $this->triedConversion;
	 }

	 public function setTried($value)
	 {
		  $this->triedConversion = $value;
	 }

	 public function setConversionDone()
	 {
		  $this->isConverted = true;

	 }

	 public function setError($code)
	 {
		  $this->errorReason = $code;
	 }

	 public function getError()
	 {
		  return $this->errorReason;
	 }

	 public function setFileFormat($ext)
	 {
		  if (is_null($this->fileFormat) && false === $this->isConverted())
		  	$this->fileFormat = $ext;
	 }

	 public function getFileFormat()
	 {
		  return $this->fileFormat;
	 }

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