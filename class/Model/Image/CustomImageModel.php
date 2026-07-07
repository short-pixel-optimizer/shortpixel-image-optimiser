<?php
namespace ShortPixel\Model\Image;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\QueueController as QueueController;
use ShortPixel\Helper\UtilHelper as UtilHelper;


/**
 * Image model for files managed through the ShortPixel custom-folders feature.
 *
 * Represents a single image that lives outside the WordPress media library and is
 * tracked in the plugin's own `shortpixel_meta` database table. Unlike
 * MediaLibraryModel, this class has no thumbnails and stores all metadata directly
 * in the custom table rather than WordPress post-meta.
 *
 * @package ShortPixel\Model\Image
 */
// @todo Custom Model for adding files, instead of meta DAO.
class CustomImageModel extends \ShortPixel\Model\Image\ImageModel
{

    /** @var int|null ID of the custom folder this image belongs to. */
    protected $folder_id;
    /** @var string|null MD5 hash of the image's full filesystem path (legacy field). */
    protected $path_md5;

    /** @var string Queue/type identifier used when interacting with QueueController. */
    protected $type = 'custom';

    /** @var array Placeholder – custom images have no thumbnail variants. */
    protected $thumbnails = []; // placeholder, should return empty.
    /** @var array Placeholder – custom images have no retina variants. */
    protected $retinas = []; // placeholder, should return empty.

    /** @var bool Whether this image has a corresponding record in the database. */
    protected $in_db = false;
    /** @var bool Whether this object is a stub (not yet persisted) awaiting insertion. */
    protected $is_stub = false;

    /** @var bool Always true for custom images; there is no parent/thumbnail hierarchy. */
    protected $is_main_file = true;
    
    public $name = ImageModel::IMAGE_TYPE_MAIN; 

		/** @var array Settings overrides applied by the UI (e.g. forced smartcrop value). */
		protected $forceSettings = array();  // option derives from setting or otherwise, request to be forced upon via UI to use specific value.


		/**
		 * Load (or stub) a CustomImageModel by its database ID.
		 *
		 * When $id is greater than zero the record is fetched from the database via
		 * loadMeta(). Passing zero or a negative value creates an empty stub that can
		 * be populated later with setStub().
		 *
		 * @param int $id Database ID from the shortpixel_meta table, or 0 for a new stub.
		 */
    public function __construct($id)
    {
        $this->id = $id;

        if ($id > 0)
				{
          $bool = $this->loadMeta();
				}
        else
        {
          $this->fullpath = ''; // stub
          $this->image_meta = new ImageMeta();
          $this->is_stub = true;
        }
        parent::__construct($this->fullpath);
    }

		/**
     * Assign the custom-folder id this image belongs to.
     *
     * Used by the folder-scanning flow to associate a freshly-discovered
     * stub with its owning folder before saveMeta() persists it.
     *
     * @param int $folder_id Custom folder ID from shortpixel_folders.
     * @return void
     */
    public function setFolderId($folder_id)
    {
        $this->folder_id = $folder_id;
    }


    /**
     * Return a flat array of URLs to be submitted for optimization.
     *
     * @return array List of image URLs ready for the ShortPixel API.
     */
    public function getOptimizeUrls()
    {

			$data = $this->getOptimizeData();
			return array_values($data['urls']);

    }

    /** Is WordPress scaled is always false on custom images.
     * 
     * @return false 
     */
    public function isScaled()
    {
       return false; 
    }

    /**
     * Retrieve the active exclusion patterns applicable to custom-folder images.
     *
     * @return array Array of exclusion pattern definitions from UtilHelper::getExclusions().
     */
    protected function getExcludePatterns()
    {
        $args = array(
          'filter' => true,
          'is_custom' => true,
        );

        $patterns = UtilHelper::getExclusions($args);
        return $patterns;
    }

