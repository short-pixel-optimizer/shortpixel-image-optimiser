<?php
declare(strict_types=1);
namespace ShortPixel\Model\Image;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\Controller\Api\ApiController as ApiController;
use ShortPixel\Controller\Backup\BackupController as BackupController;
use ShortPixel\Helper\DownloadHelper;
use ShortPixel\Model\File\FileModel as FileModel;
use ShortPixel\Model\AccessModel as AccessModel;
use ShortPixel\Helper\UtilHelper as UtilHelper;
use ShortPixel\Model\Backup\BackupModel;
use ShortPixel\Model\Converter\Converter as Converter;

/**
 * Abstract base model for a single image entity handled by ShortPixel.
 *
 * Represents an image *entity* (not just a file) — a Media Library attachment,
 * a Custom image, a thumbnail, a retina or an original file. Subclasses provide
 * the storage / loading of the associated metadata; this class owns the shared
 * business logic: processability checks, exclusion rules, backup / restore,
 * WebP + AVIF handling, and the post-optimization state transitions.
 *
 * The properties on this class (width, height, mime, error_message, etc.) are
 * derived at runtime; all persistent state lives on the image_meta object.
 *
 * @package ShortPixel\Model\Image
 */
abstract class ImageModel extends \ShortPixel\Model\File\FileModel
{
    // File Status Constants — persisted on image_meta->status.
    /** Image was seen but processing failed. */
    const FILE_STATUS_ERROR = -1;
    /** Image has never been processed. */
    const FILE_STATUS_UNPROCESSED = 0;
    /** Image is queued but not yet optimized. */
    const FILE_STATUS_PENDING = 1;
    /** Image has been successfully optimized. */
    const FILE_STATUS_SUCCESS = 2;
    /** Image has been restored from backup. */
    const FILE_STATUS_RESTORED = 3;
    /** Marker used during bulk restore to indicate the image is queued for restore. */
    const FILE_STATUS_TORESTORE = 4;

    /** Image is prevented from being auto-processed (usually after a fatal error). */
    const FILE_STATUS_PREVENT = -10;
    /** Image was manually marked as done and should be skipped. */
    const FILE_STATUS_MARKED_DONE = -11;
    /** Image metadata is invalid / unreadable. */
    const FILE_STATUS_BAD_METADATA = -12;

    // Compression Option Constants — must be replicated in screen-base.js.
    /** Lossless compression. */
    const COMPRESSION_LOSSLESS = 0;
    /** Lossy compression. */
    const COMPRESSION_LOSSY = 1;
    /** Glossy compression (lossy tuned to preserve visual quality). */
    const COMPRESSION_GLOSSY = 2;

    /** Marker used in the resize action for smart-crop enabled resizing. */
		const ACTION_SMARTCROP = 100;
    /** Marker used in the resize action for smart-crop disabled resizing. */
		const ACTION_SMARTCROPLESS = 101;

    /**
     * File extensions ShortPixel will process. Excludes anything the
     * MediaLibraryModel should route separately (i.e. avoiding thumbnail
     * touching).
     */
    const PROCESSABLE_EXTENSIONS = array('jpg', 'jpeg', 'gif', 'png', 'pdf', 'webp');

    // Processable-status codes — cached in $processable_status.
    /** Image is eligible for processing. */
    const P_PROCESSABLE = 0;
    /** Underlying file is missing. */
    const P_FILE_NOT_EXIST  = 1;
    /** File extension is not in PROCESSABLE_EXTENSIONS. */
    const P_EXCLUDE_EXTENSION = 2;
    /** Image dimensions match a size-exclusion rule. */
    const P_EXCLUDE_SIZE  = 3;
    /** Image path / name matches a path-exclusion rule. */
    const P_EXCLUDE_PATH  = 4;
    /** Image is already optimized. */
    const P_IS_OPTIMIZED = 5;
    /** File is not writable. */
    const P_FILE_NOTWRITABLE = 6;
    /** Backup directory is not writable. */
		const P_BACKUPDIR_NOTWRITABLE = 7;
    /** A backup already exists (blocking new backup). */
		const P_BACKUP_EXISTS = 8;
    /** preventNextTry() previously flagged this image; auto-processing is blocked. */
		const P_OPTIMIZE_PREVENTED = 9;
    /** Containing directory is not writable. */
		const P_DIRECTORY_NOTWRITABLE = 10;
    /** PDF processing is disabled in settings. */
    const P_EXCLUDE_EXTENSION_PDF = 11;
    /** File on disk is zero-size or unreadable. */
    const P_IMAGE_ZERO_SIZE = 12;
    /** Image date matches a date-exclusion rule. */
    const P_EXCLUDE_DATE = 13;
    /** Image filesize matches a filesize-exclusion rule. */
    const P_EXCLUDE_FILESIZE = 14;

    // Restorable-status codes — cached in $restorable_status.
    /** Image can be restored from backup. */
		const P_RESTORABLE = 109;
    /** No backup file exists for this image. */
		const P_BACKUP_NOT_EXISTS = 110;
    /** Image was never optimized, nothing to restore. */
		const P_NOT_OPTIMIZED = 111;

    /** The primary attachment file. */
		const IMAGE_TYPE_MAIN = 0;
    /** A generated thumbnail size. */
		const IMAGE_TYPE_THUMB = 1;
    /** The unscaled original (WordPress `-scaled` companion). */
		const IMAGE_TYPE_ORIGINAL = 2;
    /** A retina variant (e.g. @2x). */
		const IMAGE_TYPE_RETINA = 3;
    /** A duplicate image already handled elsewhere. */
		const IMAGE_TYPE_DUPLICATE = 4;

    /** Sentinel stored on the webp/avif meta field when the optimized variant
     *  would be larger than the source and was therefore not written. */
		const FILETYPE_BIGGER = -10;

    /**
     * The metadata object for this image (subclass-specific ImageMeta variant).
     * @var \ShortPixel\Model\Image\ImageMeta|object
     */
    protected $image_meta;

    /**
     * Marker set when a meta field changed this request, so subclasses can
     * decide whether to persist.
     * @var bool
     */
		protected $recordChanged = false;

    // NOTE: The properties below are runtime-derived. Persistent state lives on $image_meta.
    /** @var string|int|null Image width in pixels; lazily populated by setImageSize(). */
    protected $width;

    /** @var string|int|null Image height in pixels; lazily populated by setImageSize(). */
    protected $height;

    /** @var string|null MIME type string of the underlying file. */
    protected $mime;

    /** @var string|null Last error message set for this image (surfaced through the response controller). */
    protected $error_message;

    /** @var int|null Image identifier; unique only when combined with $imageType. */
    protected $id;

    /** @var string|int|null One of the IMAGE_TYPE_* constants. */
		protected $imageType;

    /** @var int|null Cached processable-status code — one of the P_* constants. */
    protected $processable_status = null;

    /** @var int|null Cached restorable-status code — one of the P_* constants. */
		protected $restorable_status = null;

    /** @var string|null Human-readable reason returned by getProcessableReason() when auto-processing is prevented. */
  	protected $optimizePreventedReason;

    /**
     * Externally set by QueueController to short-circuit repeated queue lookups.
     * @var bool|null
     */
		public $is_in_queue;

    /**
     * Cached backup model for this image, populated on first getBackupModel() call.
     * @var \ShortPixel\Model\Backup\BackupModel|false|null
     */
    protected $backupModel;


    /**
     * Return the URLs (and companion data) that should be sent to the ShortPixel API
     * for optimization.
     *
     * @return array Optimize-data array keyed by size / variant.
     */
    abstract public function getOptimizeUrls();

    /**
     * Persist the current image_meta object back to storage (attachment meta,
     * custom table, etc.).
     *
     * @return void
     */
    abstract protected function saveMeta();

    /**
     * Load the image_meta object from storage into this instance.
     *
     * @return void
     */
    abstract protected function loadMeta();

