<?php
namespace ShortPixel\Model\Converter;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Replacer\Replacer as Replacer;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\File\DirectoryModel as DirectoryModel;
use ShortPixel\Model\File\FileModel as FileModel;
use ShortPixel\Controller\ResponseController as ResponseController;

/* ShortPixel Image Optimiser Converters. Unified interface for handling conversion between file types */
abstract class Converter
{
	  const CONVERTABLE_EXTENSIONS = array('png', 'heic');

		const ERROR_LIBRARY = -1; /// PNG Library error
		const ERROR_PATHFAIL = -2; // Couldn't create replacement path
		const ERROR_RESULTLARGER = -3; // Converted file is bigger than the original
		const ERROR_WRITEERROR = -4; // Result file could not be written
		const ERROR_BACKUPERROR = -5; // Backup didn't work.
		const ERROR_TRANSPARENT = -6; // Transparency when it is not allowed.

		protected $imageModel;  // The current ImageModel from SPIO

		// Method specific
		abstract public function convert($args = array());
		abstract public function isConvertable();
		abstract public function restore();
		abstract public function getCheckSum();

		// Media Library specific
		abstract protected function updateMetaData($params);
		abstract public function getUpdatedMeta();
		abstract protected function setupReplacer();
		abstract protected function setTarget($file);

    // Prepare item for adding to queue, adding data, doing backup perhaps.
    abstract public function filterQueue($item, $args = array());

		public function __construct($imageModel)
		{
				$this->imageModel = $imageModel;
				$this->imageModel->getMeta()->convertMeta()->setFileFormat($imageModel->getExtension());
		}

    private static function getConverterByExt($ext, $imageModel)
    {
          $converter = false;
          switch($ext)
          {
             case 'png':
              $converter = new PNGConverter($imageModel);
             break;
             case 'heic':
             case 'tiff':
             case 'tif':
             case 'bmp':
              $converter = new ApiConverter($imageModel);
             break;

              //$converter = new BMPConverter($imageModel);
             //break;
          }
          return $converter;
    }

    // Check what the converter is for ( extension-wise ) OR if the converter is API or another method.
    //
		public function isConverterFor($extension)
		{
			 if ($extension === $this->imageModel->getMeta()->convertMeta()->getFileFormat())
			 {
				  return true;
			 }
       elseif ('api' == $extension && strpos(strtolower(get_class($this)), 'apiconverter') !== false)
       {
          return true;
       }

			 return false;
		}

		// ForConversion:  Return empty if file can't be converted or is already converrted
		/**
     * Gets the converter for this ImageModel. Must be from media (for now) and is checked by the extension. Adding converters can be done by adding said extension.
     * @param  Object  $imageModel          ImageModel object
     * @param  boolean $forConversion       If requesting for conversion, less checks are performed.
     * @return object|boolean               Object or false
     */
		public static function getConverter($imageModel, $forConversion = false)
		{
        if (! is_object($imageModel))
        {
           return false;
        }
        
			  $extension = $imageModel->getExtension();

				$converter = false;

				$converter = self::getConverterByExt($extension, $imageModel);

				// No Support (yet)
				if ($imageModel->get('type') == 'custom')
				{
					return false;
				}

				// Second option for conversion is image who have been placeholdered.
				if (true === $imageModel->getMeta()->convertMeta()->hasPlaceHolder() && false === $imageModel->getMeta()->convertMeta()->isConverted() && ! is_null($imageModel->getMeta()->convertMeta()->getFileFormat()))
				{
					 $converter = self::getConverterByExt($imageModel->getMeta()->convertMeta()->getFileFormat(), $imageModel);
				}

				if (true === $forConversion) // don't check more.
				{
					 return $converter;
				}

				if (false === $converter)
				{
					 if ($imageModel->getMeta()->convertMeta()->isConverted() && ! is_null($imageModel->getMeta()->convertMeta()->getFileFormat()) )
					 {
						 $converter = self::getConverterByExt($imageModel->getMeta()->convertMeta()->getFileFormat(), $imageModel);
					 }
					 else
					 {
					 		return false;
					 }
				}

				return $converter;
		}

    public function handleConvertedFilter($successData)
    {
       return $successData;
    }

		/** Own function to get a unique filename since the WordPress wp_unique_filename seems to not function properly w/ thumbnails */
    protected function unique_file(DirectoryModel $dir, FileModel $file, $number = 0)
    {
      if (! $file->exists())
        return $file;

			if ($file->is_virtual())
			{
				 return $file;
			}

      $number = 0;
      $fs = \wpSPIO()->filesystem();

      $base = $file->getFileBase();
      $ext = $file->getExtension();

      while($file->exists())
      {
        $number++;
        $numberbase = $base . '-' . $number;
        Log::addDebug('check for unique file -- ' . $dir->getPath() . $numberbase . '.' . $ext);
        $file = $fs->getFile($dir->getPath() . $numberbase . '.' . $ext);
      }

      return $file;
    }

		protected function getReplacementPath()
		{
			$fs = \wpSPIO()->filesystem();

			$filename = $this->imageModel->getFileName();
			$newFileName = $this->imageModel->getFileBase() . '.jpg'; // convert extension to .jpg

			$fsNewFile = $fs->getFile($this->imageModel->getFileDir() . $newFileName);

			$uniqueFile = $this->unique_file( $this->imageModel->getFileDir(), $fsNewFile);
			$newPath =  $uniqueFile->getFullPath(); //(string) $fsFile->getFileDir() . $uniquepath;

			if (! $this->imageModel->getFileDir()->is_writable())
			{
					Log::addWarn('Replacement path for PNG not writable ' . $this->imageModel->getFileDir()->getPath());
					$msg = __('Replacement path for PNG not writable', 'shortpixel-image-optimiser');
					ResponseController::addData($this->imageModel->get('id'), 'message', $msg);

				return false;
			}

			$this->setTarget($uniqueFile);

			return $newPath;

		}


}