    /**
     * Build the full optimization data payload for this image.
     *
     * Returns an array with 'urls', 'params', and 'returnParams' keys that describe
     * the single image (custom images never have thumbnails) to be sent to the API.
     *
     * @return array{urls: array, params: array, returnParams: array}
     */
		public function getOptimizeData()
		{
				$parameters = array(
						'urls' => array(),
						'params' => array(),
						'returnParams' => array(),
				);

				$fs = \wpSPIO()->filesystem();
        if ($this->is_virtual())
          $url = $this->getFullPath();
        else
          $url = $this->getURL();

				 $settings = \wpSPIO()->settings();
				 $isSmartCrop = ($settings->useSmartcrop == true && $this->getExtension() !== 'pdf') ? true : false;
		 		 $paramListArgs = array(); // args for the params, yes.

		 		 if (isset($this->forceSettings['smartcrop']) && $this->getExtension() !== 'pdf')
		 		 {
		 			  $isSmartCrop = ($this->forceSettings['smartcrop'] == ImageModel::ACTION_SMARTCROP) ? true : false;
		 		 }
				 $paramListArgs['smartcrop'] = $isSmartCrop;
         $paramListArgs['main_url'] = $url;
         $paramListArgs['url'] = $url;

        if ($this->isProcessable(true) || $this->isProcessableAnyFileType())
				{
          $parameters['urls'][0] =  $url;
					$parameters['paths'][0] = $this->getFullPath();
					$parameters['params'][0] = $this->createParamList($paramListArgs);
					$parameters['returnParams']['sizes'][0] =  $this->getFileName();

					if ($isSmartCrop )
					{
						 $parameters['returnParams']['fileSizes'][0] = $this->getFileSize();
					}
  			}

				return $parameters;
		}

    /**
     * Override a specific optimization setting for this image (e.g. force smartcrop on/off).
     *
     * @param string $setting Setting key (e.g. 'smartcrop').
     * @param mixed  $value   Setting value (e.g. ImageModel::ACTION_SMARTCROP).
     * @return void
     */
		public function doSetting($setting, $value)
		{
			  $this->forceSettings[$setting] = $value;
		}

		/**
		 * Return the public-facing URL of this custom image.
		 *
		 * @return string|false URL string, or false if it cannot be determined.
		 */
		public function getURL()
		{
			  return \wpSPIO()->filesystem()->pathToUrl($this);
		}

    /**
     * Return all public URLs associated with this image (always a single-element array).
     *
     * @return array
     */
    public function getAllUrls()
    {
        return array($this->getURL());
    }

    /**
     * Count associated files of a given type for this custom image.
     *
     * Custom images have no thumbnails (always returns 0 for that type). WebP and AVIF
     * counts reflect whether those companion files exist.
     *
     * @param string $type One of 'thumbnails', 'webps', or 'avifs'.
     * @return int
     */
    public function count($type)
    {
      // everything is 1 on 1 in the customModel
      switch($type)
      {
         case 'thumbnails':
            return 0;
         break;
         case 'webps':
            $count = count($this->getWebps());
         break;
         case 'avifs':
            $count = count($this->getAvifs());
         break;
         /*  Never happens / function not here (?)
         case 'retinas':
           $count = count($this->getRetinas());
         break;
         */
         default:
            $count = 0;
         break;
      }


      return $count; // 0 or 1
    }