    /**
     * Return the per-thumbnail / per-variant optimization improvements the
     * ShortPixel API reported for this image.
     *
     * @return array<string, mixed>|false
     */
    abstract protected function getImprovements();

    /**
     * Return the exclude patterns (path, name, size, date, filesize) that
     * apply to *this* image, ready to be evaluated by the isPathExcluded /
     * isSizeExcluded / isFileSizeExcluded / checkDateExcluded helpers.
     *
     * @return array<int, array<string, mixed>>|false
     */
    abstract protected function getExcludePatterns();

    /**
     * Mark this image so that it will not be auto-processed again until
     * resetPrevent() is called. Used to break auto-retry loops after fatal
     * errors.
     *
     * @param string $reason Human-readable reason surfaced through getProcessableReason().
     * @return void
     */
    abstract protected function preventNextTry($reason = '');

    /**
     * Whether preventNextTry() has flagged this image and it should be
     * skipped by the automated pipeline.
     *
     * @return bool|string False when not prevented, otherwise the reason string.
     */
    abstract public function isOptimizePrevented();

    /**
     * Clear the "prevented" flag so this image can be processed again.
     *
     * @return void
     */
    abstract public function resetPrevent();

    /**
     * Constructor.
     *
     * @param string $path Absolute path to the image file backing this model.
     */
    public function __construct($path)
    {
      parent::__construct($path);
    }

    /**
     * Fill in derived meta values (originalWidth, originalHeight, tsAdded)
     * and refresh WebP/AVIF companion metadata after loadMeta().
     *
     * Called by subclasses at the end of loadMeta() so the image_meta object
     * always has a complete baseline.
     *
     * @return void
     */
    protected function verifyImage()
    {

      // Only get data from Image if not yet set in metadata.
      if (is_null($this->getMeta('originalWidth')))
        $this->setMeta('originalWidth', $this->get('width'));

      if (is_null($this->getMeta('originalHeight')))
        $this->setMeta('originalHeight', $this->get('height'));

      if (is_null($this->getMeta('tsAdded')))
        $this->setMeta('tsAdded', time());

      $this->setWebp();
      $this->setAvif();

    }

    /**
     * Lazily populate $width and $height from the file on disk.
     *
     * Assigns `false` to the fields when the file is skippable (excluded
     * extension, non-image, unreadable, or virtual) so subsequent calls do
     * not re-run the check.
     *
     * @return void
     */
    protected function setImageSize()
    {
      // to prevent is_null check on get to loop if something is off.
      if (is_null($this->width))
      {
        $this->width = false;
      }
      if (is_null($this->height))
      {
        $this->height = false;
      }

      if (! $this->isExtensionExcluded() && $this->isImage() && $this->is_readable() && ! $this->is_virtual() )
      {
         $info = @getimagesize($this->getFullPath());
         if (is_array($info))
         {
            list($width, $height) = $info; 

         }
  
         if (isset($width))
         {
          $this->width = $width;
         }
         if (isset($height))
         {
          $this->height = $height;
         }
      }


    }
    /**
     * Whether this image is currently eligible for processing.
     *
     * Considers the exclusion rules (extension / size / filesize / path),
     * filesystem readiness (exists / writable / directory writable), the
     * optimize-prevented flag and the already-optimized status. The first
     * result is cached in $processable_status; subsequent calls short-circuit
     * off that cache.
     *
     * @return bool True if the image can be processed right now.
     */
    public function isProcessable()
    {
        // isprocessable runs zillion times, so take the edge off a little.
        if (! is_null($this->processable_status))
        {
            if (self::P_PROCESSABLE === $this->processable_status)
            {
               return true;
            }
            else {
                return false;
            }
        }

        if ( $this->isOptimized() || ! $this->exists()  || (! $this->is_virtual() && ! $this->is_writable()) || 
        (! $this->is_virtual() && ! $this->is_directory_writable() || 
        $this->isPathExcluded() || 
        $this->isExtensionExcluded() || 
        $this->isSizeExcluded() ||
        $this->isFileSizeExcluded()
        )
				|| $this->isOptimizePrevented() !== false
        || ! $this->isFileSizeOK() )
        {
          if(! $this->is_writable() && $this->processable_status == 0)
					{
            $this->processable_status = self::P_FILE_NOTWRITABLE;
					}
					elseif(! $this->is_directory_writable() && $this->processable_status == 0)
					{
            $this->processable_status = self::P_DIRECTORY_NOTWRITABLE;
					}

          return false;
        }
        else
				{
					$this->processable_status = self::P_PROCESSABLE;
          return true;
				}

    }

    /**
     * Whether a WebP or AVIF variant can still be generated for this image.
     *
     * Considers the feature-access gate, the "create WebP" / "create AVIF"
     * settings, PDF exclusion, self-conversion (webp of a webp), and whether
     * a variant already exists or was previously marked as FILETYPE_BIGGER.
     *
     * @param string $type Either 'webp' or 'avif'.
     * @return bool True if a variant of $type is still processable.
     */
    public function isProcessableFileType($type = 'webp')
    {
        $settings = \WPSPIO()->settings();

				if ( AccessModel::getInstance()->isFeatureAvailable($type) === false)
				{
					 return false;
				}

				if ($type == 'webp' && ! $settings->createWebp)
          return false;

        if ($type == 'avif' && ! $settings->createAvif)
            return false;
        
        if ('webp' == $type && 'webp' ==  $this->getExtension())
        {
           return false;
        }

        if ('avif' == $type && 'avif' ==  $this->getExtension())
        {
           return false;
        }


				// Pdf, no special files.
				if ($this->getExtension() == 'pdf')
					return false;

				$imgObj = $this->getImageType($type);

				// if this image doesn't have webp / avif, it can be processed.
        if ($imgObj === false && $this->getMeta($type) !== self::FILETYPE_BIGGER)
          return true;
        else
          return false;
    }

    /**
     * Whether either a WebP or AVIF variant can still be generated.
     *
     * @return bool True if at least one of WebP / AVIF is still processable.
     */
		public function isProcessableAnyFileType()
		{
			  $webp = $this->isProcessableFileType('webp');
				$avif = $this->isProcessableFileType('avif');

				if ($webp === false && $avif === false)
					return false;
				else {
					return true;
				}
		}

    /**
     * Whether the image is excluded because of a user-configured setting
     * (path, size, or filesize exclusion) rather than a system condition
     * (missing file, not writable, etc.).
     *
     * Runs isProcessable() first so $processable_status is populated.
     *
     * @return bool True when the current $processable_status reflects a user exclusion.
     */
    public function isUserExcluded()
    {
      if (is_null($this->processable_status))
      {
         $this->isProcessable();
      }

        $reasons = array(
            self::P_EXCLUDE_PATH,
            self::P_EXCLUDE_SIZE,
            self::P_EXCLUDE_FILESIZE,
        );

        if (in_array($this->processable_status, $reasons))
        {
           return true;
        }
        return false;
    }

    /**
     * Reset the processable-status cache when the current status is a
     * user-configured exclusion, so the next isProcessable() call re-runs.
     *
     * Used by the "process anyway" flow.
     *
     * @return void
     */
    public function cancelUserExclusions()
    {
       if ($this->isUserExcluded())
       {
          $this->processable_status = null;
       }
    }

    /**
     * Check whether the underlying file still exists, updating the
     * processable-status cache with P_FILE_NOT_EXIST when it does not.
     *
     * @param bool $forceCheck Bypass any parent-level caching of the existence check.
     * @return bool True when the file exists.
     */
    public function exists($forceCheck = false)
    {
       $result = parent::exists($forceCheck);
       if ($result === false)
       {
          $this->processable_status = self::P_FILE_NOT_EXIST;
       }
       return $result;
    }

