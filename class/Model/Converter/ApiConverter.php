<?php
namespace ShortPixel\Model\Converter;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UtilHelper as UtilHelper;
use ShortPixel\Model\Image\ImageModel as ImageModel;


class ApiConverter extends MediaLibraryConverter
{

	const CONVERTABLE_EXTENSIONS = array( 'heic', 'tiff', 'tif', 'bmp');

	protected $requestAPIthumbnails = true;

		public function isConvertable()
		{
			 $fs = \wpSPIO()->filesystem();
			 $extension = $this->imageModel->getExtension();

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


    public function filterQueue($item, $args = array())
    {
      foreach($item->paramlist as $index => $data)
      {
        if (isset($item->paramlist[$index]['convertto']))
        {
          $item->paramlist[$index]['convertto'] = 'jpg';
        }
      }

      // Run converter to create backup and make placeholder to block similar heics from overwriting.
      $converter_args = array('runReplacer' => false);
       if (false === $args['debug_active'])
       {
        $this->prepareQueue($converter_args);
       }

      //Lossless because thumbnails will otherwise be derived of compressed image, leaving to double compression.
      if (property_exists($item, 'compressionType'))
      {
         $item->compressionTypeRequested = $item->compressionType;
      }
      // Process Heic as Lossless so we don't have double opts.
      $item->compressionType = ImageModel::COMPRESSION_LOSSLESS;

      // Reset counts
      $item->counts->baseCount = 1; // count the base images.
      $item->counts->avifCount = 0;
      $item->counts->webpCount = 0;
      $item->counts->creditCount = 1;

      return $item;
    }

  	/**
     * Prepare to use the converter on the imageModel. Called in queue.php
     * @param  array  $args      Parameters for the function (runreplacer now - will replace urls)
     * @return bool       success or not.
     */
		protected function prepareQueue($args = array())
		{
      // Turning off replacer, since it's always called off in Api?
			$defaults = array(
				 'runReplacer' => true, // The replacer doesn't need running when the file is just uploaded and doing in handle upload hook.
			);

				$args = wp_parse_args($args, $defaults);

				$this->setupReplacer();

				$fs = \wpSPIO()->filesystem();

        $extension = $this->imageModel->getExtension();

        if ('heic' === $extension)
        {
				      $placeholderFile = $fs->getFile(\wpSPIO()->plugin_path('res/img/fileformat-heic-placeholder.jpg'));
        }
        elseif ('tiff' === $extension || 'tif' === $extension)
        {
          $placeholderFile = $fs->getFile(\wpSPIO()->plugin_path('res/img/fileformat-tiff-placeholder.jpg'));
        }
				elseif ('bmp' === $extension)
				{
					$placeholderFile = $fs->getFile(\wpSPIO()->plugin_path('res/img/fileformat-bmp-placeholder.jpg'));
				}
        else { // wrong file better than no file.
          $placeholderFile = $fs->getFile(\wpSPIO()->plugin_path('res/img/fileformat-heic-placeholder.jpg'));

        }

				// Convert runs when putting imageModel to queue format in the Queue classs. This could run without optimization (before) taking place and when accidentally running it more than once results in duplicate files / backups (img-1, img-2 etc). Check placeholder and baseName to prevent this. Assume already done when it has it .
				if ($this->imageModel->getMeta()->convertMeta()->hasPlaceHolder() && $this->imageModel->getMeta()->convertMeta()->getReplacementImageBase() !== false)
				{
					 return true;
				}

        $replacementPath = $this->getReplacementPath();

				if (false === $replacementPath)
				{
					Log::addWarn('ApiConverter replacement path failed');
					$this->imageModel->getMeta()->convertMeta()->setError(self::ERROR_PATHFAIL);

					return false; // @todo Add ResponseController something here.
				}

				$replaceFile = $fs->getFile($replacementPath);
				// If filebase (filename without extension) is not the same, this indicates that a double is there and it's enumerated. Move backup accordingly.


				$destinationFile = $fs->getFile($replacementPath);

        // Create placeholder here.
				$copyok = $placeholderFile->copy($destinationFile);

				if ($copyok)
				{
					$this->imageModel->getMeta()->convertMeta()->setFileFormat($extension);
					$this->imageModel->getMeta()->convertMeta()->setPlaceHolder(true);
					$this->imageModel->getMeta()->convertMeta()->setReplacementImageBase($destinationFile->getFileBase());
					$this->imageModel->saveMeta();

					// @todo Wip . Moved from handleConverted.
					// Backup basically. Do this first.
					$conversion_args = array(
              'replacementPath' => $replacementPath,
              'backup_thumbnails' => false, // no need for this. either they should be optimized, or generated after the run
          );
					$prepared = $this->imageModel->conversionPrepare($conversion_args);
					if (false === $prepared)
					{
						 return false;
					}

          // Don't offload until the API file has been returned properly.
          do_action('shortpixel/converter/prevent-offload', $this->imageModel->get('id'));

// Turning off replacer, since it's always called off in Api?
				//	$this->setTarget($destinationFile);

				//	$params = array('success' => true, 'generate_metadata' => false);
				//	$this->updateMetaData($params);

					$fs->flushImage($this->imageModel);

          // Turning off all replacer, since it's always called off in Api?
					/*
           if (true === $args['runReplacer'])

					{
						$result = $this->replacer->replace();
					} */

				}
				else {
					Log::addError('Failed to copy placeholder');
					return false;
				}

				return true;
		}

    /** Currently not in use */
    public function convert($args = array())
    {
       return;
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

      $extension = $this->imageModel->getExtension();
			$replacementBase = $this->imageModel->getMeta()->convertMeta()->getReplacementImageBase();
			if (false === $replacementBase)
			{
				$replacementPath = $this->getReplacementPath();
				$replacementFile = $fs->getFile($replacementPath);
			}
			else {
				$replacementPath = $replacementBase . '.jpg';
				$replacementFile = $fs->getFile($this->imageModel->getFileDir() . $replacementPath);
			}

			// If -sigh- file has a placeholder, then do something with that.
			if (true === $this->imageModel->getMeta()->convertMeta()->hasPlaceHolder())
			{
				 $this->imageModel->getMeta()->convertMeta()->setPlaceHolder(false);

				// ReplacementFile as source should not point to the placeholder file
				 $this->source_url = $fs->pathToUrl($replacementFile);
				 $this->replacer->setSource($this->source_url);

				 $replacementFile->delete();
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

			if (! isset($mainFile['image']) || ! isset($mainFile['image']['file']))
			{
				 Log::addError('Optimizer didn\'t return file', $mainFile);
				 return false;
			}

			$tempFile = $fs->getFile($mainFile['image']['file']);
			$res = $tempFile->copy($replacementFile);

			if (true === $res)
			{
				 $this->newFile = $replacementFile;
				 $tempFile->delete();

         $generate_metadata = true;

				 $params = array(
            'success' => true,
            'generate_metadata' => $generate_metadata,
         );
				 $this->updateMetaData($params);

				 $result = true;

				 $result = $this->replacer->replace();

				 // Conversion done, but backup results.
				 $this->imageModel->conversionSuccess(array('omit_backup' => false));
				 return true;
			}
			else {
				return false;
			}

		}

} // class