    /**
     * Whether this custom image can be processed right now.
     *
     * Wraps parent::isProcessable() with two custom-image-specific rules:
     *   - Date-exclusion rules apply to the ts_optimized / ts_added stored on
     *     the record itself, not to a WP post_date (custom images have none).
     *   - `$strict = true` or a size / path exclusion status short-circuits;
     *     otherwise the "still need a WebP/AVIF variant even though the file
     *     is already optimized" flow is checked, gated by directory writability.
     *
     * @param bool $strict When true, only the main image is considered — the WebP/AVIF fallback is skipped.
     * @return bool
     */
    public function isProcessable($strict = false)
    {
        $bool = parent::isProcessable();


        if (true === $bool && false !== $this->checkDateExcluded())
        {
          $date_bool = $this->isDateExcluded();
          if (true === $date_bool)
          {
             return false; 
          }
        } 

				if($strict)
				{
					return $bool;
				}

				// The exclude size on the  image - via regex - if fails, prevents the whole thing from optimization.
				if ($this->processable_status == ImageModel::P_EXCLUDE_SIZE || $this->processable_status == ImageModel::P_EXCLUDE_PATH)
				{
					 return $bool;
				}

      /*  if ($bool === false && $strict === false)
        {
          // Todo check if Webp / Acif is active, check for unoptimized items
          if ($this->isProcessableFileType('webp'))
					{
            $bool = true;
					}
          if ($this->isProcessableFileType('avif'))
					{
             $bool = true;
					}

        } */

				// From above to below was implemented because it could not detect file not writable / directory not writable issues if there was any option to generate webp in the settings. Should check for all those file issues first.

				// First test if this file isn't unprocessable for any other reason, then check.
				if (($this->isProcessable(true) || $this->isOptimized() ) && $this->isProcessableAnyFileType() === true)
				{
					if (false === $this->is_directory_writable())
					{
					 	$bool = false;
					}
					else {
						$bool = true;
					}
				}

        return $bool;
    }

		/**
     * Whether this image can be restored from a backup.
     *
     * Delegates to the parent check first. If that says no, a special-case
     * kicks in: a FILE_STATUS_PREVENT image that still has a backup on disk
     * *is* restorable — that state can happen when optimize was prevented
     * after backup was already created.
     *
     * @return bool
     */
		public function isRestorable() : bool
		{

			 $bool = parent::isRestorable();

			 	// If fine, all fine.
			 	if ($bool == true)
			 	{
			 		return $bool;
				}

        $backupModel = $this->getBackupModel();

				// If not, check this..
				if ($backupModel->hasBackup($this, true) && $this->getMeta('status') == self::FILE_STATUS_PREVENT)
				{
					 	return true;
				}
				else
				{
					  return $bool;
				}
		}

    /**
     * Return the WebP companion as a single-element array (or empty when
     * none exists) to keep parity with the media-library shape.
     *
     * @return \ShortPixel\Model\File\FileModel[]
     */
    protected function getWebps()
    {
         $webp = array($this->getWebp());
         return array_filter($webp);
    }

    /**
     * Return the AVIF companion as a single-element array (or empty when
     * none exists) to keep parity with the media-library shape.
     *
     * @return \ShortPixel\Model\File\FileModel[]
     */
    protected function getAvifs()
    {
         $avif = array($this->getAvif());
         return array_filter($avif);
    }

    /**
     * Return URLs of WebP or AVIF companions that still need to be optimized.
     *
     * Returns the main image URL when a companion of the requested type
     * does not yet exist and the image is either processable or already
     * optimized. PDFs never have companions.
     *
     * Callers should still gate the result with isProcessableFileType() —
     * this method reports discoverability, not settings enablement.
     *
     * @param string $type Either 'webp' or 'avif'.
     * @return string[] Single-element URL list when a companion is still needed; empty otherwise.
     */
    public function getOptimizeFileType($type = 'webp')
    {
        // Pdf files can't have special images.
        if ($this->getExtension() == 'pdf')
          return array();

        if ($type == 'webp')
        {
          $types = $this->getWebps();
        }
        elseif ($type == 'avif')
        {
            $types = $this->getAvifs();
        }

        $toOptimize = array();
        $fs = \WPSPIO()->filesystem();

				// The file must not exist yet.
        if (count($types) == 0 && ($this->isProcessable(true) || $this->isOptimized()) )
          return array($fs->pathToUrl($this));
        else
          return array();

    }