		/**
     * Return the human-readable reason for the requested status cache.
     *
     * Newer semantic wrapper around getProcessableReason() that also handles
     * the restorable-status cache.
     *
     * @param string $name Either 'processable' or 'restorable'.
     * @return string|false Translated reason string, or false when unknown.
     */
		public function getReason($name = 'processable')
		{
				$status = null;

			 if ($name == 'processable')
			 	$status = $this->processable_status;
			 elseif($name == 'restorable')
			 	$status = $this->restorable_status;

			 return $this->getProcessableReason($status);
		}
    
    /**
     * Return the BackupModel associated with this image, using a per-instance
     * cache to avoid repeated controller lookups.
     *
     * @return \ShortPixel\Model\Backup\BackupModel|false Backup model, or false when unavailable.
     */
    public function getBackupModel()
    {
      // BackupModel not set on all images. 
      if (property_exists($this, 'backupModel') &&  false === is_null($this->backupModel))
      {
         return $this->backupModel; 
      }

      $backupController = BackupController::getBackupController();
      $backupModel = $backupController->getModel($this);    
       
      if (property_exists($this, 'backupModel'))
      {
         $this->backupModel = $backupModel; 
      }

      return $backupModel;
    }

    /**
     * Translate a P_* status code into a human-readable, i18n'd reason string.
     *
     * When $status is null the current $processable_status is used.
     *
     * @param int|null $status One of the P_* constants; defaults to the cached processable_status.
     * @return string Translated reason string (may contain HTML for links).
     */
    public function getProcessableReason($status = null)
    {
      $message = false;
			$status = (! is_null($status)) ? $status : $this->processable_status;

      switch($status)
      {
         case self::P_PROCESSABLE:
            $message = __('Image Processable', 'shortpixel-image-optimiser');
         break;
         case self::P_FILE_NOT_EXIST:
            $message = __('File does not exist', 'shortpixel-image-optimiser');
         break;
         case self::P_EXCLUDE_EXTENSION:
            $message = __('Image Extension not processable', 'shortpixel-image-optimiser');
         break;
         case self::P_EXCLUDE_EXTENSION_PDF:
            $message = sprintf(__('PDF processing is not enabled in the %ssettings%s', 'shortpixel-image-optimiser'), '<a href="' .  esc_url(admin_url('options-general.php?page=wp-shortpixel-settings&part=optimisation')) . '">', '</a>');
         break;
         case self::P_EXCLUDE_SIZE:
            $message = __('Image Size Excluded', 'shortpixel-image-optimiser');
         break;
         case self::P_EXCLUDE_FILESIZE: 
            $message = __('Image Filesize excluded', 'shortpixel-image-optimiser');
          break;
         case self::P_EXCLUDE_PATH:
            $message = __('Image Excluded', 'shortpixel-image-optimiser');
         break;
         case self::P_IS_OPTIMIZED:
            $message = __('Image is already optimized', 'shortpixel-image-optimiser');
         break;
         case self::P_FILE_NOTWRITABLE:
            $message = sprintf(__('Image %s (or related thumbnails) is not writable in %s', 'shortpixel-image-optimiser'), $this->getFileName(), (string) $this->getFileDir());
         break;
				 case self::P_DIRECTORY_NOTWRITABLE:
						$message = sprintf(__('Image directory %s is not writable', 'shortpixel-image-optimiser'), (string) $this->getFileDir());
				 break;
				 case self::P_BACKUPDIR_NOTWRITABLE:
				 		$message = __('Backup directory is not writable', 'shortpixel-image-optimiser');
				 break;
				 case self::P_BACKUP_EXISTS:
				 		$message = __('Backup already exists', 'shortpixel-image-optimiser');
				 break;
				 case self::P_OPTIMIZE_PREVENTED:
				 		$message = __('Fatal error preventing processing', 'shortpixel-image-optimiser');
						if (property_exists($this, 'optimizePreventedReason'))
						$message = $this->get('optimizePreventedReason');
				 break;
				 // Restorable Reasons
				 case self::P_RESTORABLE:
				 		$message = __('Image restorable', 'shortpixel-image-optimiser');
				 break;
				 case self::P_BACKUP_NOT_EXISTS:
				 		$message = __('Backup does not exist', 'shortpixel-image-optimiser');
				 break;
				 case self::P_NOT_OPTIMIZED:
				 		$message = __('Image is not optimized', 'shortpixel-image-optimiser');
				 break;
         case self::P_IMAGE_ZERO_SIZE:
            $message = __('File seems empty, or failure on image size', 'shortpixel-image-optimiser');
         break;
         case self::P_EXCLUDE_DATE: 
             $message = __('Date is excluded', 'shortpixel-image-optimiser');
          break; 
         default:
            $message = __(sprintf('Unknown Issue, Code %s',  $status), 'shortpixel-image-optimiser');
         break;
      }

      return $message;
    }



    /**
     * Whether the underlying file is an image.
     *
     * Virtual files are treated as images when the extension is not excluded,
     * because their content cannot be inspected locally. Non-virtual files
     * fall through to the parent FileModel check.
     *
     * @return bool
     */
    public function isImage()
    {
        if (! $this->exists())
        {
          return false;
        }
        if ($this->is_virtual()) // if virtual, don't filecheck on image.
        {
            if (! $this->isExtensionExcluded() )
              return true;
            else
              return false;
        }

        return parent::isImage();
    }

    /**
     * Explicit property getter with lazy initialisation for width/height.
     *
     * @param string $name Property name.
     * @return mixed|null The property value, or null when the property is unknown.
     */
    public function get($name)
    {
       if (property_exists($this, $name))
       {
          if ( ($name == 'width' || $name == 'height') && is_null($this->$name))  // dynamically load this.
          {
            $this->setImageSize();
          }

        return $this->$name;
       }

       return null;
    }


    /**
     * Magic accessor that delegates to get() so `$image->width` works the
     * same as `$image->get('width')`.
     *
     * @param string $name Property name.
     * @return mixed|null
     */
    public function __get($name)
    {
        return $this->get($name);
    }

    /**
     * Read a value from image_meta, or the whole meta object.
     *
     * Unknown property names log a warning and return null.
     *
     * @param string|false $name Meta field name, or false to return the whole meta object.
     * @return mixed|null Meta value, the full meta object when $name === false, or null when the field is unknown.
     */
    public function getMeta($name = false)
    {
      if ($name === false)
      {
        return $this->image_meta;
      }

      if (! property_exists($this->image_meta, $name))
      {
          Log::addWarn('GetMeta on Undefined Property : ' . $name);
          return null;
      }

      return $this->image_meta->$name;
    }

		/**
     * Count how many URLs of a given variant still need to be optimized.
     *
     * Reads the optimize-data array produced by getOptimizeData(), filters it
     * by the requested variant and returns both the matching URL list and its
     * count.
     *
     * @param string $param Variant to count: 'thumbnails' (alias for 'image'), 'webp' or 'avif'.
     * @return array{0: array<int, string>, 1: int} Tuple of URL list and count.
     */
		public function getCountOptimizeData($param = 'thumbnails')
		{
				$optimizeData = $this->getOptimizeData();

				if (! isset($optimizeData['params']) || ! isset($optimizeData['urls']))
				{
					return array([], 0);
				}

				$count = 0;
				$urls = [];

				$params = $optimizeData['params'];

				if ($param == 'thumbnails')
					$param = 'image';

				// Take the optimizeData and take key - param column, then check if the param (image/webp/avif) is true (filter) .
				$combinedArray = array_filter(array_combine(array_keys($params), array_column($params, $param)));

				$count = count($combinedArray);
				foreach($combinedArray as $sizeName => $unneeded)
				{
					 $urls[] = $optimizeData['paths'][$sizeName];
				}
				return array($urls, $count);

		}

