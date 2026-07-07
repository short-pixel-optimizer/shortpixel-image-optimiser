<?php
 namespace ShortPixel\Model\Converter;

 if ( ! defined( 'ABSPATH' ) ) {
 	exit; // Exit if accessed directly.
 }

 use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
 use ShortPixel\Model\Image\ImageModel as ImageModel;
 use ShortPixel\Model\File\DirectoryModel as DirectoryModel;
 use ShortPixel\Model\File\FileModel as FileModel;
 use ShortPixel\Notices\NoticeController as Notices;

 use ShortPixel\Controller\ResponseController as ResponseController;

 use ShortPixel\Helper\DownloadHelper as DownloadHelper;
use ShortPixel\Model\Image\Image;
use ShortPixel\Model\Queue\QueueItem;

class PNGConverter extends MediaLibraryConverter
{
		protected $instance;

    	protected $current_image; // The current PHP image resource in memory
		protected $virtual_filesize;

		protected $converterActive = false;
		protected $forceConvertTransparent = false;

		protected $lastError;

		protected $settingCheckSum;


		/**
		 * Constructor.
		 *
		 * Records the required env / settings state:
		 *   - `converterActive` reflects the `png2jpg` setting AND the presence
		 *     of at least one imaging library (GD or Imagick).
		 *   - `forceConvertTransparent` is true when `png2jpg == 2` (the
		 *     "convert even transparent PNGs" option).
		 *   - `settingCheckSum` combines `png2jpg + backupImages` so a config
		 *     change forces a retry of a previously-failed conversion.
		 *
		 * @param object $imageModel ImageModel bound for conversion.
		 */
		public function __construct($imageModel)
		{
			parent::__construct($imageModel);

			$settings = \wpSPIO()->settings();
			$env = \wpSPIO()->env();


			$this->converterActive = (intval($settings->png2jpg) > 0) ? true : false;

			if ($env->is_gd_installed === false && false === $env->is_imagick_installed)
			{
				 $this->converterActive = false;
				 $this->lastError = __('No GD or imagick library detected on this installation. Can\'t convert images to PNG', 'shortpixel-image-optimiser');
			}

			$this->forceConvertTransparent = ($settings->png2jpg == 2) ? true : false;

			// If conversion is tried, but failed somehow, it will never try it again, even after changing settings. This should prevent that switch.
			$this->settingCheckSum = intval($settings->png2jpg) + intval($settings->backupImages);


		}

		/**
		 * Whether the bound ImageModel is a valid PNG→JPG conversion candidate.
		 *
		 * All of the following must hold:
		 *   - `converterActive` is true (setting is on + a library is present)
		 *   - the source extension is `.png`
		 *   - the file exists on disk
		 *   - conversion has not already succeeded, and no previous attempt
		 *     with the current settings checksum has been recorded
		 *
		 * @return bool
		 */
		public function isConvertable()
		{
				$imageModel = $this->imageModel;

				// Settings
			  if ($this->converterActive === false)
				{
					return false;
				}

				// Extension
				if ($imageModel->getExtension() !== 'png') // not a png ext. fail silently.
				{
					return false;
				}

				// Existence
				if (! $imageModel->exists())
				{
					 return false;
				}

				if (true === $imageModel->getMeta()->convertMeta()->isConverted() || true === $this->hasTried($imageModel->getMeta()->convertMeta()->didTry()) )
				{
					return false;
				}


				return true;
		}

		/**
		 * Compare a stored `didTry` checksum against the current settings
		 * checksum. Returns true when the previous attempt was made under
		 * identical settings — which means the conversion should NOT be
		 * retried until the user changes settings again.
		 *
		 * @param int|string $checksum Previously-stored checksum from convertMeta.
		 * @return bool
		 */
		protected function hasTried($checksum)
		{
			 if ( intval($checksum) === $this->getCheckSum())
			 {
				  return true;
			 }
			 return false;
		}

    /**
     * Enrich a queued item so PNG→JPG conversion runs before the current
     * action. The original action (e.g. `optimize`) becomes the next action,
     * and `compressionType` + `smartcrop` are added to keep_data so they
     * survive the reset when the queue transitions to the follow-up action.
     *
     * @param QueueItem $qItem Queue slot to enrich.
     * @param array     $args  Reserved for converter-specific hints.
     * @return QueueItem The mutated queue slot (also mutated in place).
     */
    public function filterQueue(QueueItem $qItem, $args = [])
    {
		$currentAction = $qItem->data()->action; 
       $qItem->data()->action = 'png2jpg';
	   $qItem->data()->addNextAction($currentAction);
		$qItem->data()->addKeepDataArgs(['compressionType', 'smartcrop']);


       return $qItem;
    }