    /**
     * Restore this custom image from its backup and reset optimization state.
     *
     * Fires the pre-restore actions, calls parent::restore() to move the
     * backup back into place, then clears the compressed size / compression
     * type meta, deletes every WebP/AVIF companion file, wipes the webp/avif
     * meta pointers, and persists. Fires the post-restore action either way.
     *
     * @param array $args Reserved for subclass compatibility; currently unused.
     * @return bool True on success, false when the parent restore failed.
     */
    public function restore($args = array())
    {
       do_action('shortpixel_before_restore_image', $this->get('id'));
       do_action('shortpixel/image/before_restore', $this);

			/* $defaults = array(
	 			'keep_in_queue' => false, // used for bulk restore.
	 		); */

	 	//	$args = wp_parse_args($args, $defaults);

       $bool = parent::restore();

			 $return = true;
       if ($bool)
			 {
				 $this->setMeta('status', ImageModel::FILE_STATUS_UNPROCESSED);
				 $this->setMeta('compressedSize', 0);
				 $this->setMeta('compressionType', null);

        $webps = $this->getWebps();
        foreach($webps as $webpFile)
            $webpFile->delete();

        $avifs = $this->getAvifs();
        foreach($avifs as $avifFile)
            $avifFile->delete();


        $this->setMeta('webp', null);
        $this->setMeta('avif', null);
        $this->saveMeta();
			 }
			 else
			 {
				  $return = false;
			 }

			/* if ($args['keep_in_queue'] === false)
			 {
				 $this->dropFromQueue();
			 } */
			 do_action('shortpixel/image/after_restore', $this, $this->id, $bool);

       return $return;
    }

    /**
     * Apply an API optimization result to this custom image.
     *
     * Simpler than the media-library version because there are no
     * thumbnails: the main file is the only member. Runs
     * parent::handleOptimized() for the main file (unless already
     * optimized), applies the WebP/AVIF companion result, then stores the
     * improvement percentage on `customImprovement` and persists.
     *
     * @param array{files: array, data: array} $optimizeData Result payload; expects `files[0]` for the main file's image + webp + avif entries.
     * @param array $args Optional flags forwarded to the parent handler.
     * @return bool True on success, false when the main-file handler failed.
     */
    public function handleOptimized($optimizeData, $args = array())
    {
			 $bool = true;

			 if (isset($optimizeData['files']) && isset($optimizeData['data']))
 			{
 				 $files = $optimizeData['files'];
 				 $data = $optimizeData['data'];
 			}
 			else {
 				Log::addError('Something went wrong with handleOptimized', $optimizeData);
 			}



			 if (! $this->isOptimized() ) // main file might not be contained in results
			 {
       		$bool = parent::handleOptimized($files[0]);
			 }

       $this->handleOptimizedFileType($files[0]);

       if ($bool)
       {
         $this->setMeta('customImprovement', parent::getImprovement());
         $this->saveMeta();
       }

	//		 $this->deleteTempFiles($files);

       return $bool;
    }

