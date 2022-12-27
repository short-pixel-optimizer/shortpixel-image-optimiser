<?php
namespace ShortPixel\Model\Converter;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

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

			 if (true === $this->imageModel->getMeta()->convertMeta()->isConverted())
			 {
				  return false;
			 }
		}

		// Do Nothing because conversion is on API level
		public function convert($args = array())
		{
				return true;
		}

		// Restore from original file. Search and replace everything else to death.
		public function restore()
		{
			/*$params = array('restore' => true);
			$fs = \wpSPIO()->filesystem();

			$this->setupReplacer();

			$newExtension =  $this->imageModel->getMeta()->convertMeta()->getFileFormat();

			$oldFileName = $this->imageModel->getFileName(); // Old File Name, Still .jpg
			$newFileName =  $this->imageModel->getFileBase() . '.' . $newExtension;

			if ($this->imageModel->isScaled())
			{
				 $oldFileName = $this->imageModel->getOriginalFile()->getFileName();
				 $newFileName = $this->imageModel->getOriginalFile()->getFileBase() . '.' . $newExtension;
			}

			$fsNewFile = $fs->getFile($this->imageModel->getFileDir() . $newFileName);

			$this->newFile = $fsNewFile;
			$this->setTarget($fsNewFile);

			$this->updateMetaData($params);
	//		$result = $this->replacer->replace();

			$fs->flushImageCache(); */
		}

		public function getCheckSum()
		{
			 return 1; // done or not.
		}

		public function handleConverted($optimizeData)
		{
			$this->setupReplacer();
			$fs = \wpSPIO()->filesystem();

			// @todo  Move heic to JPG via uniqueFile and such
			$replacementPath = $this->getReplacementPath();
			if (false === $replacementPath)
			{
				Log::addWarn('ApiConverter replacement path failed');
				$this->imageModel->getMeta()->convertMeta()->setError(self::ERROR_PATHFAIL);

				return false; // @todo Add ResponseController something here.
			}

			// backup basically.
			$prepared = $this->imageModel->conversionPrepare();
			if (false === $prepared)
			{
				 return false;
			}

			if (isset($optimizeData['files']) && isset($optimizeData['data']))
			{
				 $files = $optimizeData['files'];
				 $data = $optimizeData['data'];
			}
			else {
				Log::addError('Something went wrong with handleOptimized', $optimizeData);
				return false;
			}

			$mainImageKey = $this->imageModel->get('mainImageKey');
			$mainFile = (isset($files) && isset($files[$mainImageKey])) ? $files[$mainImageKey] : false;

			if (false === $mainFile)
			{
				 Log::addError('MainFile not set during success Api Conversion');
				 return false;
			}


			$tempFile = $fs->getFile($mainFile['image']['file']);

			$replacementFile = $fs->getFile($replacementPath);

			$res = $tempFile->copy($replacementFile);

			if (true === $res)
			{
				 $this->newFile = $fs->getFile($replacementPath);
				 $tempFile->delete();

				 $params = array('success' => true);
				 $this->updateMetaData($params);

				 $result = true;
				 /*
				 if (true === $args['runReplacer'])
				 {
					 $result = $this->replacer->replace();
				 } */

				 // Conversion done, but backup results.
				 $this->imageModel->conversionSuccess(array('omit_backup' => false));
				 return true;
			}
			else {
				return false;
			}

		}








}
