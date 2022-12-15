<?php
namespace ShortPixel\Model\Converter;

class ApiConverter extends MediaLibraryConverterer
{

		public function isConvertable()
		{
			 $extension = $this->imageModel->getExtension();

			 if (in_array($extension, self::CONVERTABLE_EXTENSIONS) && $extension !== 'png')
			 {
				  return true;
			 }
		}

		// Do Nothing because conversion is on API level
		public function convert()
		{


		}

		public function restore()
		{

		}

		public function getCheckSum()
		{
			 return 1; // done or not.
		}
}