	  /**
     * Resolve the FileModel for the WebP or AVIF companion of this image.
     *
     * Prefers the filename stored on image_meta, then falls back to the
     * conventional single-extension (`foo.webp`) or double-extension
     * (`foo.jpg.webp`) layout depending on environment settings. When the
     * `shortpixel/image/filecheck` filter is enabled, the filesystem is
     * re-checked and stale meta entries are cleared.
     *
     * @param string $type Either 'webp' or 'avif'.
     * @return \ShortPixel\Model\File\FileModel|false File model for the variant, or false when none exists.
     */
	  protected function getImageType($type = 'webp')
	  {
	    $fs = \wpSPIO()->filesystem();
			if ($this->getMeta($type) === self::FILETYPE_BIGGER)
				return false;

	    if (! is_null($this->getMeta($type)))
	    {
				// Filter to disable assumption(s) on the file basis of imageType.  Active when something has manually been deleted.
				$metaCheck = apply_filters('shortpixel/image/filecheck', false);
	      $filepath = $this->getFileDir() . $this->getMeta($type);
	      $file = $fs->getFile($filepath);

				if ($metaCheck === false)
				{
					 return $file;
				}
	    }

			if ($type == 'webp')
			{
	    	$is_double = \wpSPIO()->env()->useDoubleWebpExtension();
			}
			if ($type == 'avif')
			{
				$is_double = \wpSPIO()->env()->useDoubleAvifExtension();
			}

			$double_filepath = $this->getFileDir() .  $this->getFileName() . '.' . $type;
		  $filepath = $this->getFileDir() . $this->getFileBase() . '.' . $type;

			if ($is_double)
				$file = $fs->getFile($double_filepath);
			else
	    	$file = $fs->getFile($filepath);

			// If double extension is enabled, but no file, check the alternative.
			if (! $file->is_virtual() && ! $file->exists())
			{
				 if ($is_double)
				 		$file = $fs->getFile($filepath);
				 else
				 		$file = $fs->getFile($double_filepath);
			}

	    if (! $file->is_virtual() && $file->exists())
	      return $file;

      // If we are in filtered special mode and indeed file doesn't not exist anymore, save it. . Metacheck implies that the imagetype was set before the check
      if ( isset($metaCheck) && true === $metaCheck && false === $file->exists())
      {
          $this->setMeta($type, null);
      }
	    return false;
	  }

    /**
     * Convenience wrapper for the WebP companion FileModel.
     *
     * @todo Deprecate this in favor of getImageType('webp').
     * @return \ShortPixel\Model\File\FileModel|false
     */
		public function getWebp()
		{
				return $this->getImageType('webp');
		}

    /**
     * Convenience wrapper for the AVIF companion FileModel.
     *
     * @todo Deprecate this in favor of getImageType('avif').
     * @return \ShortPixel\Model\File\FileModel|false
     */
	  public function getAvif()
	  {
	    	return $this->getImageType('avif');
	  }

    /**
     * Persist the WebP companion filename onto image_meta when one exists on disk.
     *
     * @return void
     */
	  protected function setWebp()
	  {
	      $webp = $this->getImageType('webp');
	      if ($webp !== false && $webp->exists())
        {
	        $this->setMeta('webp', $webp->getFileName() );
        }
	  }

    /**
     * Persist the AVIF companion filename onto image_meta when one exists on disk.
     *
     * @return void
     */
	  protected function setAvif()
	  {
	      $avif = $this->getImageType('avif');
	      if ($avif !== false && $avif->exists())
        {
	        $this->setMeta('avif', $avif->getFileName() );
        }
	  }

    /**
     * Write a value to a known image_meta field, flagging the change so
     * recordChanged() can persist later.
     *
     * Unknown field names are logged and ignored.
     *
     * @param string $name  Meta field name.
     * @param mixed  $value New value.
     * @return false|void False when the field is unknown; otherwise void.
     */
    public function setMeta($name, $value)
    {
      if (! $this->hasMeta($name))
      {
          Log::addDebug('Writing meta non existing' . $name);
          return false;
      }
      else
			{
				if ($this->image_meta->$name !== $value)
				{
					 $this->recordChanged(true, $this->image_meta->$name, $value);
				}
        $this->image_meta->$name = $value;

			}
    }

		/**
     * Mark this image as having a modified meta value this request.
     *
     * $old_value / $new_value are accepted for future auditing but currently
     * unused.
     *
     * @param bool  $bool      True to flag as changed, false to clear.
     * @param mixed $old_value Previous value (currently unused).
     * @param mixed $new_value New value (currently unused).
     * @return void
     */
		protected function recordChanged($bool = true, $old_value = null, $new_value = null)
		{
			 $this->recordChanged = $bool;
		}

    /**
     * Whether any meta value on this image has changed this request.
     *
     * @return bool
     */
    protected function didRecordChange()
    {
       return $this->recordChanged;
    }

    /**
     * Whether the image_meta object exposes a given field.
     *
     * @param string $name Meta field name.
     * @return bool
     */
    public function hasMeta($name)
    {
        return (property_exists($this->image_meta, $name));
    }

    /**
     * Whether this image has been optimized (meta status == FILE_STATUS_SUCCESS).
     *
     * Also seeds the processable-status cache with P_IS_OPTIMIZED as a side effect.
     *
     * @return bool
     */
    public function isOptimized()
    {
      if ($this->getMeta('status') == self::FILE_STATUS_SUCCESS)
      {
          $this->processable_status = self::P_IS_OPTIMIZED;
          return true;
      }

      return false;
    }

    /**
     * Return the optimization improvement for this image.
     *
     * With $int=false returns the percentage improvement (2 decimals).
     * With $int=true returns the absolute byte savings.
     * Negative results (image ended up larger, possible with smartcrop) are
     * clamped to 0.
     *
     * @param bool $int When true return raw byte savings; otherwise return the percentage improvement.
     * @return int|float|false|null Improvement value, false when not optimized, null when sizes are unusable.
     */
    public function getImprovement($int = false)
    {
        if ($this->isOptimized())
        {
            $original = $this->getMeta('originalSize');
            $optimized = $this->getMeta('compressedSize');

            //$diff = $original - $optimized;
            if ($original <= 0 || $optimized <= 0)
              return null;

            if (! $int)
            {
              $number = round(100.0 * (1.0 - $optimized / $original), 2);
            }
            else
            {
              $number =  $original - $optimized;
            }

            if ($number < 0) // It can be optimized in smaller in some cases with smartcrop etc
            {
               return 0; 
            }
            return $number;
        }
        else
          return false;
    }