		/**
		 * Full PNG→JPG conversion pipeline for the bound ImageModel.
		 *
		 * Pipeline:
		 *   1. Refuse if isConvertable() rejects (settings, extension, existing conversion).
		 *   2. Compute a unique replacement path via getReplacementPath(); on
		 *      failure records ERROR_PATHFAIL on convertMeta and bails.
		 *   3. Record the replacementImageBase on convertMeta so the backup
		 *      layer can name files consistently.
		 *   4. Ask the ImageModel to `conversionPrepare()` — this creates the
		 *      backups needed to make the conversion reversible.
		 *   5. Detect transparent PNGs; unless `forceConvertTransparent` is set,
		 *      record ERROR_TRANSPARENT and fail.
		 *   6. Run the actual GD/Imagick conversion via convertFile().
		 *   7. On success: update WP metadata, optionally run the URL replacer
		 *      (skipped when uploading fresh files where nothing points at the
		 *      old URL yet), and call `conversionSuccess()` on the ImageModel.
		 *   8. On failure: `conversionFailed()` — restores the state.
		 *
		 * @param array{runReplacer?: bool} $args Options. `runReplacer=false` for the upload-hook path.
		 * @return bool
		 */
		public function convert($args = array())
		{
			 if (! $this->isConvertable())
			 {
				 return false;
			 }

			 $fs = \wpSPIO()->filesystem();

			 $defaults = array(
				 	'runReplacer' => true, // The replacer doesn't need running when the file is just uploaded and doing in handle upload hook.
			 );

			 $conversionArgs = array('checksum' => $this->getCheckSum());

			 $this->setupReplacer();
			 $this->raiseMemoryLimit();

			 $replacementPath = $this->getReplacementPath();
			 if (false === $replacementPath)
			 {
				 Log::addWarn('PNGConverter replacement path failed');
				 $this->imageModel->getMeta()->convertMeta()->setError(self::ERROR_PATHFAIL);

				 return false; // @todo Add ResponseController something here.
			 }

			 $replaceFile = $fs->getFile($replacementPath);
			 Log::addDebug('Image replacement base : ' . $replaceFile->getFileBase());
			 $this->imageModel->getMeta()->convertMeta()->setReplacementImageBase($replaceFile->getFileBase());

			 

			 $prepared = $this->imageModel->conversionPrepare($conversionArgs);
 			 if (false === $prepared)
 			 {
				  return false;
			 }

			 $args = wp_parse_args($args, $defaults);

			 if ($this->forceConvertTransparent === false && $this->isTransparent())
			 {
				 	$this->imageModel->getMeta()->convertMeta()->setError(self::ERROR_TRANSPARENT);
					$this->imageModel->conversionFailed($conversionArgs);
					return false;
			 }

			 Log::addDebug('Starting PNG conversion of #' . $this->imageModel->get('id'));
			 $bool = $this->convertFile();

			 if (true === $bool)
			 {
				  $params = array('success' => true);
        	$this->updateMetaData($params);

					$result = true;
					if (true === $args['runReplacer'])
					{
						$result = $this->replacer->replace();
					}

					if (is_array($result))
					{
							foreach($result as $error)
								 Notices::addError($error);
					}


					$this->imageModel->conversionSuccess($conversionArgs);

					// new hook.
					do_action('shortpixel/image/convertpng2jpg_success', $this->imageModel);

					return true;
			 }

			 $this->imageModel->conversionFailed($conversionArgs);

			 //legacy. Note at this point metadata has not been updated.
			 do_action('shortpixel/image/convertpng2jpg_after', $this->imageModel, $args);

			 return false;
		}

		/**
		 * Return the settings-derived checksum used by hasTried() to
		 * detect whether the same conversion has already been attempted
		 * under the current settings.
		 *
		 * @return int
		 */
		public function getCheckSum()
		{
			 return intval($this->settingCheckSum);
		}


