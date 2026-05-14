<?php

namespace ShortPixel\Model\Converter;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Replacer\Replacer as Replacer;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\File\DirectoryModel as DirectoryModel;
use ShortPixel\Model\File\FileModel as FileModel;
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\Model\Queue\QueueItem as QueueItem;


/* ShortPixel Image Optimiser Converters. Unified interface for handling conversion between file types */

/**
 * Abstract base class defining the unified interface for all file-format converters.
 *
 * Subclasses implement the concrete conversion logic (e.g. PNG-to-JPG, HEIC-via-API).
 * All media-library-specific concerns (metadata, URL replacement) are handled by
 * MediaLibraryConverter.
 *
 * @package ShortPixel\Model\Converter
 */
abstract class Converter
{
	/** @var array File extensions this converter can handle by default. */
	const CONVERTABLE_EXTENSIONS = array('png', 'heic');

	/** @var int Error code: imaging library failure. */
	const ERROR_LIBRARY = -1; /// PNG Library error
	/** @var int Error code: could not determine replacement file path. */
	const ERROR_PATHFAIL = -2; // Couldn't create replacement path
	/** @var int Error code: converted file is larger than the original. */
	const ERROR_RESULTLARGER = -3; // Converted file is bigger than the original
	/** @var int Error code: result file could not be written to disk. */
	const ERROR_WRITEERROR = -4; // Result file could not be written
	/** @var int Error code: backup operation failed. */
	const ERROR_BACKUPERROR = -5; // Backup didn't work.
	/** @var int Error code: image has transparency and force-convert is not set. */
	const ERROR_TRANSPARENT = -6; // Transparency when it is not allowed.

	/** @var object The ImageModel being processed. */
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
	abstract protected function setTarget(FileModel $file);

	// Prepare item for adding to queue, adding data, doing backup perhaps.
	abstract public function filterQueue(QueueItem $item, $args = array());

	/**
	 * Initialises the converter with the given ImageModel and records its current file format.
	 *
	 * @param object $imageModel The ImageModel instance to be converted.
	 */
	public function __construct($imageModel)
	{
		$this->imageModel = $imageModel;
		$this->imageModel->getMeta()->convertMeta()->setFileFormat($imageModel->getExtension());
	}

	/**
	 * Returns the appropriate converter instance for a given file extension.
	 *
	 * @param string $ext        File extension to match against known converters.
	 * @param object $imageModel The ImageModel to pass to the converter constructor.
	 * @return object|false      Converter instance, or false if no converter matches.
	 */
	private static function getConverterByExt($ext, $imageModel)
	{
		$converter = false;
		switch ($ext) {
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

	/**
	 * Checks whether this converter instance handles a specific extension or method type.
	 *
	 * @param string $extension Extension to check (e.g. 'png', 'heic') or 'api' to match API-based converters.
	 * @return bool True when this converter handles the given extension or type.
	 */
	// Check what the converter is for ( extension-wise ) OR if the converter is API or another method.
	//
	public function isConverterFor($extension)
	{
		if ($extension === $this->imageModel->getMeta()->convertMeta()->getFileFormat()) {
			return true;
		} elseif ('api' == $extension && strpos(strtolower(get_class($this)), 'apiconverter') !== false) {
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
		if (! is_object($imageModel)) {
			Log::addInfo('Converter - not an imagemodel');
			return false;
		}

		$extension = $imageModel->getExtension();

		$converter = false;
		$converter = self::getConverterByExt($extension, $imageModel);

		// No Support (yet)
		if ($imageModel->get('type') == 'custom') {
			//	Log::addInfo('Converter fail - no support for custom types');
			return false;
		}

		// Second option for conversion is image who have been placeholdered.
		if (true === $imageModel->getMeta()->convertMeta()->hasPlaceHolder() && false === $imageModel->getMeta()->convertMeta()->isConverted() && ! is_null($imageModel->getMeta()->convertMeta()->getFileFormat())) {
			$converter = self::getConverterByExt($imageModel->getMeta()->convertMeta()->getFileFormat(), $imageModel);
		}

		if (true === $forConversion) // don't check more.
		{
			return $converter;
		}

		if (false === $converter) {
			if ($imageModel->getMeta()->convertMeta()->isConverted() && ! is_null($imageModel->getMeta()->convertMeta()->getFileFormat())) {
				$converter = self::getConverterByExt($imageModel->getMeta()->convertMeta()->getFileFormat(), $imageModel);
			} else {
				Log::addInfo('Converter failed - ', $imageModel->getMeta());
				return false;
			}
		}

		return $converter;
	}

	/**
	 * Hook point called after an optimized file is returned. Base implementation is a pass-through.
	 *
	 * @param array $successData Data returned from the optimizer.
	 * @return array Unmodified success data.
	 */
	public function handleConvertedFilter($successData)
	{
		return $successData;
	}

	/**
	 * Returns a unique FileModel within the given directory by appending a numeric
	 * suffix when a file with the same name already exists.
	 *
	 * @param DirectoryModel $dir    Target directory to check for collisions.
	 * @param FileModel      $file   Proposed file object.
	 * @param int            $number Starting suffix number (unused; always starts from 0 internally).
	 * @return FileModel             A FileModel whose path does not already exist on disk.
	 */
	/** Own function to get a unique filename since the WordPress wp_unique_filename seems to not function properly w/ thumbnails */
	protected function unique_file(DirectoryModel $dir, FileModel $file, $number = 0) : FileModel
	{
		if (false === $file->exists())
			return $file;

		if (true === $file->is_virtual()) {
			return $file;
		}

		$number = 0;
		$fs = \wpSPIO()->filesystem();

		$base = $file->getFileBase();
		$ext = $file->getExtension();

		while ($file->exists()) {
			$number++;
			$numberbase = $base . '-' . $number;
			Log::addDebug('check for unique file -- ' . $dir->getPath() . $numberbase . '.' . $ext);
			$file = $fs->getFile($dir->getPath() . $numberbase . '.' . $ext);
		}

		return $file;
	}

	/**
	 * Determines and registers the full path where the converted JPG replacement file should be stored.
	 * Ensures the path is unique (no collision) and that the directory is writable.
	 * Also calls setTarget() to register the destination with the replacer.
	 *
	 * @return string|false Full path string on success, false on failure.
	 */
	protected function getReplacementPath()
	{
		$fs = \wpSPIO()->filesystem();
		$image_id = $this->imageModel->get('id');

		if ($this->imageModel->isScaled()) {
			$fileObj = $this->imageModel->getOriginalFile();
		} else {
			$fileObj = $this->imageModel;
		}

		//	$filename = $fileObj->getFileName();
		$newFileName = $fileObj->getFileBase() . '.jpg'; // convert extension to .jpg
		$fileDir = $fileObj->getFileDir();

		$fsNewFile = $fs->getFile($fileDir . $newFileName);

		$uniqueFile = $this->unique_file($fileDir, $fsNewFile);
		$newPath =  $uniqueFile->getFullPath();

		if (! $fileDir->is_writable()) {
			Log::addWarn('Replacement path for PNG not writable ' . $newPath);
			$msg = __('Replacement path for PNG not writable', 'shortpixel-image-optimiser');
			ResponseController::addData($image_id, 'message', $msg);

			return false;
		}

		$this->setTarget($uniqueFile);

		return $newPath;
	}
}