    /**
     * Post-optimization handler for the main image.
     *
     * Does the generic work: create the backup (unless a converter already
     * did), move the temp file into place (with virtual-file support), update
     * the size / timestamp / compression meta fields, and record resize info.
     * Subclasses are responsible for persisting the meta afterwards.
     *
     * Example `$results` shape returned by the API:
     * ```
     * [image] => [ url, originalSize, optimizedSize, status ]
     * [webp]  => [ url, size, status ]
     * [avif]  => [ url, size, status ]
     * ```
     *
     * @param array $results One image result array from the API.
     * @param array $args    Options; supports 'isConverted' (bool) to skip the backup step when a converter already produced one.
     * @return bool True on success, false on backup / copy failure.
     */
    public function handleOptimized($results, $args = [])
    {
        $settings = \wpSPIO()->settings();
        $fs = \wpSPIO()->filesystem();

				$defaults = array('isConverted' => false,
				);

				$args = wp_parse_args($args, $defaults);

				$status = $results['image']['status'];

          if ($settings->backupImages)
          {
							// If conversion to jpg is done, this function also does the backup.
							if (true === $args['isConverted'])
							{
									 $backupok = true;
							}
							else
							{
              	 $backupok = $this->createBackup();
							}

              if (! $backupok)
              {
                Log::addError('Backup Not OK - ' . $this->getFileName(), $args);

								$response = [
										'is_error' => true,
										'issue_type' => ResponseController::ISSUE_BACKUP_CREATE,
										'message' => __('Could not create backup. Please check file permissions', 'shortpixel-image-optimiser'),
										'fileName' => $this->getFileName(),
                ];

								ResponseController::addData($this->get('id'), $response);

								$this->preventNextTry(__('Could not create backup'));
                return false;
              }
          }

					if (true === $this->is_virtual())
					{
						$originalSize = $results['image']['originalSize'];
					}
					else {
						$originalSize = $this->getFileSize();
					}

          $stati = [ApiController::STATUS_UNCHANGED, ApiController::STATUS_OPTIMIZED_BIGGER, ApiController::STATUS_NOT_COMPATIBLE];
          if (true === in_array($status, $stati, true))
          {
            $copyok = true;
            $optimizedSize = $this->getFileSize();
            
            $tempFile = null;
          }
          else
          {
            if (false === isset($results['image']['file']))
            {
               Log::addError('ImageModel:  Result image files not set! Uncaught issue. ', $results['image']);
               $copyok = false; 
            }
            else 
            {
                $tempFile = $fs->getFile($results['image']['file']);
            }
						
            if ($this->is_virtual() && isset($tempFile))
            {
                $filepath = apply_filters('shortpixel/file/virtual/translate', $this->getFullPath(), $this);
                $virtualFile = $fs->getFile($filepath);
                // Seems stateless like google cloud doesn't like overwrites with declared delete
                if ($this->virtual_status == self::$VIRTUAL_STATELESS)
                {
                    $virtualFile->delete();
                }
                $optimizedSize = $tempFile->getFileSize();


                $copyok = $tempFile->move($virtualFile);

                // File has been copied to local system, set the path to real to be able to get file and image sizes.
                if ($copyok)
                {
                  $this->setVirtualToReal($filepath);
                }
            }
            elseif (isset($tempFile))
            {
                $optimizedSize  = $tempFile->getFileSize();
                $copyok = $tempFile->move($this);
                $this->setImageSize();
            }
          } // else

          if ($copyok)
          {
             $this->processable_status = self::P_IS_OPTIMIZED; // don't let this linger

             $this->setMeta('status', self::FILE_STATUS_SUCCESS);
             $this->setMeta('tsOptimized', time());
             $this->setMeta('compressedSize', $optimizedSize);
             $this->setMeta('originalSize', $originalSize);

             if ($this->hasMeta('did_keepExif'))
              $this->setMeta('did_keepExif', UtilHelper::getExifParameter());
             if ($this->hasMeta('did_cmyk2rgb'))
              $this->setMeta('did_cmyk2rgb', $settings->CMYKtoRGBconversion);

             // Not set before in this case.
             if (is_null($this->getMeta('compressionType')) || $this->getMeta('compressionType') === false)
             {
                $this->setMeta('compressionType', $settings->compressionType);
             }

             if ($settings->resizeImages)
             {

							 $originalWidth = $this->getMeta('originalWidth');
							 $originalHeight = $this->getMeta('originalHeight');

							 $width = $this->get('width'); // image width
							 $height = $this->get('height');

               if ($width != $originalWidth  || $height != $originalHeight ) // resized.
               {
                   $this->setMeta('resizeWidth', $width );
                   $this->setMeta('resizeHeight', $height );
                   $this->setMeta('resize', true);
									 $resizeType = ($settings->resizeType == 1) ? __('Cover', 'shortpixel-image-optimiser') : __('Contain', 'shortpixel-image-optimiser');
									 $this->setMeta('resizeType', $resizeType);
               }
               else
                 $this->setMeta('resize', false);
             }
          }
          else
          {
            Log::addError('Copy failed for  ' . $this->getFullPath() );
            $responseItem = ResponseController::getResponseItem($this->get('id')); 


						$response = array(
								'is_error' => true,
								'issue_type' => ResponseController::ISSUE_BACKUP_CREATE,
								'message' => __('Could not copy optimized image from temporary files. Check file permissions', 'shortpixel-image-optimiser'),
								'fileName' => $this->getFileName(),
						);

						ResponseController::addData($this->get('id'), $response);

            return false;
          }

          return true;

    }

    /**
     * Post-optimization handler for the WebP + AVIF companions of this image.
     *
     * For each variant present in the API result:
     *   - if the API returned a file, move it into place through handleWebp / handleAvif and record its filename on meta;
     *   - if the API returned STATUS_OPTIMIZED_BIGGER / STATUS_NOT_COMPATIBLE, record FILETYPE_BIGGER so the variant isn't re-attempted.
     *
     * @param array $downloadResult API download result array with optional 'webp' / 'avif' sub-arrays.
     * @return void
     */
    public function handleOptimizedFileType($downloadResult)
    {
				 $fs = \wpSPIO()->filesystem();

          if (isset($downloadResult['webp']) && isset($downloadResult['webp']['file'])) // check if there is webp with same filename
          {
						$tmpFile = $fs->getFile($downloadResult['webp']['file']);

             $webpResult = $this->handleWebp($tmpFile);
              if ($webpResult === false)
              {
                if (is_object($tmpFile))
                {
                  Log::addWarn('Webps available, but copy failed ' . $tmpFile->getFullPath());
                }
                else {
                  Log::addWarn('Webps available, but tmpFile not object / failed ', $downloadResult['webp']);
                }
              }
              else
                $this->setMeta('webp', $webpResult->getFileName());
          }
					elseif(isset($downloadResult['webp']) && isset($downloadResult['webp']['status']))
					{
             if ($downloadResult['webp']['status'] == APIController::STATUS_OPTIMIZED_BIGGER)
						 {
							  $this->setMeta('webp', self::FILETYPE_BIGGER);
						 }
             elseif ($downloadResult['webp']['status'] == APIController::STATUS_NOT_COMPATIBLE)
						 {
							  $this->setMeta('webp', self::FILETYPE_BIGGER);
						 }
					}

          if (isset($downloadResult['avif']) && isset($downloadResult['avif']['file'])) // check if there is webp with same filename
          {
						 $tmpFile = $fs->getFile($downloadResult['avif']['file']);
             $avifResult = $this->handleAvif($tmpFile);
              if ($avifResult === false)
                Log::addWarn('Avif available, but copy failed ' . $tmpFile->getFullPath());
              else
                $this->setMeta('avif', $avifResult->getFileName());
          }
					elseif(isset($downloadResult['avif']) && isset($downloadResult['avif']['status']))
					{

             if ($downloadResult['avif']['status'] == APIController::STATUS_OPTIMIZED_BIGGER)
						 {
								$this->setMeta('avif', self::FILETYPE_BIGGER);
						 }
             elseif ($downloadResult['avif']['status'] == APIController::STATUS_NOT_COMPATIBLE)
						 {
							  $this->setMeta('avif', self::FILETYPE_BIGGER);
						 }
					}
    }