    /**
     * Hydrate this instance from its row in shortpixel_meta.
     *
     * Always assigns an ImageMeta object so the caller can rely on
     * $image_meta being non-null even when no row is found. When the row
     * exists, copies path / folder / md5 onto the model, maps every column
     * into the appropriate meta field, and decodes any `extra_info` JSON
     * for the webp/avif "bigger than source" status markers.
     *
     * @return false|void False when no row exists; otherwise void with side effects on $image_meta.
     */
    public function loadMeta()
    {

      global $wpdb;

      $sql = 'SELECT * FROM '  . $wpdb->prefix . 'shortpixel_meta where id = %d';
      $sql = $wpdb->prepare($sql, $this->id);

      $imagerow = $wpdb->get_row($sql);

			$metaObj = new ImageMeta();
			$this->image_meta = $metaObj; // even if not found, load an empty imageMeta.

      if (! is_object($imagerow))
        return false;

      $this->in_db = true; // record found.

      $this->fullpath = $imagerow->path;
      $this->folder_id = $imagerow->folder_id;
      $this->path_md5 = $imagerow->path_md5;

      $status = intval($imagerow->status);
      $metaObj->status = $status;

      if ($status == ImageModel::FILE_STATUS_SUCCESS)
      {
        $metaObj->customImprovement = $imagerow->message;
      }


      $metaObj->compressedSize = intval($imagerow->compressed_size);
			// The null check is important, otherwise it will always optimize wrongly.
      $metaObj->compressionType = (is_null($imagerow->compression_type)) ? null : intval($imagerow->compression_type);

      if (! is_numeric($imagerow->message) && ! is_null($imagerow->message))
        $metaObj->errorMessage = $imagerow->message;

      $metaObj->did_keepExif = intval($imagerow->keep_exif);

      $metaObj->did_cmyk2rgb = (intval($imagerow->cmyk2rgb) == 1) ? true : false;

      $metaObj->resize = (intval($imagerow->resize) > 1) ? true : false;

      if (intval($imagerow->resize_width) > 0)
        $metaObj->resizeWidth = intval($imagerow->resize_width);

      if (intval($imagerow->resize_height) > 0)
        $metaObj->resizeHeight = intval($imagerow->resize_height);

        //$metaObj->has_backup = (intval($imagerow->backup) == 1) ? true : false;

        $addedDate = UtilHelper::DBtoTimestamp($imagerow->ts_added);
        $metaObj->tsAdded = $addedDate;

        $optimizedDate = UtilHelper::DBtoTimestamp($imagerow->ts_optimized);
        $metaObj->tsOptimized = $optimizedDate;

				$extraInfo = property_exists($imagerow, 'extra_info') ? $imagerow->extra_info : null;

				if (! is_null($extraInfo))
				{
					$data = json_decode($extraInfo, true);

					if (isset($data['webpStatus']))
					{
						 $this->setMeta('webp', $data['webpStatus']);
					}
					if (isset($data['avifStatus']))
					{
						 $this->setMeta('avif', $data['avifStatus']);
					}

				}

        $this->image_meta = $metaObj;
    }

		/**
     * Custom images have no parent hierarchy; always returns false.
     *
     * Implemented for signature compatibility with MediaLibraryModel::getParent(),
     * where a WPML duplicate can point at a parent attachment.
     *
     * @return false
     */
		public function getParent()
		{
			 return false;
		}

    /**
     * Populate this instance as a "stub" pointing at a path that may or may
     * not already exist in shortpixel_meta.
     *
     * Used by the custom-folder scanner: for each file found on disk,
     * setStub() decides whether the file is already tracked (loads it if
     * `$load = true`) or fresh (seeds a blank ImageMeta ready for the
     * first saveMeta() insert).
     *
     * @param string $path Absolute filesystem path of the discovered file.
     * @param bool   $load When true and a matching row exists, immediately loadMeta() from it.
     * @return void
     */
    public function setStub($path, $load = true)
    {
       $this->fullpath = $path;
       $this->path_md5 = md5($this->fullpath);

       global $wpdb;

       $sql = 'SELECT id from '  . $wpdb->prefix . 'shortpixel_meta where path =  %s';
       $sql = $wpdb->prepare($sql, $path);

       $result = $wpdb->get_var($sql);
       if ( ! is_null($result)  )
       {
          $this->in_db = true;
          $this->id = $result;
          if ($load)
            $this->loadMeta();
       }
       else
       {
          $this->image_meta = new ImageMeta();
          $this->image_meta->compressedSize = 0;
          $this->image_meta->tsOptimized = 0;
          $this->image_meta->tsAdded = time();

       }

    }

    /**
     * Mark this image as "do not auto-optimize" by writing the reason onto
     * the errorMessage meta and setting the status code, then persisting.
     *
     * Unlike MediaLibraryModel — which stores the flag on post meta —
     * custom images already have their own row, so the state can live
     * directly on the record.
     *
     * @param string $reason Human-readable reason surfaced by isOptimizePrevented().
     * @param int    $status FILE_STATUS_* code; defaults to FILE_STATUS_PREVENT.
     * @return void
     */
    protected function preventNextTry($reason = '', $status = self::FILE_STATUS_PREVENT)
    {
        $this->setMeta('errorMessage', $reason);
        $this->setMeta('status', $status);
        $this->saveMeta();
    }