		/**
		 * Perform the actual PNG-to-JPG conversion on disk.
		 *
		 * Loads the source PNG through the Image wrapper (GD or Imagick),
		 * writes the JPG replacement, then gates acceptance on file-size margin:
		 * if the resulting JPG isn't at least ~5% smaller (or within the
		 * `shortpixel/pngconverter/filesizeMargin` filter's tolerance), the
		 * conversion is rolled back and ERROR_RESULTLARGER is recorded.
		 *
		 * Emits the `shortpixel/image/convertpng2jpg_before` action so
		 * integrations can react to the pending conversion.
		 *
		 * @return bool True when the JPG replacement is on disk and accepted,
		 *              false when the library failed, wrote nothing, or the
		 *              result was too large.
		 */
		protected function convertFile()
		{
			do_action('shortpixel/image/convertpng2jpg_before', $this->imageModel);

			//$img = $this->getPNGImage();
			$fs = \wpSPIO()->filesystem();

			$image = $this->getPNGImage();

			if (false === $image)
			{
				return false; 
			}

			$width = $image->getWidth(); 
			$height = $image->getHeight();
			Log::addDebug("PNG2JPG doConvert width $width height $height", memory_get_usage());

			// check old filename, replace with uniqued filename.
			$bool = $image->convertPNG();

      /** Quality is set to 90 and not using WP defaults (or filter) for good reason. Lower settings very quickly degrade the libraries output quality.  Better to leave this hardcoded at 90 and let the ShortPixel API handle the optimization **/
			if (true === $bool) {

					$replacementPath = $image->getReplacementPath();
					Log::addDebug("PNG2JPG doConvert created JPEG at $replacementPath");
					$newSize = filesize($replacementPath); // This might invoke wrapper but ok

					if (! is_null($this->virtual_filesize))
					{
						 $origSize = $this->virtual_filesize;
					}
					else {
						if ($this->imageModel->isScaled())
						{
							$origSize = $this->imageModel->getOriginalFile()->getFileSize();
						}
						else
						{
							$origSize = $this->imageModel->getFileSize();
						}
					}

					// Reload the file we just wrote.
					$newFile = $fs->getFile($replacementPath);

					if(false === $this->checkFileSizeMargin($origSize, $newSize)) {
							//if the image is not 5% smaller, don't bother.
							//if the size is 0, a conversion (or disk write) problem happened, go on with the PNG
							Log::addDebug("PNG2JPG converted image is larger ($newSize vs. $origSize), keeping the PNG");
							$msg = __('Converted file is larger. Keeping original file', 'shortpixel-image-optimiser');
							ResponseController::addData($this->imageModel->get('id'), 'message', $msg);
							$newFile->delete();
							$this->imageModel->getMeta()->convertMeta()->setError(self::ERROR_RESULTLARGER);

							return false;
					}
					elseif (! $newFile->exists())
					{
						 Log::addWarn('PNG imagejpeg file not written!', $newFile->getFileName() );
						 $msg = __('Error - PNG file not written', 'shortpixel-image-optimiser');
						 ResponseController::addData($this->imageModel->get('id'), 'message', $msg);
						 $this->imageModel->getMeta()->convertMeta()->setError(self::ERROR_WRITEERROR);

						 return false;
					}
					else {
						$this->newFile = $newFile;
					}


					Log::addDebug('PNG2jPG Converted');
			}

			$fs->flushImage($this->imageModel);

			return true;
		}

    /**
     * Decide whether the converted JPG's filesize is acceptable relative to
     * the original PNG.
     *
     * Rules, in order:
     *   1. Result is smaller or equal → accept.
     *   2. Original filesize is 0 (unknown / virtual file) → accept.
     *   3. Result is 0 → reject (write issue).
     *   4. Consult `shortpixel/pngconverter/filesizeMargin` filter. A
     *      negative value short-circuits every subsequent check and accepts.
     *      Otherwise, accept iff the percentage increase is within the
     *      filter's tolerance.
     *
     * @param int $fileSize    Original PNG filesize in bytes.
     * @param int $resultSize  Converted JPG filesize in bytes.
     * @return bool True when the JPG should replace the PNG.
     */
    private function checkFileSizeMargin($fileSize, $resultSize)
    {
        // If the original filesize is bigger, it means we made it smaller, rejoice and allow.
        if ($fileSize >= $resultSize)
          return true;

        // Fine suppose, but crashes the increase
        if ($fileSize == 0)
          return true;

        // Indicates write issues
        if ($resultSize == 0)
        {
           return false;
        }

        $percentage = apply_filters('shortpixel/pngconverter/filesizeMargin', 0);

        // If the percentage is lower than 0, stop checking. This is a way to short-circuit this check in case optimized images always should be used.
        if ($percentage < 0)
        {
           return true;
        }

        $increase = (($resultSize - $fileSize) / $fileSize) * 100;

        // If the size bigger is within the defined margins, still use it .
        if ($increase <= $percentage)
          return true;

        return false;
    }

