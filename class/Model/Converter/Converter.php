<?php
namespace ShortPixel\Model\Converter;
use ShortPixel\Replacer\Replacer as Replacer;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


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
		abstract public function convert();
		abstract public function isConvertable();
		abstract public function restore();
		abstract public function getCheckSum();

		// Media Library specific
		abstract protected function updateMetaData($params);
		abstract protected function setupReplacer();
		abstract protected function setTarget($file);

		public function __construct($imageModel)
		{
				$this->imageModel = $imageModel;
				$this->imageModel->getMeta()->convertMeta()->setFileFormat($imageModel->getExtension());
		}

		public function isConverterFor($extension)
		{
			 if ($extension === $this->imageModel->getMeta()->convertMeta()->getFileFormat())
			 {
				  return true;
			 }
			 return false;
		}

		public static function getConverter($imageModel)
		{
			  $extension = $imageModel->getExtension();

				$converter = false;

				$converter = self::getConverterByExt($extension, $imageModel);

				// No Support (yet)
				if ($imageModel->get('type') == 'custom')
				{
					return false;
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

		private static function getConverterByExt($ext, $imageModel)
		{
					$converter = false;
					switch($ext)
					{
						 case 'png':
							$converter = new PNGConverter($imageModel);
						 break;
						 case 'heic':
							$converter = new ApiConverter($imageModel);
						 break;

					}
					return $converter;

		}


}