    /**
     * Semantic wrapper around preventNextTry() for cases where the image
     * is being marked as intentionally completed (e.g. FILE_STATUS_MARKED_DONE)
     * rather than blocked after a fatal error.
     *
     * @param string $reason Human-readable reason.
     * @param int    $status FILE_STATUS_* code to persist.
     * @return void
     */
    public function markCompleted($reason, $status)
    {
       return $this->preventNextTry($reason, $status);
    }

    /**
     * Whether this image is flagged as "do not auto-optimize" and, if so,
     * expose the reason via $optimizePreventedReason + $processable_status.
     *
     * Any FILE_STATUS_PREVENT or FILE_STATUS_MARKED_DONE status counts as
     * prevented; the errorMessage meta is returned as the reason string.
     *
     * @return string|false Reason string when prevented, false otherwise.
     */
    public function isOptimizePrevented()
    {
         $status = $this->getMeta('status');

         if ($status == self::FILE_STATUS_PREVENT || $status == self::FILE_STATUS_MARKED_DONE )
         {
					  $this->processable_status = self::P_OPTIMIZE_PREVENTED;
            $this->optimizePreventedReason  = $this->getMeta('errorMessage');

            return $this->getMeta('errorMessage');
         }


         return false;
    }

    /**
     * Whether the record's tsOptimized (or tsAdded when never optimized)
     * falls on the wrong side of a configured date-exclusion rule.
     *
     * `when=before` excludes items whose reference timestamp is earlier
     * than the rule date; `when=after` (the default) excludes ones later.
     * An unparseable rule date logs a warning and returns false so nothing
     * is silently skipped.
     *
     * Sets $processable_status to P_EXCLUDE_DATE on match.
     *
     * @return bool
     */
    protected function isDateExcluded()
    {
        $options = $this->checkDateExcluded();


        if ($this->getMeta('tsOptimized') > 0)
          $timestamp = $this->getMeta('tsOptimized');
        else
          $timestamp = $this->getMeta('tsAdded');

        $itemDate = new \DateTime();
        $itemDate->setTimestamp($timestamp);


        try{
          $date = new \DateTime($options['date']); 
        }
        catch(\Exception $e)
        {
          Log::addError('[Custom] Date exclusion - not valid date'); 
          return false; 
        }

        $when = isset($options['when']) ? $options['when'] : 'before'; 

        $bool = false; 

        switch($when)
        {
          case 'before':
            if ($date->format('U') > $itemDate->format('U'))
            {
              $bool = true; 
            }
          break; 
          case 'after': 
          default:
          if ($date->format('U') < $itemDate->format('U'))
            {
              $bool = true; 
            }
          break; 
        }

        if (true === $bool)
        {
          $this->processable_status = ImageModel::P_EXCLUDE_DATE; 
        }

        return $bool; 

    }
  

    /**
     * Whether anything in this image is optimized.
     *
     * Custom images have no thumbnails or companions to consider — this
     * is functionally equivalent to isOptimized(). Provided so callers can
     * uniformly ask the family regardless of model type.
     *
     * @return bool
     */
    public function isSomethingOptimized()
    {
       return $this->isOptimized();
    }

    /**
     * Return the optimized image if any exists, mirroring the
     * MediaLibraryModel signature. For custom images, that's always $this
     * when optimized.
     *
     * @return self|false
     */
    public function getSomethingOptimized()
    {
      if ($this->isOptimized())
      {
        return $this;
      }
      return false;
    }