    /**
     * Whether this image can be restored from a backup right now.
     *
     * Populates $restorable_status with a P_* code describing the outcome
     * and, on failure branches, records a response through ResponseController.
     *
     * @return bool True when a backup exists and the target is writable / virtual.
     */
    public function isRestorable() : bool
    {
        $backupModel = $this->getBackupModel(); 

			// Check for both optimized and hasBackup, because even if status for some reason is not optimized, but backup is there, restore anyhow.
        if (! $this->isOptimized() && ! $backupModel->hasBackup($this))
        {
					 $this->restorable_status = self::P_NOT_OPTIMIZED;
           return false;  // not optimized, done.
        }
        elseif ($backupModel->hasBackup($this) && ($this->is_virtual() || ($this->is_writable() && $this->is_directory_writable()) ))
        {
					$this->restorable_status = self::P_RESTORABLE;
          return true;
        }
        else
        {
					if ($this->is_virtual()) // Is_virtual, but no backup found ( see up )
					{
						$this->restorable_status = self::P_BACKUP_NOT_EXISTS;
					}
          elseif (! $this->is_writable())
          {
						  $response = array(
									'is_error' => true,
									'issue_type' => ResponseController::ISSUE_FILE_NOTWRITABLE,
									'message' => __('This file can\'t be restored, not writable', 'shortpixel-image-optimiser'),

							);
							ResponseController::addData($this->get('id'), $response);

							$this->restorable_status = self::P_FILE_NOTWRITABLE;
              Log::addWarn('Restore - Not Writable ' . $this->getFullPath() );
          }
					elseif (false === $this->is_directory_writable())
					{
							$response = array(
									'is_error' => true,
									'issue_type' => ResponseController::ISSUE_DIRECTORY_NOTWRITABLE,
									'message' => __('This file can\'t be restored, directory is not writable', 'shortpixel-image-optimiser'),

							);
							ResponseController::addData($this->get('id'), $response);

							$this->restorable_status = self::P_DIRECTORY_NOTWRITABLE;
							Log::addWarn('Restore - Directory not Writable ' . $this->getFileDir() );
					}
          elseif (false ===  $backupModel->hasBackup($this))
					{
						$this->restorable_status = self::P_BACKUP_NOT_EXISTS;
						$response = array(
								'is_error' => true,
								'issue_type' => ResponseController::ISSUE_BACKUP_EXISTS,
								'message' => __('Can\'t restore, backup file doesn\'t exist', 'shortpixel-image-optimiser'),

						);
						ResponseController::addData($this->get('id'), $response);
					}
           return false;
        }
    }

    /**
     * Restore this image from its backup file.
     *
     * NOTE: this only moves the file — the caller is responsible for saving
     * the meta afterwards to reflect the restored state.
     * Clears the width / height / mime / filesize caches on success so the
     * next access re-reads them from the restored file.
     *
     * @return bool True on successful restore, false when not restorable or the move failed.
     */
    public function restore()
    {
        if (! $this->isRestorable())
        {
            Log::addWarn('Trying restore action on non-restorable: ' . $this->getFullPath(), $this->getReason('restorable'));
            return false; // no backup / everything not writable.
        }

        $backupModel = $this->getBackupModel(); 

				$type = $this->get('type');
				$id = $this->get('id');

        $bool = $backupModel->restore($this); 

        if ($bool !== true)
        {
					Log::addError('Moving backupFile failed -' . $this->getFullPath() );
					$response = array(
							'is_error' => true,
							'issue_type' => ResponseController::ISSUE_FILE_NOTWRITABLE,
							'message' => __('Moving Backup file failed', 'shortpixel-image-optimiser'),

					);
					ResponseController::addData($this->get('id'), $response);
        }
				else {
					$this->width = null;
					$this->height = null;
					$this->mime = null;
          $this->filesize = null;
				}

        // Reset statii
        $this->restorable_status = null;
        $this->processable_status = null;
        return $bool;
    }

    /**
     * Handle an image being deleted (by WordPress or otherwise).
     *
     * Cleans up the associated backup and any WebP / AVIF companion files
     * that aren't the primary file itself.
     *
     * @return void
     */
    public function onDelete()
    {
        // @todo This delete should go to backupModel, probably on main item.
        $backupModel = $this->getBackupModel();
        $backupModel->onDelete($this); 

        $webp = $this->getWebp();
        $avif = $this->getAvif();

        if ($webp !== false && $webp->exists() && $this->getExtension() !== 'webp')
        {
          $webp->delete();
        }

        if ($avif !== false && $avif->exists() && $this->getExtension() !== 'avif')
        {
           $avif->delete();
        }
    }

    /**
     * Move an API-produced temporary WebP file into the final location.
     *
     * Handles virtual sources (translating the virtual path back to a real
     * one) and the double-extension convention when enabled. If the target
     * already exists it is considered a success without overwriting.
     *
     * @param FileModel $tempFile Temporary WebP file returned by the API.
     * @return FileModel|false FileModel of the final destination, or false when the move failed.
     */
    protected function handleWebp(FileModel $tempFile)
    {
         $fs = \wpSPIO()->filesystem();
				 if ($this->is_virtual())
				 {
					 	$fullpath = apply_filters('shortpixel/file/virtual/translate', $this->getFullPath(), $this);
						$fileObj = $fs->getFile($fullpath);
						$fileDir = $fileObj->getFileDir();
				 }
				 else {
				 		$fileDir = $this->getFileDir();
				 }

         $target = $fs->getFile( (string) $fileDir . $this->getFileBase() . '.webp');

            // only copy when this constant is set.
            if( true === \wpSPIO()->env()->useDoubleWebpExtension() ) {
                 $target = $fs->getFile((string) $fileDir . $this->getFileName() . '.webp'); // double extension, if exists.
            }

            $result = false;

            if (false === $target->exists()) // don't copy if exists.
            {
							$result = $tempFile->move($target);
						}
            else
						{
              $result = true; // if already exists, all fine by us.
						}

            if (false === $result)
						{
              Log::addWarn('Could not copy Webp to destination ' . $target->getFullPath() );
							return false;
						}
            return $target;

         return false;
    }

    /**
     * Move an API-produced temporary AVIF file into the final location.
     *
     * Handles virtual sources and the double-extension convention when
     * enabled.
     *
     * @param FileModel $tempFile Temporary AVIF file returned by the API.
     * @return FileModel|false FileModel of the final destination, or false when the move failed.
     */
    protected function handleAvif(FileModel $tempFile)
    {
         $fs = \wpSPIO()->filesystem();
				 if ($this->is_virtual())
				 {
						$fullpath = apply_filters('shortpixel/file/virtual/translate', $this->getFullPath(), $this);
						$fileObj = $fs->getFile($fullpath);
						$fileDir = $fileObj->getFileDir();
				 }
				 else {
						$fileDir = $this->getFileDir();
				 }

            $target = $fs->getFile( (string) $fileDir . $this->getFileBase() . '.avif');

						// only copy when this constant is set.
            if( true === \wpSPIO()->env()->useDoubleAvifExtension() ) {
                 $target = $fs->getFile((string) $fileDir . $this->getFileName() . '.avif'); // double extension, if exists.
            }

            $result = $tempFile->move($target);
            if (false === $result)
            {
              Log::addWarn('Could not copy Avif to destination ' . $target->getFullPath() );
              return false; 
            }
            return $target;

         return false;
    }