		/**
		 * Roll back a completed PNG→JPG conversion.
		 *
		 * The restore of the file itself is handled by MediaLibraryModel's
		 * restoreConversion() before this method runs — here we handle only
		 * the WordPress-side cleanup: register the target as the reinstated
		 * `.png` file, update wp attachment metadata, and run the URL
		 * replacer to rewrite references from the intermediate `.jpg` back
		 * to the restored `.png`.
		 *
		 * @return bool Result of the URL-replacer run.
		 */
		public function restore()
		{
			$params = array(
				'restore' => true,
			);
			$fs = \wpSPIO()->filesystem();

			$this->setupReplacer(); // Sets the source for Replacer. 

			$oldFileName = $this->imageModel->getFileName(); // Old File Name, Still .jpg
			$newFileName =  $this->imageModel->getFileBase() . '.png';

			if ($this->imageModel->isScaled())
			{
				 $oldFileName = $this->imageModel->getOriginalFile()->getFileName();
				 $newFileName = $this->imageModel->getOriginalFile()->getFileBase() . '.png';
			}

			$fsNewFile = $fs->getFile($this->imageModel->getFileDir() . $newFileName);

			$this->newFile = $fsNewFile;
			$this->setTarget($fsNewFile); // Sets the target base file

			$this->updateMetaData($params); // Triggers update of new Metadata - Sets the targets
			$result = $this->replacer->replace();

			$fs->flushImageCache();

			return $result;
		}
    /** Checks if imageModel is transparent. Returns boolean.  --Note-- this is a  heavy function that might load the entire image multiple times and cause memory issues!
    *
    *  @return Boolean Transparent true of false.
    */
		public function isTransparent() {
				$isTransparent = false;
		//		$transparent_pixel = $bg = false;

				$imagePath = $this->imageModel->getFullPath();

				// Check for transparency at the bit path.
						$contents = file_get_contents($imagePath);
						if (stripos($contents, 'PLTE') !== false && stripos($contents, 'tRNS') !== false) {
								$isTransparent = true;
						}
						if (false === $isTransparent) {

								$width = $this->imageModel->get('width');
								$height = $this->imageModel->get('height');
								Log::addDebug("PNG2JPG Image width: " . $width . " height: " . $height . " aprox. size: " . round($width*$height*5/1024/1024) . "M memory limit: " . ini_get('memory_limit') . " USED: " . memory_get_usage());

								$image = $this->getPNGImage();

								if (false === $image)
								{
									 return false;
								}

								$isTransparent = $image->isTransparent(['width' => $width, 'height' => $height]); 
								Log::addDebug("PNG2JPG width $width height $height. Now checking pixels.");
								//run through pixels until transparent pixel is found:

						}
			//	} // non-transparant.

				Log::addDebug("PNG2JPG is " . (false ===  $isTransparent ? " not" : "") . " transparent");
				return $isTransparent;
		}

		/** Load PNG via the Image Model
		 * 
		 * @return Image 
		 */
		protected function getPNGImage()
		{
			if (is_object($this->current_image))
			{
				 return $this->current_image;
			}

			if ($this->imageModel->isScaled())
			{
				$imagePath = $this->imageModel->getOriginalFile()->getFullPath();
				$imageObj = $this->imageModel->getOriginalFile();
			}
			else {
				$imagePath = $this->imageModel->getFullPath();
				$imageObj = $this->imageModel;
			}

			$replacementPath = $this->getReplacementPath();

			if (true === $this->imageModel->is_virtual())
			{
				$downloadHelper = DownloadHelper::getInstance();
				Log::addDebug('PNG converter: Item is remote, attempting to download');

				$tempFile = $downloadHelper->downloadFile($imageObj->getURL());
				if (is_object($tempFile))
				{
					 $imagePath = $tempFile->getFullPath();
					 $this->virtual_filesize = $tempFile->getFileSize();
				}

				$replacementPath = apply_filters('shortpixel/file/virtual/translate', $replacementPath);
			}

			Log::addInfo("PNG Replacement Path: " . $replacementPath);



			// @todo Add ResponseController support to here and getReplacementPath.
			if (false === $replacementPath)
			{
				Log::addWarn('Png2Jpg replacement path failed');
				$this->imageModel->getMeta()->convertMeta()->setError(self::ERROR_PATHFAIL);

				return false; // @todo Add ResponseController something here.
			}

			$image = new Image($imagePath, $replacementPath); 
			$image->loadImageResource();

			$bool = $image->checkImageLoaded();

		//	$image = @imagecreatefrompng($imagePath);
			if (false === $bool)
			{
				$msg = __('Image source failed - Check if source image is PNG and library is working', 'shortpixel-image-optimiser');
				$this->imageModel->getMeta()->convertMeta()->setError(self::ERROR_LIBRARY);
				ResponseController::addData($this->imageModel->get('id'), 'message', $msg);

				Log::addError('Image Create from PNG failed!');
				$this->current_image = false;
			}
			else
			{
				$this->current_image = $image;
			}

			return $this->current_image;
		}



		/**
     * Ask WordPress to raise the PHP memory limit for image work if the
     * helper is available. No-op on older WP versions that lack
     * wp_raise_memory_limit().
     *
     * @return void
     */
    private function raiseMemoryLimit()
    {
      if(function_exists('wp_raise_memory_limit')) {
          wp_raise_memory_limit( 'image' );
      }
    }


} // class