    /**
     * Clear the "prevent auto-optimization" flag on this custom image.
     *
     * Status resets to FILE_STATUS_SUCCESS when a backup still exists on
     * disk (which means the file *was* previously optimized), or to
     * FILE_STATUS_UNPROCESSED when it doesn't. Either way clears the
     * error message and persists.
     *
     * @return void
     */
    public function resetPrevent()
    {
        $backupModel = $this->getBackupModel(); 

				if ($backupModel->hasBackup($this, true))
					$this->setMeta('status', self::FILE_STATUS_SUCCESS);
				else
        	$this->setMeta('status', self::FILE_STATUS_UNPROCESSED);

        $this->setMeta('errorMessage', '');
        $this->saveMeta();
    }

    /**
     * Persist the current state to shortpixel_meta as an INSERT or UPDATE.
     *
     * Uses the $in_db flag to decide between insert and update; on insert,
     * captures the new row id back onto $this->id so subsequent saves go
     * through the update branch.
     *
     * The `message` column is dual-purpose: on success it stores the
     * improvement percentage (from customImprovement), on failure the
     * errorMessage — kept together for legacy compatibility with the pre-
     * modern-meta schema. Format arrays are keyed for readability but the
     * key names aren't used by wpdb.
     *
     * FILETYPE_BIGGER markers for webp/avif are folded into a JSON blob on
     * the `extra_info` column so a single writable payload can hold future
     * per-companion state without schema changes.
     *
     * @return bool True when the row was written, false on DB error.
     */
    public function saveMeta()
    {
        global $wpdb;

       $table = $wpdb->prefix . 'shortpixel_meta';
       $where = array('id' => $this->id);

       $metaObj = $this->image_meta;

       if (! is_null($metaObj->customImprovement) && is_numeric($metaObj->customImprovement))
        $message = $metaObj->customImprovement;
       elseif (! is_null($metaObj->errorMessage))
        $message = $metaObj->errorMessage;
       else
        $message = null;

      $optimized = new \DateTime();
      $optimized->setTimestamp($metaObj->tsOptimized);

      $added = new \DateTime();
      $added->setTimeStamp($metaObj->tsAdded);

			$extra_info = array();
			if ($this->getMeta('webp') === self::FILETYPE_BIGGER)
			{
				 $extra_info['webpStatus']  = self::FILETYPE_BIGGER;
			}
			if ($this->getMeta('avif') === self::FILETYPE_BIGGER)
			{
				 $extra_info['avifStatus']  = self::FILETYPE_BIGGER;
			}

			if (count($extra_info) > 0)
			{
				 $extra_info = json_encode($extra_info);
			}
			else {
				 $extra_info = null;
			}

      $backupModel = $this->getBackupModel();

       $data = array(
            'folder_id' => $this->folder_id,
            'compressed_size' => $metaObj->compressedSize,
            'compression_type' => $metaObj->compressionType,
            'keep_exif' =>  intval($metaObj->did_keepExif),
            'cmyk2rgb' =>  ($metaObj->did_cmyk2rgb) ? 1 : 0,
            'resize' =>  ($metaObj->resize) ? 1 : 0,
            'resize_width' => $metaObj->resizeWidth,
            'resize_height' => $metaObj->resizeHeight,
            'backup' => ($backupModel->hasBackup($this, true)) ? 1 : 0,
            'status' => $metaObj->status,
            'retries' => 0, // this is unused / legacy
            'message' => $message, // this is used for improvement line.
            'ts_added' => UtilHelper::timestampToDB($metaObj->tsAdded),
            'ts_optimized' => UtilHelper::timestampToDB($metaObj->tsOptimized),
            'path' => $this->getFullPath(),
						'name' => $this->getFileName(),
            'path_md5' => md5($this->getFullPath()), // this is legacy
						'extra_info' => $extra_info,
       );
       // The keys are just for readability.
       $format = array(
            'folder_id' => '%d',
            'compressed_size' => '%d',
            'compression_type' => '%d' ,
            'keep_exif' => '%d' ,
            'cmyk2rgb' => '%d' ,
            'resize' => '%d' ,
            'resize_width' => '%d',
            'resize_height' => '%d',
            'backup' => '%d',
            'status' => '%d',
            'retries' => '%d', // this is unused / legacy
            'message' => '%s', // this is used for improvement line.
            'ts_added' => '%s',
            'ts_optimized' => '%s' ,
            'path' => '%s',
						'name' => '%s',
            'path_md5' => '%s' , // this is legacy
						'extra_info' => '%s',
       );


      $is_new = false;

       if ($this->in_db)
      {
        $res = $wpdb->update($table, $data, $where, $format); // result is amount rows updated.
      }
      else
      {
        $is_new = true;
        $res = $wpdb->insert($table, $data, $format); // result is new inserted id
      }

      if ($is_new)
      {
         $this->id = $wpdb->insert_id;
      }

      if ($res !== false)
        return true;
      else
        return false;
    }