    /**
     * Evaluate the "name", "path", "regex-name" and "regex-path" exclusion
     * rules against this image. Updates $processable_status to P_EXCLUDE_PATH
     * on a match.
     *
     * @return bool True when at least one rule matches.
     */
    protected function isPathExcluded()
    {
       $excludePatterns = $this->getExcludePatterns();

        if(!$excludePatterns || !is_array($excludePatterns)) { return false; }

        foreach($excludePatterns as $item) {
            $type = (isset($item['type'])) ? trim($item["type"]) : '';
            if(in_array($type, array("name", "path", 'regex-name','regex-path'))) {
                $pattern = trim($item["value"]);
                $target = ($type == "name") ? $this->getFileName() : $this->getFullPath();


                if ($type == 'regex-name' || $type == 'regex-path')
                {
                    $result = $this->matchExcludeRegexPattern($target, $pattern);
                }
                else {
                    $result =  $this->matchExcludePattern($target, $pattern);
                }
                if($result === true) { //search as a substring if not
                    $this->processable_status = self::P_EXCLUDE_PATH;
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check whether the file's extension is excluded from processing.
     *
     * The extension must be in PROCESSABLE_EXTENSIONS; PDFs additionally
     * require the optimizePdfs setting. As a final fallback, a main-file
     * image with a non-listed extension is *not* excluded when a Converter
     * would accept it.
     *
     * Updates $processable_status to P_EXCLUDE_EXTENSION or
     * P_EXCLUDE_EXTENSION_PDF when the exclusion applies.
     *
     * @return bool True when the extension is excluded.
     */
    protected function isExtensionExcluded()
    {

       if ('pdf' === $this->getExtension())
       {
         $settings = \wpSPIO()->settings();
         if (! $settings->optimizePdfs )
         {
           $this->processable_status = self::P_EXCLUDE_EXTENSION_PDF;
            return true;
         }
       }

        if (! is_null($this->getExtension()) && in_array( $this->getExtension() , self::PROCESSABLE_EXTENSIONS))
        {
            return false;
        }


				// If extension not in allowed list, check converters.
				// @todo Most likely move this higher up the chain.
				if (true === $this->is_main_file)
				{
					$converter = Converter::getConverter($this, true);
					if (is_object($converter))
					{
							// Yes can convert, so do not exclude.
							if (true === $converter->isConvertable())
							{
								 return false;
							}
					}
				}


        $this->processable_status = self::P_EXCLUDE_EXTENSION;
        return true;
    }

    /**
     * Substring exclusion match. Empty patterns never match (guards against
     * faulty settings input).
     *
     * @param string $target  Filename or path being tested.
     * @param string $pattern Pattern (plain substring).
     * @return bool True when $pattern is found inside $target.
     */
    protected function matchExcludePattern($target, $pattern) {
        if(strlen($pattern) == 0)  // can happen on faulty input in settings.
          return false;

        if (strpos($target, $pattern) !== false)
        {
          return true;
        }

        return false;
    }

    /**
     * Regex exclusion match. Empty patterns never match.
     *
     * @param string $target  Filename or path being tested.
     * @param string $pattern PCRE-compatible regex including delimiters.
     * @return bool True on a successful, non-empty match.
     */
    protected function matchExcludeRegexPattern($target, $pattern)
    {
      if(strlen($pattern) == 0)  // can happen on faulty input in settings.
        return false;

			$matches = array();
      $m = preg_match($pattern,  $target, $matches);

      if ($m !== false && $m > 0) // valid regex, more hits than zero
      {
        return true;
      }

      return false;
    }

		/**
     * Evaluate "size" exclusion rules against this image's width / height.
     * Updates $processable_status to P_EXCLUDE_SIZE on match.
     *
     * @return bool True when a size rule matched.
     */
		protected function isSizeExcluded()
		{
			$excludePatterns = $this->getExcludePatterns();
			if (! $excludePatterns || ! is_array($excludePatterns) ) // no patterns, nothing excluded
				return false;

			$bool = false;

			foreach($excludePatterns as $item) {
					$type = (isset($item['type'])) ? trim($item["type"]) : '';
					if($type == "size") {

							$width = $this->get('width');
							$height = $this->get('height');

							if( $width && $height
									 && $this->isProcessableSize($width, $height, $item["value"]) === false){
										 $this->processable_status = self::P_EXCLUDE_SIZE;
										return true; // exit directly because we have our exclusion
								}
							else
									$bool = false; // continue and check all patterns, there might be multiple.
						}
			 }

			 return $bool;
		}

    /**
     * Evaluate "filesize" exclusion rules against the file's byte size.
     *
     * Rule format is `"<operator> <value> <unit>"` (e.g. `"> 500 KB"`).
     * Supported operators: `>` and `<`. Virtual / zero-byte files skip the
     * check. Updates $processable_status to P_EXCLUDE_FILESIZE on match.
     *
     * @return bool|null True when a rule matched, false otherwise. Implicit null on malformed rule.
     */
    private function isFileSizeExcluded()
    {
        $excludePatterns = $this->getExcludePatterns();

        if(!$excludePatterns || !is_array($excludePatterns)) { return false; }

        $bool = false; 
        // Support for operators, more characters should be first in array
       // $operators = ['<=', '>=', '<', '>' ]; 
        
        foreach($excludePatterns as $item)
        {
           $type = (isset($item['type'])) ? trim($item["type"]) : '';
           if ('filesize' === $type)
           {  
               $filesize =  $this->getFileSize(); 

               // This indicates remote files / virtual / will not work with that. 
               if ($filesize <= 0)
               {
                  return false;   
               }

               $item_value = explode(' ', $item['value']);
               if (! is_array($item_value) || count($item_value) <> 3)
               {
                 return false; 
               }
               
               $operator = $item_value[0]; 
               $value = $item_value[1]; 
               $bytes = $item_value[2]; 
              
               if ('B' == $bytes)
               {
                 $compare_bytes = $value; 
               }
               else
               {
                $compare_bytes = (int) UtilHelper::convertExclusionFileSizeToBytes($value . $bytes);          
               }
               // About version_compare for this 
              if ('>' == $operator &&  $filesize > $compare_bytes)
              {
                 $bool = true; 
              }
              elseif ('<' == $operator &&  $filesize < $compare_bytes)
              {
                 $bool = true; 
              }
              
              if (true === $bool)
              {
                $this->processable_status = self::P_EXCLUDE_FILESIZE; 
                return $bool; 
              }
           }
        }
        // Convert fileSize to bytes.

        return $bool;
    }

    /**
     * Return the first "date" exclusion rule that applies to this image, or
     * false when no date rule is configured.
     *
     * The caller is expected to compare the returned `date` / `when` pair
     * against the image's own date.
     *
     * @return array{date: string, when: string}|false Rule payload, or false when no match.
     */
    protected function checkDateExcluded()
		{
			$excludePatterns = $this->getExcludePatterns();
			if (! $excludePatterns || ! is_array($excludePatterns) ) // no patterns, nothing excluded
				return false;

			$bool = false;

			foreach($excludePatterns as $item) {
					$type = (isset($item['type'])) ? trim($item["type"]) : '';
					if($type == "date") {

              $check_date = ['date' => $item['value'], 'when' => $item['dateWhen']];
              return $check_date; 
						}
			 }

			 return $bool;
		}

    /**
     * Whether the file on disk has a usable size (or is virtual, which we
     * can't measure locally). Zero-byte files set $processable_status to
     * P_IMAGE_ZERO_SIZE.
     *
     * @return bool
     */
    protected function isFileSizeOK()
    {
        if ($this->is_virtual() || $this->getFileSize() > 0 )
        {
           return true;
        }
        else {
          $this->processable_status = static::P_IMAGE_ZERO_SIZE;
          return false;
        }
    }

    /**
     * Transition a virtual file into a real one by pointing the model at a
     * local path and refreshing the derived file info.
     *
     * Used after downloading a stateless (e.g. S3-offloaded) image back to
     * the local filesystem for further processing.
     *
     * @param string $fullpath Absolute path the file now lives at.
     * @return void
     */
    protected function setVirtualToReal($fullpath)
    {
      $this->resetStatus();
      $this->fullpath = $fullpath;
      $this->directory = null; //reset directory
      $this->is_virtual = false; // stops being virtual
      $this->setFileInfo();
    }

		/**
     * Whether a given width/height pair should be *excluded* by a size rule.
     *
     * Rule format is `"minW-maxW × minH-maxH"` (× / x / X accepted). Bounds
     * may be single values (min=max). Height range is optional.
     *
     * @param int    $width          Image width.
     * @param int    $height         Image height.
     * @param string $excludePattern Rule value from the settings.
     * @return bool True when the dimensions fall *outside* the excluded range (i.e. still processable).
     */
		private function isProcessableSize($width, $height, $excludePattern)
		{

				$ranges = preg_split("/(x|×|X)/",$excludePattern);
				$widthBounds = explode("-", $ranges[0]);
				$minWidth = intval($widthBounds[0]);
				$maxWidth = (!isset($widthBounds[1])) ? intval($widthBounds[0]) : intval($widthBounds[1]);

				$heightBounds = isset($ranges[1]) ? explode("-", $ranges[1]) : false;
				$minHeight = $maxHeight = 0;

				if ($heightBounds)
				{
					$minHeight = intval($heightBounds[0]);
					$maxHeight = (!isset($heightBounds[1])) ? intval($heightBounds[0]) : intval($heightBounds[1]);
				}

				if(   $width >= $minWidth && $width <= $maxWidth
					 && ( $heightBounds === false
							 || ($height >= $minHeight && $height <= $maxHeight) )) {
						return false;
				}
				return true;
		}


    /**
     * Delegate to the image_meta object's toClass() so callers can serialise
     * meta without knowing which meta variant is in use.
     *
     * @return object
     */
    protected function toClass()
    {
        return $this->image_meta->toClass();
    }

    /**
     * Create a backup of the current file through the BackupModel.
     *
     * On failure inspects the backup model's status code, sets
     * preventNextTry() to break the retry loop and populates $error_message
     * for the UI. The `shortpixel/image/skip_backup` filter can short-circuit
     * this method (returning true without creating a backup).
     *
     * @return bool True on success (or when the filter opted out); false on failure.
     */
    protected function createBackup()
    {
        $backupModel = $this->getBackupModel();


       if(apply_filters('shortpixel/image/skip_backup', false, $this->getFullPath(), $this->is_main_file)){
        return true;
       }

        $bool = $backupModel->createBackupFile($this); 
        $statusCode = $backupModel->statusCode; 

        if (false === $bool)
        {

          $backupFile = $backupModel->getBackupFile($this); 
          $backup_filesize = -1; 
          if (is_object($backupFile))
          {
            $backup_filesize = $backupFile->getFileSize(); 
          }
           
           switch($statusCode)
           {
              default: 
                  case BackupModel::ERR_COPY_FAILED: 
                    $this->preventNextTry(__('Issue: The Backup file failed to copy. Check file permissions and retry', 'shortpixel-image-optimiser'));
                    Log::addError('The backup file already exists and it is bigger than the original file. BackupFile Size: ' . $backup_filesize . ' This Filesize: ' . $this->getFileSize(), $this->fullpath);
                    $this->error_message = __('Backup not possible: Copy failed!.', 'shortpixel-image-optimiser');
                  break; 
                  case BackupModel::ERR_BACKUP_EXISTS:
                    $this->preventNextTry(__('Fatal Issue: The Backup file already exists. The backup seems not restorable, or the original file is bigger than the backup, indicating an error.', 'shortpixel-image-optimiser'));
                    Log::addError('The backup file already exists and it is bigger than the original file. BackupFile Size: ' . $backup_filesize . ' This Filesize: ' . $this->getFileSize(), $this->fullpath);
                    $this->error_message = __('Backup not possible: it already exists and the original file is bigger.', 'shortpixel-image-optimiser');
                  break; 
            
              break; 
           }
        }
      
        return $bool;
    }

    /**
     * Convenience accessor for the ShortPixel filesystem controller.
     *
     * @return \ShortPixel\Controller\FileSystemController
     */
    protected function fs()
    {
       return \wpSPIO()->filesystem();
    }

		/**
     * Assemble the per-URL parameter list sent to the ShortPixel API.
     *
     * Resolves the resize / smartcrop policy (they're mutually exclusive at
     * the API level and interact with the size definition of a thumbnail),
     * clamps the target dimensions against the configured resize sizes
     * (doubled for retinas), and marks which variants (image / webp / avif)
     * still need processing.
     *
     * The result is passed through the `shortpixel/image/imageparamlist`
     * filter before being returned.
     *
     * @param array $args Per-URL context; expects at least 'url', 'main_url', 'main_width', 'main_height', and optionally 'smartcrop'.
     * @return array{resize?: int, resize_width?: int, resize_height?: int, url: string, image: bool, webp: bool, avif: bool}
     */
		protected function createParamList($args = array())
		{
			$settings = \wpSPIO()->settings();

		 $resize = false;
		 $hasResizeSizes = (intval($settings->resizeImages) > 0) ? true : false;
		 $result = array();

		 $useSmartcrop = false;
     $useResize = false;

     if ($this->getExtension() !== 'pdf')
     {
    		 if (isset($args['smartcrop']))
    		 {
    			  $useSmartcrop = $args['smartcrop'];
    		 }
    		 else {
    		 	 $useSmartcrop = (bool) $settings->useSmartcrop;
    		 }
     }

     /** This construct. If both resize and smartcrop are on, the smartcrop is applied to cropped images, and resize to the rest. If one or the other is off, apply that setting to all if possible */
     if ($this->getExtension() == 'pdf') // pdf can never be smartcrop
     {
        $useSmartcrop = false;
        if (true === $hasResizeSizes)
        {
          $useResize = true;
        }
     }
     elseif ( true === $useSmartcrop && true === $hasResizeSizes )
     {
        $size = is_array($this->sizeDefinition) ? $this->sizeDefinition : false;

        if (false === $size) // if there is no size definition, err on the safe side.
        {
           $useResize = true;
           $useSmartcrop = false;
        }
        else {
            if (true == $size['crop'])
            {

              $useResize = false;
              $useSmartcrop = true;

              if ($args['main_width'] !== false && $args['main_height'] !== false)
              {
                 $ratio_check = round(($args['main_width'] / $args['main_height']),2) - round($this->get('width') / $this->get('height'), 2);


                 if ($ratio_check == 0)
                 {
                    $useSmartcrop = false;
                    $useResize = true;
                 }

              }
            }
            else {
              $useResize = true;
              $useSmartcrop = false;
            }
        }
     }
		 elseif (true === $useSmartcrop) // these for clarity
		 {
			$useSmartcrop = true;
      $useResize = false;
		 }
		 elseif (true === $hasResizeSizes)
		 {
		 	 $useResize = true;
       $useSmartcrop = false;
		 }

     // Log if this goes wrong, but err on the side of resize if so.
     if (true === $useSmartcrop && true === $useResize)
     {
      Log::addError('Both UseSmartCrop and UseResize are true, this should not be');
     }

     if (true === $useSmartcrop)
     {
        $resize = 4;
     }
     if (true === $useResize)
     {
        $resize = $settings->resizeImages ? 1 + 2 * ($settings->resizeType == 'inner' ? 1 : 0) : 0;
     }

		 if ($resize > 0)
		 {
			 $resize_width = $resize_height = 0; // can be not set.
 	 		 $width = $this->get('width');
			 $height = $this->get('height');

       if (true === $useSmartcrop)
       {
         $url = $args['main_url'];
       }
       else {
         $url = $args['url'];
       }

			 if ($hasResizeSizes)
			 {
			 		$resize_width = intval($settings->resizeWidth);
			 		$resize_height = intval($settings->resizeHeight);
					// If retina, allowed resize sizes is doubled, otherwise big image / big retina would end up same sizes.
					if ($this->get('imageType') == self::IMAGE_TYPE_RETINA)
					{
						 $resize_width = $resize_width * 2;
						 $resize_height = $resize_height * 2;
					}
				}

				$width =  ($width <= $resize_width || $resize_width === 0) ? $width : $resize_width;
				$height = ($height <= $resize_height || $resize_height === 0) ? $height : $resize_height;

			 	$result = array('resize' => $resize, 'resize_width' => $width, 'resize_height' => $height);
			}
      else {
        $url = $args['url'];
      }

      $result['url'] = $url; // select which url to use.

		 // Check if the image is not excluded
		 $imageOk = ($this->isProcessable(true) || $this->isOptimized()) ? true : false ;

		 $result['image'] = $this->isProcessable(true);
		 $result['webp']  = ($imageOk && $this->isProcessableFileType('webp')) ? true : false;
		 $result['avif']  = ($imageOk && $this->isProcessableFileType('avif')) ? true : false;

     $result = apply_filters('shortpixel/image/imageparamlist', $result, $this->id, $this);
		 return $result;

		}



} // model
