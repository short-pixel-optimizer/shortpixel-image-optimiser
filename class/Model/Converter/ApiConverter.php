<?php
namespace ShortPixel\Model\Converter;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UtilHelper as UtilHelper;

class ApiConverter extends MediaLibraryConverter
{

	const CONVERTABLE_EXTENSIONS = array( 'heic');

	protected $requestAPIthumbnails = true;


		public function isConvertable()
		{
			 $fs = \wpSPIO()->filesystem();
			 $extension = $this->imageModel->getExtension();

			 // Don't allow to convert if target exists to prevent overwrites.
			 $replacement = $fs->getFile($this->imageModel->getFileDir() . $this->imageModel->getFileBase() . '.jpg');

			 if ($replacement->exists() && false === $this->imageModel->getMeta()->convertMeta()->hasPlaceHolder())
			 {
				 return false;
			 }

			 // If extension is in list of allowed Api Converts.
			 if (in_array($extension, static::CONVERTABLE_EXTENSIONS) && $extension !== 'png')
			 {
				  return true;
			 }

			 // If file has not been converted in terms of file, but has a placeholder - process ongoing, so continue;
			 if (false === $this->imageModel->getMeta()->convertMeta()->isConverted() && true === $this->imageModel->getMeta()->convertMeta()->hasPlaceHolder())
			 {
				 return true;
			 }

			 // File has been converted, not converting again.
			 if (true === $this->imageModel->getMeta()->convertMeta()->isConverted())
			 {
				  return false;
			 }
		}

		// Create placeholder here.
		public function convert($args = array())
		{
			$defaults = array(
				 'runReplacer' => true, // The replacer doesn't need running when the file is just uploaded and doing in handle upload hook.
			);

				$args = wp_parse_args($args, $defaults);

				$this->setupReplacer();

				$fs = \wpSPIO()->filesystem();

				$placeholderFile = $fs->getFile(\wpSPIO()->plugin_path('res/img/fileformat-heic-placeholder.jpg'));
				$destinationFile = $fs->getFile($this->imageModel->getFileDir() . $this->imageModel->getFileBase() . '.jpg');

				$copyok = $placeholderFile->copy($destinationFile);

				if ($copyok)
				{
					$this->imageModel->getMeta()->convertMeta()->setFileFormat('heic');
					$this->imageModel->getMeta()->convertMeta()->setPlaceHolder(true);
					$this->imageModel->saveMeta();

					$this->setTarget($destinationFile);
				//	$params = array('success' => true, 'generate_metadata' => false);
				//	$this->updateMetaData($params);

					$fs->flushImage($this->imageModel);

					if (true === $args['runReplacer'])
					{
						$result = $this->replacer->replace();
					}

				}
				else {
					return false;
				}

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

			// Backup basically. Do this first.
			$prepared = $this->imageModel->conversionPrepare();
			if (false === $prepared)
			{
				 return false;
			}

			// If -sigh- file has a placeholder, then do something with that.
			if (true === $this->imageModel->getMeta()->convertMeta()->hasPlaceHolder())
			{
				 $this->imageModel->getMeta()->convertMeta()->setPlaceHolder(false);

		//		 $attach_id = $this->imageModel->get('id');
				 $placeHolderFile = $fs->getFile($this->imageModel->getFileDir() . $this->imageModel->getFileBase() . '.' . $this->imageModel->getMeta()->convertMeta()->getFileFormat());

				 $this->source_url = $fs->pathToUrl($placeHolderFile);
				 $this->replacer->setSource($this->source_url);

				 $placeHolderFile->delete();
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

			if (! isset($mainFile['image']) || ! isset($mainFile['image']['files']))
			{
				 Log::addError('Optimizer didn\'t return file', $mainFile);
				 return false;
			}

			$tempFile = $fs->getFile($mainFile['image']['file']);
			Log::addTemp('MainFile Debug INfo', $mainFile);

			$replacementFile = $fs->getFile($this->imageModel->getFileDir() . $this->imageModel->getFileBase() . '.jpg');
			$res = $tempFile->copy($replacementFile);

			if (true === $res)
			{
				 $this->newFile = $replacementFile;
				 $tempFile->delete();

				 $params = array('success' => true);
				 $this->updateMetaData($params);

				 $result = true;

				// if (true === $args['runReplacer'])
			//	 {
					 $result = $this->replacer->replace();
			//	 }

				 // Conversion done, but backup results.
				 $this->imageModel->conversionSuccess(array('omit_backup' => false));
				 return true;
			}
			else {
				return false;
			}

		}








}