    /**
     * Remove this image's row from shortpixel_meta.
     *
     * Does not touch the file on disk — see onDelete() for the full-cleanup
     * flow.
     *
     * @return int|false Number of rows deleted, or false on DB error.
     */
    public function deleteMeta()
    {
      global $wpdb;
      $table = $wpdb->prefix . 'shortpixel_meta';
      $where = array('id' => $this->id);

      $result = $wpdb->delete($table, $where, array('%d'));

      return $result;
    }

    /**
     * Handle deletion of this image.
     *
     * Delegates the on-disk cleanup (main file + WebP/AVIF companions +
     * backup) to parent::onDelete(), removes the shortpixel_meta row, and
     * drops the id from the queue so nothing tries to process a ghost.
     *
     * @return void
     */
    public function onDelete()
    {
				parent::onDelete();
        $this->deleteMeta();
				$this->dropFromQueue();
    }

			/**
     * Remove this image from both the regular custom queue and the bulk
     * queue.
     *
     * @return void
     */
			public function dropFromQueue()
			{
				 $queueController = new QueueController();

				 $queue = $queueController->getQueue($this->type);
				 $queue->dropItem($this->get('id'));

				 // Drop also from bulk if there.
				 $queueController = new QueueController(['is_bulk' => true]);

				 $queue = $queueController->getQueue($this->type);
				 $queue->dropItem($this->get('id'));
			}

    /**
     * Return the optimization improvement recorded on this image.
     *
     * Custom images store the improvement as a single percentage on the
     * `customImprovement` meta field; the byte-savings variant is not
     * tracked here, so `$int = true` returns 0 to honour the parent
     * contract without inventing a value.
     *
     * @param bool $int When true return 0 (byte savings not tracked); otherwise return the percentage.
     * @return int|float|null 0 when $int is true, the percentage improvement, or null when never optimized.
     */
    public function getImprovement($int = false)
    {
       if ($int)
       {
           return 0;
       }
       return $this->getMeta('customImprovement');
    }

    /**
     * Return the improvement payload in the same shape as
     * MediaLibraryModel::getImprovements(), so consumers can be model-
     * agnostic. Custom images have no thumbnails, so the payload always
     * has a single `main` entry plus `totalpercentage` equal to it.
     *
     * @return array{main: array{0: float|int, 1: int}, totalpercentage: int}
     */
    public function getImprovements()
    {
      $improvements = array();
      /*$totalsize = $totalperc = $count = 0;
      if ($this->isOptimized())
      {
         $perc = $this->getImprovement();
         $size = $this->getImprovement(true);
         $totalsize += $size;
         $totalperc += $perc;
         $improvements['main'] = array($perc, $size);
         $count++;
      } */
			$improvement = $this->getImprovement();
			if (is_null($improvement)) // getImprovement can return null.
			{
				$improvement = 0;
			}
      $improvements['main'] = array($improvement, 0);
			$improvements['totalpercentage'] = round($improvement); // the same.

      return $improvements;

    //  return $improvements; // we have no thumbnails.
    }

}
