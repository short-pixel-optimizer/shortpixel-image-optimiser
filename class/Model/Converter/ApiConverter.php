<?php
namespace ShortPixel\Model\Converter;

use ShortPixel\Helper\UtilHelper as UtilHelper;

class ApiConverter extends MediaLibraryConverter
{

	protected $requestAPIthumbnails = true;


		public function isConvertable()
		{
			 $extension = $this->imageModel->getExtension();

			 if (in_array($extension, self::CONVERTABLE_EXTENSIONS) && $extension !== 'png')
			 {
				  return true;
			 }

			 if (true === $imageModel->getMeta()->convertMeta()->isConverted())
			 {
				  return false;
			 }
		}

		// Do Nothing because conversion is on API level
		public function convert($args = array())
		{

		}

		// Restore from original file. Search and replace everything else to death.
		public function restore()
		{

		}

		public function getCheckSum()
		{
			 return 1; // done or not.
		}






}
