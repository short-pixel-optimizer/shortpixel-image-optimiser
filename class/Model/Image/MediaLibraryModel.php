<?php
namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use \ShortPixel\ShortPixelPng2Jpg as ShortPixelPng2Jpg;
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\Controller\QuotaController as QuotaController;

use ShortPixel\Helper\InstallHelper as InstallHelper;

class MediaLibraryModel extends \ShortPixel\Model\Image\MediaLibraryThumbnailModel
{

  protected $thumbnails = array(); // thumbnails of this // MediaLibraryThumbnailModel .
  protected $retinas = array(); // retina files - MediaLibraryThumbnailModel (or retina / webp and move to thumbnail? )
  //protected $webps = array(); // webp files -
  protected $original_file = false; // the original instead of the possibly _scaled one created by WP 5.3

  protected $is_scaled = false; // if this is WP 5.3 scaled
  protected $do_png2jpg = false; // option to flag this one should be checked / converted to jpg.

  protected $wp_metadata;

  protected $type = 'media';
  protected $is_main_file = true; // for checking

  private static $unlistedChecked = array(); // limit checking unlisted.

  private $optimizePrevented; // cache if there is any reason to prevent optimizing

	const IMAGE_TYPE_MAIN = 0;
	const IMAGE_TYPE_THUMB = 1;
	const IMAGE_TYPE_ORIGINAL = 2;
	const IMAGE_TYPE_RETINA = 3;
	const IMAGE_TYPE_DUPLICATE = 4;

  public function __construct($post_id, $path)
  {
      $this->id = $post_id;

      parent::__construct($path, $post_id, null);


      // WP 5.3 and higher. Check for original file.
      if (function_exists('wp_get_original_image_path'))
      {
        $this->setOriginalFile();
      }

      if (! $this->isExtensionExcluded())
        $this->loadMeta();

  }


  public function getOptimizePaths()
  {
     $paths = array();

     if ($this->isProcessable(true))
        $paths = array($this->getFullPath());

     foreach($this->thumbnails as $thumbObj)
     {
       if ($thumbObj->isProcessable() )
        $paths = array_merge($paths, $thumbObj->getOptimizePaths());
     }

     $paths = array_values(array_unique($paths));
     return $paths;
  }


	// Path will only return the filepath.  For reasons, see getOptimizeFileType
  public function getOptimizeUrls($get_path = false)
  {
     $settings = \wpSPIO()->settings();

		 if (true === $get_path)
		 {

			 $url = $this->getFullPath();
		 }
		 else{
		 		$url = $this->getURL();
		 }

		 if (! $url) // If the whole image URL can't be found
		 {
			return array();
		 }

     $urls = array();
     if ($this->isProcessable(true))
		 {
	      $urls = array($url);
		 }

     if ($this->isScaled())
     {
        $urls = array_merge($urls, $this->original_file->getOptimizeUrls($get_path));
     }

     foreach($this->thumbnails as $thumbObj)
     {
        if($thumbObj->isThumbnailProcessable())
          $urls = array_merge($urls, $thumbObj->getOptimizeUrls($get_path));
     }

     // @todo Check Retina's
    if ($settings->optimizeRetina)
    {

        foreach($this->retinas as $retinaObj)
        {
           $urls = array_merge($urls, $retinaObj->getOptimizeUrls($get_path));
        }
     }
     $urls = array_values(array_unique($urls));
     return $urls;
  }

  // Try to get the URL via WordPress
	// This is now officially a heavy function.  Take times, other plugins (like s3) might really delay it
  public function getURL()
  {
     $url = $this->fs()->checkURL(wp_get_attachment_url($this->id));
		 return $url;
  }

  /** Get FileTypes that might be optimized. Checking for setting should go via isProcessableFileType!
	*  Get path will return the filepath of said files. This is not useful, except for -checking- if it's processable via isProcessableFileType . GetURL can potentially hit performance, and we don't need it in that case.
	*/
  public function getOptimizeFileType($type = 'webp', $get_path = false)
  {
      if ($type == 'webp')
      {
        $types = $this->getWebps();
      }
      elseif ($type == 'avif')
      {
          $types = $this->getAvifs();
      }

      $toOptimize = array();

      // main file.
      if (! isset($types[0]))
      {
          // The isProcessable(true) is very important, since non-strict calls of this function check this function as well ( resulting in loop )
          if ($this->isProcessable(true) || $this->isOptimized())
          {
            if (parent::getOptimizeFileType($type))
						{
							if (true === $get_path) {
								$toOptimize[] = $this->getFullPath();
							} else {
              	$toOptimize[] = $this->getURL(); // $fs->pathToUrl($this);
							}
           	}
				 }
      }
      if ($this->isScaled() ) // scaled image
      {
        if ($this->original_file->getOptimizeFileType($type) )
				{
						if (true === $get_path)
						{
							$toOptimize[] = $this->original_file->getFullPath();
						}
						else {
							$toOptimize[] = $this->original_file->getURL();
						}
				}
      }

      foreach($this->thumbnails as $thumbName => $thumbObj)
      {
          if ($thumbObj->getOptimizeFileType($type))
					{
						if (true === $get_path)
						{
						  $toOptimize[] = $thumbObj->getFullPath();
						}
						else{
              $toOptimize[] = $thumbObj->getURL(); //$fs->pathToUrl($thumbObj);
						}
					}
      }

      return array_values(array_unique($toOptimize));
      //foreach($types as $index => $)
  }

  public function getWPMetaData()
  {
      if (is_null($this->wp_metadata))
        $this->wp_metadata = wp_get_attachment_metadata($this->id);

      return $this->wp_metadata;
  }

	/** Check if image is scaled by WordPress
	*
	*	@return boolean
	*/
  public function isScaled()
  {
     return $this->is_scaled;
  }

	/** Check and find if there is an error message around
	*
	* This is usually requested after some error status has been detected.
	* Note that the error might not be in the main image, but can also be in a thumbnail!
	*
	*/
  public function getLastErrorMessage()
  {
       $message =  $this->error_message;
			 if (is_null($message) || strlen($message) == 0)
			 {

				   foreach ($this->thumbnails as $thumbnail)
					 {
						  $message = $thumbnail->getLastErrorMessage();
							if (! is_null($message) && strlen($message) > 0)
								return $message;
					 }
			 }

			 return $message;
  }


  /** Loads an array of Thumbnailmodels based on sizes available in WordPress metadata
  **  @return Array consisting ofMediaLibraryThumbnailModel
  **/
  protected function loadThumbnailsFromWP()
  {
    $wpmeta = $this->getWPMetaData();

    $width = null; $height = null;
    if (! isset($wpmeta['width']))
    {
       if ($this->getExtension == 'pdf')
       {
          $width = $wpmeta['full']['width'];
       }
    }
    else
      $width = $wpmeta['width'];


    if (! isset($wpmeta['height']))
    {
       if ($this->getExtension == 'pdf')
       {
          $height = $wpmeta['full']['height'];
       }
    }
    else
      $height = $wpmeta['height'];

    if (is_null($width) || is_null($height) && ! $this->is_virtual())
    {
       $width = (is_null($width)) ? $this->get('width') : $width;
       $height = (is_null($height)) ? $this->get('height') : $height;
    }

    if (is_null($this->originalWidth))
      $this->originalWidth = $width;

    if (is_null($this->originalWidth))
      $this->originalHeight = $height;


    $thumbnails = array();
    if (isset($wpmeta['sizes']))
    {
          foreach($wpmeta['sizes'] as $name => $data)
          {
             if (isset($data['file']))
             {
               $thumbObj = $this->getThumbnailModel($this->getFileDir() . $data['file'], $name);

               $meta = new ImageThumbnailMeta();
               $thumbObj->setName($name);
               $meta->originalWidth = (isset($data['width'])) ? $data['width'] : null; // get from WP
               $meta->originalHeight = (isset($data['height'])) ? $data['height'] : null;
               $thumbObj->setMetaObj($meta);
               $thumbnails[$name] = $thumbObj;
             }
          }
    }

    return $thumbnails;
  }

  protected function getRetinas()
  {
      if (! is_null($this->retinas))
        return $this->retinas;

      $retinas = array();
      $main = $this->getRetina();

      if ($main)
        $retinas[0] = $main; // on purpose not a string, but number to prevent any custom image sizes to get overwritten.

      if ($this->isScaled())
      {
        $retscaled = $this->original_file->getRetina();
        if ($retscaled)
          $retinas[1] = $retscaled; //see main
      }

      foreach ($this->thumbnails as $thumbname => $thumbObj)
      {
        $retinaObj = $thumbObj->getRetina();
        if ($retinaObj)
           $retinas[$thumbname] = $retinaObj;
      }


      return $retinas;
  }

  protected function getWebps()
  {
      $webps = array();

      $main = $this->getWebp();
      if ($main)
        $webps[0] = $main;  // on purpose not a string, but number to prevent any custom image sizes to get overwritten.

      foreach($this->thumbnails as $thumbname => $thumbObj)
      {
         $webp = $thumbObj->getWebp();
         if ($webp)
          $webps[$thumbname] = $webp;
      }
      if ($this->isScaled())
      {
        $webp = $this->original_file->getWebp();
        if ($webp)
          $webps[1] = $webp; //see main
      }

      return $webps;
  }

  protected function getAvifs()
  {
      $avifs = array();
      $main = $this->getAvif();

      if ($main)
        $avifs[0] = $main;  // on purpose not a string, but number to prevent any custom image sizes to get overwritten.

      foreach($this->thumbnails as $thumbname => $thumbObj)
      {
         $avif = $thumbObj->getAvif();
         if ($avif)
          $avifs[$thumbname] = $avif;
      }
      if ($this->isScaled())
      {
        $avif = $this->original_file->getAvif();
        if ($avif)
          $avifs[1] = $avif; //see main
      }

      return $avifs;
  }

  // @todo Needs unit test.
  public function count($type)
  {
      switch($type)
      {
         case 'thumbnails' :
           $count = count($this->thumbnails);
         break;
         case 'webps':
            $count = count(array_unique($this->getWebps()));
         break;
         case 'avifs':
            $count = count(array_unique($this->getAvifs()));
         break;
         case 'retinas':
           $count = count(array_unique($this->getRetinas()));
         break;
      }

      return $count;

  }

  public function handleOptimized($tempFiles)
  {
      $return = true;
			$wpmeta = wp_get_attachment_metadata($this->get('id'));


      if (! $this->isOptimized() && isset($tempFiles[$this->getFileName()]) ) // main file might not be contained in results
      {
					if ($this->getExtension() == 'heic')
					{
						 $isHeic = true;
					}

          $result = parent::handleOptimized($tempFiles);
          if (! $result)
          {
             return false;
          }

					if (isset($isHeic) && $isHeic == true)
					{
						  $metadata = $this->generateThumbnails();
					}

					if ($this->getMeta('resize') == true)
					{
						 $wpmeta['width'] = $this->get('width');
 						 $wpmeta['height'] = $this->get('height');
					}
					$wpmeta['filesize'] = $this->getFileSize();

      }

      $this->handleOptimizedFileType($tempFiles);

      $optimized = array();

			$compressionType = $this->getMeta('compressionType'); // CompressionType not set on subimages etc.

      // If thumbnails should not be optimized, they should not be in result Array.
      foreach($this->thumbnails as $thumbnail)
      {
				 // Check if thumbnail is in the tempfiles return set. This might not always be the case
				 if (! isset($tempFiles[$thumbnail->getFileName()]) )
				 {
					  continue;
				 }

				 $thumbnail->setMeta('compressionType', $compressionType);

         $thumbnail->handleOptimizedFileType($tempFiles); // check for webps /etc

         if ($thumbnail->isOptimized())
         {
					  continue;
				 }
         if (!$thumbnail->isProcessable())
				 {
           continue; // when excluded.
				 }
         $filebase = $thumbnail->getFileBase();
         $result = false;

         if (isset($optimized[$filebase])) // double sizes.
         {
					 $databaseID = $thumbnail->getMeta('databaseID');
           $thumbnail->setMetaObj($optimized[$filebase]);
					 $thumbnail->setMeta('databaseID', $databaseID);  // keep dbase id the same, otherwise it won't write this thumb to DB due to same ID.
					 $result = false;
         }
         else
         {
          $result = $thumbnail->handleOptimized($tempFiles);
         }

				 // Always update the WP meta - except for unlisted files.
				 if ($thumbnail->getMeta('file') === null)
				 {

						 $size = $thumbnail->get('size');
						 if ($thumbnail->getMeta('resize') == true)
						 {
									$wpmeta['sizes'][$size]['width'] = $thumbnail->get('width');
									$wpmeta['sizes'][$size]['height']  = $thumbnail->get('height');
						 }

						 	$wpmeta['sizes'][$size]['filesize'] = $thumbnail->getFileSize();

				 }

         if ($result)
         {
            $optimized[$filebase] = $thumbnail->getMetaObj();
         }
         elseif ($thumbnail->get('prevent_next_try') !== false) // in case of fatal issues.
         {
              $this->preventNextTry($thumbnail->get('prevent_next_try'));
              $return = false; //failed
         }
      }

      if ($this->isScaled() )
      {
          $original_file = $this->getOriginalFile();
          $original_file->handleOptimizedFileType($tempFiles); // @todo Find out why this is before handleOptimized
					$original_file->setMeta('compressionType', $compressionType);

          if (! $original_file->isOptimized())
          {

            $result = $original_file->handleOptimized($tempFiles);

            if (! $result &&  $original_file->get('prevent_next_try') !== false)
            {
                $this->preventNextTry($original_file->get('prevent_next_try'));
                $return = false; // failed.
            }

          }
          $this->original_file = $original_file;
      }

      $this->saveMeta();
			update_post_meta($this->get('id'), '_wp_attachment_metadata', $wpmeta);


			$duplicates = $this->getWPMLDuplicates();
			if (is_array($duplicates) && count($duplicates) > 0)
			{
				// Run the WPML duplicates
				foreach($duplicates as $duplicate_id)
				{
						// Save the exact same data under another post.
						$this->createDuplicateRecord($duplicate_id);

						$duplicate_meta = wp_get_attachment_metadata($duplicate_id);
						$duplicate_meta = array_merge($duplicate_meta, $wpmeta);

						update_post_meta($duplicate_id, '_wp_attachment_metadata', $duplicate_meta);
				}

			}

      return $return;
  }

  public function getImprovements()
  {
        $improvements = array();
        $count = 0;
        $totalsize = 0;
        $totalperc = 0;

        if ($this->isOptimized())
        {
           $perc = $this->getImprovement();
           $size = $this->getImprovement(true);
           $totalsize += $size;
           $totalperc += $perc;
           $improvements['main'] = array($perc, $size);
           $count++;
        }

        foreach($this->thumbnails as $thumbObj)
        {
           if (! $thumbObj->isOptimized())
             continue;

           if (! isset($improvements['thumbnails']))
           {
                $improvements['thumbnails'] = array();
           }
           $perc = $thumbObj->getImprovement();
           $size = $thumbObj->getImprovement(true);
           $totalsize += $size;
           $totalperc += $perc;
           $improvements['thumbnails'][$thumbObj->name] = array($perc, $size);
           $count++;
        }

        if ($count == 0)
          return false; // no improvements;

        $improvements['totalpercentage']  = round($totalperc / $count);
        $improvements['totalsize'] = $totalsize;
        return $improvements;
  }


  /** @param String Full Path to the Thumbnail File
  *   @return Object ThumbnailModel
  * */
  private function getThumbnailModel($path, $size)
  {
      $thumbObj = new MediaLibraryThumbnailModel($path, $this->id, $size);
      return $thumbObj;
  }

  protected function loadMeta()
  {
			$metadata = $this->getDBMeta();

      $settings = \wpSPIO()->settings();

      $this->image_meta = new ImageMeta();
      $fs = \wpSPIO()->fileSystem();

      if (! $metadata)
      {
          // Thumbnails is a an array of ThumbnailModels
          $this->thumbnails = $this->loadThumbnailsFromWP();

          $result = $this->checkLegacy();

          if ($result)
          {
            $this->saveMeta();
						//$metadata = $this->GetDbMeta();
          }
      }
      elseif (is_object($metadata) )
      {
          $this->image_meta->fromClass($metadata->image_meta);

          // Loads thumbnails from the WordPress installation to ensure fresh list, discover later added, etc.
          $thumbnails = $this->loadThumbnailsFromWP();

          foreach($thumbnails as $name => $thumbObj)
          {
             if (isset($metadata->thumbnails[$name])) // Check WP thumbs against our metadata.
             {
                $thumbMeta = new ImageThumbnailMeta();
                $thumbMeta->fromClass($metadata->thumbnails[$name]); // Load Thumbnail data from our saved Meta in model

                // Only get data from WordPress meta if we don't have that yet.

                if (is_null($thumbMeta->originalWidth))
                  $thumbObj->setMeta('originalWidth', $thumbObj->get('width'));

                if (is_null($thumbMeta->originalHeight))
                  $thumbObj->setMeta('originalHeight', $thumbObj->get('height'));

                if (is_null($thumbMeta->tsAdded))
                  $thumbObj->setMeta('tsAdded', time());

                $thumbnails[$name]->setMetaObj($thumbMeta);
                unset($metadata->thumbnails[$name]);
             }
          }

          // Load Thumbnails.
          if (property_exists($metadata,'thumbnails') && count($metadata->thumbnails) > 0) // unlisted in WordPress metadata sizes. Might be special unlisted one, one that was removed etc.
          {
             foreach($metadata->thumbnails as $name => $thumbMeta) // <!-- ThumbMeta is Object
             {
               // Load from Class and file, might be an unlisted one. Meta doesn't save file info, so without might prove a problem!

               // If file is not set, it's indication it's not a unlisted image, we can't add it.
               if (! property_exists($thumbMeta, 'file'))
                 continue;

               $thumbObj = $this->getThumbnailModel($this->getFileDir() . $thumbMeta->file, $name);

               $newMeta = new ImageThumbnailMeta();
               $newMeta->fromClass($thumbMeta);
               //$thumbObj = $this->getThumbnailModel($this->getFileDir() . $thumbmeta['file']);
               //$meta = new ImageThumbnailMeta();
               //$meta->fromClass($thumbMeta); // Load Thumbnail data from our saved Meta in model
               $thumbObj->setMetaObj($newMeta);
               $thumbObj->setName($name);
               if ($thumbObj->exists()) // if we exist.
               {
                $thumbnails[$name] = $thumbObj;
               }

             }
          }
          $this->thumbnails = $thumbnails;

          if (property_exists($metadata, 'retinas') && is_object($metadata->retinas))
          {
              $retinas = $this->getRetinas();
              foreach($metadata->retinas as $name => $retinaMeta)
              {
                  if (isset($retinas[$name]))
                  {
                    $retfile = $retinas[$name];
                    $retinaObj = $this->getThumbnailModel($retfile->getFullPath(), $name);
                    $retMeta = new ImageThumbnailMeta();
                    $retMeta->fromClass($retinaMeta);
                    $retinaObj->setMetaObj($retMeta);
                    $this->retinas[$name] = $retinaObj;
                  }
              }
          }

          if (property_exists($metadata, 'original_file') && is_object($metadata->original_file))
          {
              $orFile = $this->getOriginalFile();
              if ($orFile)
              {
                $orMeta = new ImageThumbnailMeta();
                $orMeta->fromClass($metadata->original_file);
                $orFile->setMetaObj($orMeta);
                $this->original_file = $orFile;
              }
          }

      }

      // settings defaults
      if (is_null($this->getMeta('originalHeight')))
        $this->setMeta('originalHeight', $this->get('height') );

      if (is_null($this->getMeta('originalWidth')))
        $this->setMeta('originalWidth', $this->get('width') );

      // Adds unlisted files to thumbnails array, if needed.
      // This is bound to be bad for performance and not good for big sites!
			// Moved this from isProcessable to be a bit more performance friendly.
      $this->addUnlisted();

  }

	protected function getDBMeta()
	{
		 global $wpdb;

		 // Main Image.
		 $sqlQuery = 'SELECT * FROM ' . $wpdb->prefix . 'shortpixel_postmeta WHERE attach_id = %d ORDER BY parent ASC';
		 $sqlPrep = $wpdb->prepare($sqlQuery, $this->id);
		 $meta = $wpdb->get_results($sqlPrep);

		 // If metadata is null and the last-error discussed about exist (and probably doesn't exist), check the table. s
		 if (count($meta) == 0 && strpos($wpdb->last_error, 'exist') !== false)
		 {
			  InstallHelper::checkTables();
				return false;
		 }
		 elseif (count($meta) == 1 && $meta[0]->image_type == self::IMAGE_TYPE_DUPLICATE)
		 {
				$duplicate_id = $meta[0]->parent;
				$sqlPrep = $wpdb->prepare($sqlQuery, $duplicate_id);
				$meta = $wpdb->get_results($sqlPrep);

		 }
		 elseif (count($meta) == 0) // no records, no object.
		 {

			 $duplicates = $this->getWPMLDuplicates();
			 if (count($duplicates) > 0) //duplicates found, but not saved.
			 {
				 $in_str_arr = array_fill( 0, count( $duplicates ), '%s' );
				 $in_str = join( ',', $in_str_arr );

				 $prepare = array_merge( array(self::IMAGE_TYPE_MAIN), $duplicates);

				 $sql = 'SELECT attach_id FROM ' . $wpdb->prefix . 'shortpixel_postmeta WHERE image_type = %d and attach_id in ( ' . $in_str . ') ';
				 $sql = $wpdb->prepare($sql, $prepare);

				 $parent_id = $wpdb->get_var($sql);

				 if (is_numeric($parent_id))
				 {
					  $this->createDuplicateRecord($this->id, $parent_id);

						$sqlPrep = $wpdb->prepare($sqlQuery, $parent_id);
						$meta = $wpdb->get_results($sqlPrep); // get the parent meta.
				 }
				 else {
				 	  return false;
				 }
			 }
			 else {
			 		 return false;
			 }

		 }

		 // Thumbnails
//		 $sql = 'SELECT * FROM ' . $wpdb->prefix . 'shortpixel_postmeta where parent = %d';
	//		 $sql = $wpdb->prepare($sql, $this->id);

		// Mimic the previous SPixel solution regarding the return Metadata Object needed, with all thunbnails there.
		 $metadata = new \stdClass;
		 $metadata->image_meta = new \stdClass;
		 $metadata->thumbnails = new \stdClass;
		 $metadata->thumbnails = array();

		 //$metadata = new \stdClass; // main image
	   for($i = 0; $i < count($meta); $i++)
		 {
			 	 	$record = $meta[$i];

					// @todo Here goes all the table stuff looking like metadata objects.
					$data = new \stdClass;
					$data->databaseID = $record->id;
					$data->status = $record->status;
					$data->compressionType = $record->compression_type;
					$data->compressedSize = $record->compressed_size;
					$data->originalSize = $record->original_size;

					// @todo This needs to be Mysql TimeStamp -> Unix TS-ilized.
					$data->tsAdded = \ShortPixelTools::DBtoTimestamp($record->tsAdded);
					$data->tsOptimized = \ShortPixelTools::DBtoTimestamp($record->tsOptimized);

					// [...]
					$extra_info = json_decode($record->extra_info);

					// @todo Extra info should probably be stored as JSON?
					if (! is_null($extra_info))
					{
						foreach($extra_info as $name => $val)
						{
							 $data->$name = $val;
						}

						if ($record->parent == 0)
						{
							// Database ID should probably also be stored for the thumbnails, so updating / insert into the database will be easier. We have a free primary key, so why not use it?
								$metadata->image_meta  = $data;
						}
						elseif($record->parent > 0)  // Thumbnails
						{
							 switch($record->image_type)
							 {
								 	 case self::IMAGE_TYPE_THUMB:
									 	$metadata->thumbnails[$record->size] = $data;
									 break;
									 case self::IMAGE_TYPE_RETINA:
									 	$metadata->retinas[$record->size] = $data;
									 break;
									 case self::IMAGE_TYPE_ORIGINAL:
									 	$metadata->original_file = $data;
									 break;
							 }

						}
				} // extra info if
		 } // loop

		 return $metadata;
	}

	/*
	*
	*/
	// @todo Test with retinas, they probably won't work because named after thumbname or 0

	protected function saveDBMeta($metadata)
	{
		 //global $wpdb;

		 $records = array();
		 $records[] = $this->createRecord($metadata->image_meta, self::IMAGE_TYPE_MAIN);

		 if (property_exists($metadata, 'thumbnails'))
		 {
				 foreach($metadata->thumbnails as $name => $thumbData)
				 {
							$records[] = $this->createRecord($thumbData, self::IMAGE_TYPE_THUMB, $name);
				 }
	 	 }

		 if (property_exists($metadata, 'retinas'))
		 {
				 foreach($metadata->retinas as $name => $retData)
				 {
					 	$records[] = $this->createRecord($retData, self::IMAGE_TYPE_RETINA, $name);
				 }
		 }

		 if ($this->isScaled() && property_exists($metadata, 'original_file'))
		 {
			  $orData = $metadata->original_file;
				$records[] = $this->createRecord($orData, self::IMAGE_TYPE_ORIGINAL);
		 }
		 // @todo II -> figure out how to store thumbnails.  Probably either per thumbnails check if it exists in the database and then update or delete.
		 //  This should include a check to see if there are thumbnail sizes no longer in the thumbnails array, probably they should be removed.

		 $this->cleanupDatabase($records);

	}


	private function createRecord($data, $imageType, $sizeName = null)
	{
		 global $wpdb;
		 $table = $wpdb->prefix . 'shortpixel_postmeta';

		 $attach_id = $this->id;

		 $parent = ($imageType == self::IMAGE_TYPE_MAIN) ? 0 : $this->id;

		 if ($imageType == self::IMAGE_TYPE_DUPLICATE)
		 {
			  $attach_id = $data->attach_id;
				$parent = $data->parent;

				unset($data->attach_id);
				unset($data->parent);
		 }


		 $fields = array(
			 	'attach_id' => $attach_id,
				'parent' => $parent,
				'image_type' => $imageType,
				'size' => $sizeName,
				'status' => $data->status,
				'compression_type' => $data->compressionType,
				'compressed_size' => $data->compressedSize,
				'original_size' => $data->originalSize,
				'tsAdded' => \ShortPixelTools::timestampToDB($data->tsAdded),
				'tsOptimized' => \ShortPixelTools::timestampToDB($data->tsOptimized),
		 );

		 unset($data->status);
		 unset($data->compressionType);
		 unset($data->compressedSize);
		 unset($data->originalSize);
		 unset($data->tsAdded);
		 unset($data->tsOptimized);

		 if (property_exists($data, 'databaseID') && intval($data->databaseID) > 0)
		 {
			 $databaseID = $data->databaseID;
			 $insert = false;
		 }
		 else {
		 	 $insert = true;
		 }

		 if(property_exists($data, 'databaseID')) // It can be null on init.
		 {
			 unset($data->databaseID);
		 }

		 if (property_exists($data, 'errorMessage'))
		 {
			  if (is_null($data->errorMessage) || strlen(trim($data->errorMessage)) == 0)
				{
					 unset($data->errorMessage);
				}
		 }

		 foreach ($data as $index => $value)
		 {
			   if (is_null($value)) // don't store things that are null
				 	{
						unset($data->$index);
					}
		 }

		 $fields['extra_info'] = wp_json_encode($data); // everything else

		 $format = array('%d', '%d','%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s');

		 if ($insert === true)
		 {
			  $wpdb->insert($table, $fields, $format);
				$database_id = $wpdb->insert_id;

				switch($imageType)
				{
					 case self::IMAGE_TYPE_MAIN:
					 		$this->setMeta('databaseID', $database_id);
					 break;
					 case self::IMAGE_TYPE_THUMB:
					 		$this->thumbnails[$sizeName]->setMeta('databaseID', $database_id);
					 break;
					 case self::IMAGE_TYPE_RETINA:
					 		$this->retinas[$sizeName]->setMeta('databaseID', $database_id);
					 break;
					 case self::IMAGE_TYPE_ORIGINAL:
					 		$this->original_file->setMeta('databaseID', $database_id);
					 break;
				}
		 }
		 else {
			 	$wpdb->update($table, $fields,  array('id' => $databaseID),$format, array('%d'));
				$database_id = $databaseID;
		 }

		 return $database_id;
	}

	private function createDuplicateRecord($duplicate_id, $parent = null)
	{
		  $data = new \StdClass;

			$data->parent = ($parent == null) ? $this->id : $parent;
			$data->attach_id = $duplicate_id;
			$imageType = self::IMAGE_TYPE_DUPLICATE;

/*
			$data->status = $this->getMeta('status');
			$data->tsOptimized = $this->getMeta('tsOptimized');
			$data->tsAdded = $this->getMeta('tsAdded');
			$data->compressionType = $this->getMeta('compressionType');
			$data->originalSize = $this->getMeta('originalSize');
			$data->compressedSize = $this->getMeta('compressedSize');
*/
			$data->status = null;
			$data->tsOptimized = null;
			$data->tsAdded = null;
			$data->compressionType = null;;
			$data->originalSize = null;
			$data->compressedSize = null;


			$this->createRecord($data, $imageType);
	}

	private function cleanupDatabase($records)
	{
		 global $wpdb;

		 // Empty numbers might erase the whole thing.
		 $records = array_filter($records, 'intval');
		 if (count($records) == 0)
		 	return;


		 $in_str_arr = array_fill( 0, count( $records ), '%s' );
		 $in_str = join( ',', $in_str_arr );

		 $prepare = array_merge( array($this->id), $records);

		 $sql = 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_postmeta WHERE attach_id = %d and id not in (' . $in_str . ') ';
		 $sql = $wpdb->prepare($sql, $prepare);

	//	 Log::addDebug('Cleaning up: ', $records);
		 $wpdb->query($sql);
	}

  private function createSave()
  {
      $metadata = new \stdClass; // $this->image_meta->toClass();
      $metadata->image_meta = $this->image_meta->toClass();
      $thumbnails = array();
      $retinas = array();
    //  $webps = array();

      foreach($this->thumbnails as $thumbName => $thumbObj)
      {
				 if ($thumbObj->getMeta('status') > 0)
				 {
         		$thumbnails[$thumbName] = $thumbObj->toClass();
				 }
      }
      foreach($this->retinas as $index => $retinaObj)
      {
				 if ($retinaObj->getMeta('status') > 0)
				 {
         		$retinas[$index] = $retinaObj->toClass();
				 }
      }

      if (count($thumbnails) > 0)
        $metadata->thumbnails = $thumbnails;
      if (count($retinas) > 0)
        $metadata->retinas = $retinas;

      if ($this->isScaled())
      {
				if ($this->original_file->getMeta('status') > 0 )
				{
        	$metadata->original_file = $this->original_file->toClass();
				}
      }

      return $metadata;
 }

 public function saveMeta()
 {
	   global $wpdb;

		 $metadata = $this->createSave();
		 $this->saveDBMeta($metadata);
     // There is no point checking for errors since false is returned on both failure and no field changed.
     //update_post_meta($this->id, '_shortpixel_meta', $metadata);

     /* if ($this->isOptimized())
     {
        update_post_meta($this->id, '_shortpixel_optimized', $this->getImprovement() );
        update_post_meta($this->id, '_shortpixel_optdate', $this->getMeta('tsOptimized'));
     } */
  }

  /** Delete the ShortPixel Meta. */
  public function deleteMeta()
  {
		global $wpdb;
    // $bool = delete_post_meta($this->id, '_shortpixel_meta');
     //if (! $bool)
     // Log::addWarn('Delete Post Meta failed');

     //delete_post_meta($this->id, '_shortpixel_optimized');
     $this->resetPrevent();
		 //delete_post_meta($this->id, '_shortpixel_was_converted');
     //delete_post_meta($this->id, '_shortpixel_optdate');

		 $sql = 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_postmeta WHERE attach_id = %s';
		 $sql = $wpdb->prepare($sql, $this->id);

		 $bool = $wpdb->query($sql);

     return $bool;
  }

  /** Ondelete is trigger by WordPress deleting an image. SPIO should delete it's data, and backups */
	// FileDelete param for subclass compat.
  public function onDelete($fileDelete = false)
  {
			$duplicates = $this->getWPMLDuplicates();

			$fileDelete = (count($duplicates) == 0) ? true : false;

			if ($fileDelete === true)
      	parent::onDelete();

      foreach($this->thumbnails as $thumbObj)
      {
				if ($fileDelete === true)
        	$thumbObj->onDelete($fileDelete);
      }

      if ($this->isScaled())
      {
         $originalFile = $this->getOriginalFile();
				 if ($fileDelete === true)
				 		$originalFile->onDelete($fileDelete);
      }

		 	$this->removeLegacy();
      $this->deleteMeta();
			$this->dropFromQueue();
  }

	public function dropFromQueue()
	{

		 $optimizeController = new OptimizeController();

		 $q = $optimizeController->getQueue($this->type);
		 $q->dropItem($this->get('id'));

		 // Drop also from bulk if there.

		 $optimizeController->setBulk(true);

		 $q = $optimizeController->getQueue($this->type);
		 $q->dropItem($this->get('id'));
	}

  public function getThumbNail($name)
  {
     if (isset($this->thumbnails[$name]))
        return $this->thumbnails[$name];

      return false;
  }

  /* Check if an image in theory could be processed. Check exclusions, thumbnails etc */
  /* @param Strict Boolean Check only the main image, don't check thumbnails */
  public function isProcessable($strict = false)
  {
      $bool = true;
      $bool = parent::isProcessable();

      $settings = \wpSPIO()->settings();

      if ($this->getExtension() == 'png' && $settings->png2jpg && $this->getMeta('tried_png2jpg') == false)
			{
        $this->do_png2jpg = true;
			}

      if($strict)
			{
        return $bool;
			}

			// The exclude size on the main image - via regex - if fails, prevents the whole thing from optimization.
			if ($this->processable_status == ImageModel::P_EXCLUDE_SIZE)
			{
				 return $bool;
			}

      if (! $bool) // if parent is not processable, check if thumbnails are, can still have a work to do.
      {

          foreach($this->thumbnails as $thumbnail)
          {

            $bool = $thumbnail->isThumbnailProcessable();

            if ($bool === true) // Is Processable just needs one job
              return true;
          }

          // check if original image is optimized.
          if ($this->isScaled())
          {
             $bool = $this->getOriginalFile()->isThumbnailProcessable();
             if ($bool === true)
              return true;
          }
      }

      // Todo check if Webp / Avisf is active, check for unoptimized items
      if ($this->isProcessableFileType('webp'))
			{
        $bool = true;
			}
      if ($this->isProcessableFileType('avif'))
			{
         $bool = true;
			}

      return $bool;
  }



  public function isRestorable()
  {
      $bool = true;
      $bool = parent::isRestorable();


      if (! $bool) // if parent is not processable, check if thumbnails are, can still have a work to do.
      {
          foreach($this->thumbnails as $thumbnail)
          {
            if (! $thumbnail->isOptimized())
               continue;

            $bool = $thumbnail->isRestorable();

            if ($bool === true) // Is Restorable just needs one job
              return true;
          }
          if ($this->isScaled() && ! $bool)
          {
             $originalFile = $this->getOriginalFile();
             $bool = $originalFile->isRestorable();
          }
      }

      return $bool;
  }

  public function convertPNG()
  {
      $settings = \wpSPIO()->settings();
      $bool = false;
			$fs = \wpSPIO()->filesystem();

      if ($this->getExtension() == 'png')
      {
          if ($settings->backupImages == 1)
          {
             $backupok = $this->createBackup();
             if (! $backupok)
             {
							 $response = array(
									'is_error' => true,
									'item_type' => ResponseController::ISSUE_FILE_NOTWRITABLE,
									'message ' => __('ConvertPNG could not create backup. Please check file permissions', 'shortpixel-image-optimiser'),
							 );
								ResponseController::addData($this->get('id'), $response);

								// Bail out with setting flag, so not to repeat.
							 $this->setMeta('tried_png2jpg', true);
							 $this->saveMeta();

               return false;
             }

						 foreach($this->thumbnails as $thumbnail)
						 {
							  $thumbnail->createBackup();
						 }

						 if ($this->isScaled())
						 {
							  $this->getOriginalFile()->createBackup();
						 }

          }

          $pngConvert = new ShortPixelPng2Jpg();
          $bool = $pngConvert->convert($this);
      }

      if ($bool === true)
      {
        $this->setMeta('did_png2jpg', true);
        $mainfile = \wpSPIO()->filesystem()->getfile($this->getFileDir() . $this->getFileBase() . '.jpg');

        if ($mainfile->exists()) // if new exists, remove old
        {
            $this->delete(); // remove the old file.
            $this->fullpath = $mainfile->getFullPath();
            $this->resetStatus();
            $this->setFileInfo();
        }

        // After Convert, reload new meta.
        $this->thumbnails = $this->loadThumbnailsFromWP();

        foreach($this->thumbnails as $thumbObj)
        {
            $file = $fs->getFile($thumbObj->getFileDir() . $thumbObj->getFileBase() . '.jpg');
            $thumbObj->setMeta('did_png2jpg', true);
						$thumbObj->setMeta('status', ImageModel::FILE_STATUS_PENDING);

            if ($file->exists()) // if new exists, remove old
            {
                $thumbObj->delete(); // remove the old file.
                $thumbObj->fullpath = $file->getFullPath();
                $thumbObj->resetStatus();
                $thumbObj->setFileInfo();
            }
        }

		    if ($this->isScaled())
		    {

		       $originalFile = $this->getOriginalFile();
					 $file = $fs->getFile($originalFile->getFileDir() . $originalFile->getFileBase() . '.jpg');
           $originalFile->setMeta('did_png2jpg', true);
					 $originalFile->setMeta('status', ImageModel::FILE_STATUS_PENDING);

            if ($file->exists()) // if new exists, remove old
            {
                $originalFile->delete(); // remove the old file.
                $originalFile->fullpath = $file->getFullPath();
                $originalFile->resetStatus();
                $originalFile->setFileInfo();
            }
				}

        // Update
      }
			else  // false didn't work. This can also be for legimate reasons as big jpg, or transparency.
			{

					if ($settings->backupImages == 1)
					{

						 // When failed, delete the backups. This can't be done via restore since image is not optimized.
						 $backupFile = $this->getBackupFile();
						 if ($backupFile->exists())
						 {
							 $backupFile->delete();
						 }

						 foreach($this->thumbnails as $thumbnail)
						 {
								$backupFile = $thumbnail->getBackupFile();
								// check if there is backup and if file exists.
								if (is_object($backupFile) && $backupFile->exists())
									 $backupFile->delete();
						 }
						 if ($this->isScaled())
						 {
								$backupFile = $this->getOriginalFile()->getBackupFile();
								if ($backupFile->exists())
									 $backupFile->delete();

						 }
					}

					// Prevent from retrying next time, since stuff will be requeued.

			}

			$this->setMeta('tried_png2jpg', true);
			$this->saveMeta();

      return $bool;
  } // convertPNG

	protected function isSizeExcluded()
  {
    $excludePatterns = \wpSPIO()->settings()->excludePatterns;

    if (! $excludePatterns || ! is_array($excludePatterns) ) // no patterns, nothing excluded
      return false;

    foreach($excludePatterns as $item) {
        $type = trim($item["type"]);
        if($type == "size") {
            //$meta = $meta? $meta : wp_get_attachment_metadata($ID);
            $width = $this->get('width');
            $height = $this->get('height');

			//		echo 'w/h'; print_r($width); echo ' '; print_r($height);
			Log::addTemp('Excluded w/h check ' .  $width . ' ' . $height);
	//		Log::addTemp('Check via', debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3));

            if( $width && $height
                 && $this->isProcessableSize($width, $height, $item["value"]) === false){
                   $this->processable_status = self::P_EXCLUDE_SIZE;
                  return true;
              }
            else
                return false;
          }
     }

  }

  private function isProcessableSize($width, $height, $excludePattern) {

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

  // Perhaps treat this as  thumbnail? And remove function from FileSystemController?
  protected function setOriginalFile()
  {
    $fs = \wpSPIO()->filesystem();

    if (is_null($this->id))
      return false;

    $originalFile = $fs->getOriginalImage($this->id);

    if ($originalFile->exists() && $originalFile->getFullPath() !== $this->getfullPath() )
    {
      $this->original_file = $originalFile;
      $this->is_scaled = true;
    }

  }

  public function hasOriginal()
  {
     if ($this->original_file)
      return true;
    else
      return false;
  }

  public function getOriginalFile()
  {
      if ($this->hasOriginal())
        return $this->original_file;
      else
        return false;
  }

	/**  Try to find language duplicates in WPML and add the same status to it.
	** @integration WPML
	*
	* Old _icl_lang_duplicate_of method have been removed, seems legacy.
	*/
  public function getWPMLDuplicates()
  {
    global $wpdb;
    $fs = \wpSPIO()->filesystem();
		$env = \wpSPIO()->env();

		$duplicates = array();


		if ($env->plugin_active('wpml'))
		{
				$sql = "select element_id from " . $wpdb->prefix . "icl_translations where trid in (select trid from " . $wpdb->prefix . "icl_translations where element_id = %d) and element_id <> %d";

				$sql = $wpdb->prepare($sql, $this->id, $this->id);


				$results = $wpdb->get_results($sql);

					if (is_array($results))
					{
						foreach($results as $result)
						{
							 	 if ($result->element_id == $this->id)  // don't select your own.
								 {
									 continue;
								 }
								 //$duplicateFile = $fs->getMediaImage($result->element_id);

								 // Check if the path is the same. WPML translations can be linked to different images, so this is important.
								 // Add. Prev. it loaded to whole media Image but this doesn't go well with loadDbMeta checks, so a rougher check now to see if files are similar. In any case if not identifical, should not be threated as such
								 if (get_attached_file($this->id) == get_attached_file($result->element_id))
								 {
								 		$duplicates[] = $result->element_id;
							 	 }

						}
					}
		}  // wpml
		if ($env->plugin_active('polylang')) // polylang
		{
				// unholy sql where guid is duplicated.
				$sql = 'SELECT id FROM ' . $wpdb->prefix . 'posts WHERE guid in (select guid from ' . $wpdb->prefix . 'posts where id = %d ) and post_type = %s and id <> %d';

				$sql = $wpdb->prepare($sql, $this->id, 'attachment', $this->id);
				$results = $wpdb->get_col($sql);

				foreach($results as $index => $element_id)
				{
					 $duplicates[]= intval($element_id);
				}

		}

    return array_unique($duplicates);
  }

  /* Protect this image from being optimized. This flag should be unset by UX / Retry button on front */
  protected function preventNextTry($reason = 1)
  {
      //Log::addError('Call resulted in preventNextTry on thumbnailModel');
      //exit('Fatal error : Prevent Next Try should not be run on thumbnails');
      Log::addWarn($this->get('id') . ' preventing next try: ' . $reason);
      update_post_meta($this->id, '_shortpixel_prevent_optimize', $reason);

  }

  public function isOptimizePrevented()
  {
       if (! is_null($this->optimizePrevented))
			 {
         return $this->optimizePrevented;
			 }

       $reason = get_post_meta($this->id, '_shortpixel_prevent_optimize', true);

       if ($reason === false || strlen($reason) == 0)
       {
         $this->optimizePrevented = false;
         return false;
       }
       else
       {
 			   $this->processable_status = self::P_OPTIMIZE_PREVENTED;
         $this->optimizePrevented = $reason;
         return $reason;
       }
  }

  public function resetPrevent()
  {
      delete_post_meta($this->id, '_shortpixel_prevent_optimize');
  }

  /** Removed the current attachment, with hopefully removing everything we set.
  */
  public function restore()
  {

    $fs = \wpSPIO()->filesystem();

    do_action('shortpixel_before_restore_image', $this->get('id'));
    do_action('shortpixel/image/before_restore', $this);

    $cleanRestore = true;
		$wpmeta = wp_get_attachment_metadata($this->get('id'));

		$did_png2jpg = $this->getMeta('did_png2jpg');
		$is_resized = $this->getMeta('resize');

		// ** Warning - This will also reset metadata
    $bool = parent::restore();


		if ($is_resized)
		{
			$wpmeta['width'] = $this->get('width');
			$wpmeta['height'] = $this->get('height');
		}
		$wpmeta['filesize'] = $this->getFileSize();


		if ($did_png2jpg)
		{
			 if ($bool)
			 {
			 	$bool = $this->restorePNG2JPG();
				$wpmeta = wp_get_attachment_metadata($this->get('id')); // png2jpg resets WP metadata.
			 }
			 else
			 	 return $bool;
		}


    if (! $bool)
    {
       $cleanRestore = false;
    }

    $restored = array();

    foreach($this->thumbnails as $thumbObj)
    {
          $filebase = $thumbObj->getFileBase();
					$is_resized = $thumbObj->getMeta('resize');
					$size = $thumbObj->get('size');
					$unlisted_file = $thumbObj->getMeta('file');

					// **** AFTER THIS IMAGE DATA IS WIPED! **** /
          if (isset($restored[$filebase]))
          {
            $bool = true;  // this filebase already restored. In case of duplicate sizes.
            $thumbObj->image_meta = new ImageThumbnailMeta();
          }
          elseif ($thumbObj->isRestorable())
					{
            $bool = $thumbObj->restore(); // resets metadata
					}

					if ($unlisted_file === null)
					{

						if ($is_resized)
						{
								$wpmeta['sizes'][$size]['width'] = $thumbObj->get('width');
								$wpmeta['sizes'][$size]['height']  = $thumbObj->get('height');
						}

						$wpmeta['sizes'][$size]['filesize'] = $thumbObj->getFileSize();
					}

          if (! $bool)
					{
            $cleanRestore = false;
					}
          else
          {
             $restored[$filebase] = true;
          }

    }

    if ($this->isScaled() )
    {
       $originalFile = $this->getOriginalFile();
			 if ($originalFile->isRestorable())
		 	 {
       		$bool = $originalFile->restore();
			 }

    }

        $webps = $this->getWebps();
        foreach($webps as $webpFile)
            $webpFile->delete();

        $avifs = $this->getAvifs();
        foreach($avifs as $avifFile)
            $avifFile->delete();

				// Any legacy will have false information by now; remove.
				$this->removeLegacy();

        if ($cleanRestore)
        {
            $this->deleteMeta();
        }
        else
				{
          $this->saveMeta(); // Save if something is not restored.
				}

				update_post_meta($this->get('id'), '_wp_attachment_metadata', $wpmeta);


        do_action('shortpixel_after_restore_image', $this->id, $cleanRestore); // legacy
				do_action('shortpixel/image/after_restore', $this, $this->id, $cleanRestore);

				$duplicates = $this->getWPMLDuplicates();
				if (is_array($duplicates) && count($duplicates) > 0 )
				{
					$current_id = $this->id;

					foreach($duplicates as $duplicate_id)
					{
						 $this->id = $duplicate_id;
						 $this->removeLegacy();

						 $duplicate_meta = wp_get_attachment_metadata($duplicate_id);
						 $duplicate_meta = array_merge($duplicate_meta, $wpmeta);
						 update_post_meta($duplicate_id, '_wp_attachment_metadata', $duplicate_meta);

						 if ($cleanRestore)
						 {
							 	$this->deleteMeta();
						 }
						 else
						 {
							  $this->saveMeta();
						 }
						 do_action('shortpixel_after_restore_image', $this->id, $cleanRestore);
						 do_action('shortpixel/image/after_restore', $this, $this->id,  $cleanRestore);

					}
					$this->id = $current_id;
				}

			// @todo Restore can be false if last item failed, which doesn't sound right.
	    return $bool;
  }

	/** New Setup of RestorePNG2JPG. Runs after copying backupfile back to uploads.
	*/
	protected function restorePNG2JPG()
	{
			$fs = \wpSPIO()->filesystem();

			// ImageModel restore, restored png file to .jpg file ( due to $this)
			// File has just been restored, but it will be wrong extension in uploads
			//
		//	$backupFile = //$this->getBackupFile(); // Should return as PNG file

		 	$destination = $fs->getFile($this->getFileDir() . $this->getFileBase() . '.png');

			// If scaled in the name, revert to originalFile.
			if ($this->isScaled())
			{
					$originalFile = $this->getOriginalFile();
					$destination = $fs->getFile($this->getFileDir() . $originalFile->getFileBase() . '.png');

			}

			// We can't remove files until the end of process because some plugins will block it.
			$toRemove = array();

			// Destination is image.png, the original.
			if (! $destination->exists())
			{
					// This is a PNG content file, that has been restored as a .jpg file which is now main.
					$this->copy($destination);
					$toRemove[] = $this;
			}
			else
			{
					ResponseController::addData('message', __('Restore PNG2JPG : Restoring to target that already exists', 'shortpixel-image-optimiser'));
					ResponseController::addData('is_error', true);

					return false;
			}


    	foreach($this->thumbnails as $thumbObj)
    	{
							if ($thumbObj->hasBackup())
							{
									$backupFile = $thumbObj->getBackupFile();

									$backupFile->delete();

									$backupFileJPG = $fs->getFile($backupFile->getFileDir() . $backupFile->getFileBase() . '.jpg');
									if ($backupFileJPG->exists())
									{
										 $backupFileJPG->delete();
									}
							}

              //  $thumbObj->delete(); // delete the jpg
								$toRemove[] = $thumbObj;
               // $thumbObj->resetStatus();
							//	$thumbObj->image_meta = new ImageThumbnailMeta();
    		}

		    if ($this->isScaled())
		    {

		       	$originalFile = $this->getOriginalFile();

						if ($originalFile->hasBackup())
						{
								$backupFile = $originalFile->getBackupFile();
								$backupFile->delete();

								$backupFileJPG = $fs->getFile($backupFile->getFileDir() . $backupFile->getFileBase() . '.jpg');
								if ($backupFileJPG->exists())
								{
									 $backupFileJPG->delete();
								}
						}

						$toRemove[] = $originalFile;
//						$originalFile->delete(); // should be .jpg file.
//			      $originalFile->resetStatus();
//						$originalFile->image_meta = new ImageThumbnailMeta();
		    }

				// Fullpath now will still be .jpg
				// PNGconvert is first, because some plugins check for _attached_file metadata and prevent deleting files if still connected to media library. Exmaple: polylang.
				$pngConvert = new ShortPixelPng2Jpg();
				$pngConvert->restorePng2Jpg($this);

				foreach($toRemove as $fileObj)
				{
					 $fileObj->delete();
					 $fileObj->resetStatus();
					 if ($fileObj->get('is_main_file') == false)
					 {
					 	$fileObj->image_meta = new ImageThumbNailMeta();
					 }
				}
				$this->wp_metadata = null;  // restore changes the metadata.

				return true;
	}

  /** This function will recreate thumbnails. This is -only- needed for very special cases, i.e. offload */
  public function wpCreateImageSizes()
  {
    add_filter('as3cf_wait_for_generate_attachment_metadata', array($this, 'returnTrue'));

    $fullpath = $this->getFullPath();
    if ($this->isScaled()) // if scaled, the original file is the main file for thumbnail base
    {
       $originalFile = $this->getOriginalFile();
       $fullpath = $originalFile->getFullPath();
    }
    $res = \wp_create_image_subsizes($fullpath, $this->id);

    remove_filter('as3cf_wait_for_generate_attachment_metadata', array($this, 'returnTrue'));

  }


  public function returnTrue()
  {
     return true;
  }

	// Function to remove all shortpixel related data
	// It's separated from the private function.
	public function removeLegacyShortPixel()
	{
		 $bool = $this->removeLegacy();
		 if ($bool)
		 {
		 		delete_post_meta($this->id, '_shortpixel_was_converted');
				delete_post_meta($this->id, '_shortpixel_status');
		 }
	}

	private function generateThumbnails()
	{
	 	 $metadata = wp_generate_attachment_metadata($this->get('id'), $this->getFullPath());
		 return $metadata;
	}

	// Check and remove legacy data.
	// If metadata is removed in a restore process, the legacy data will be reimported, which should not happen.
	/* @return bool If legacy data was found and removed or not */
	private function removeLegacy()
	{
		$metadata = $this->getWPMetaData();
		$updated = false;


		$unset = array('ShortPixel', 'ShortPixelImprovement', 'ShortPixelPng2Jpg');

		foreach($unset as $key)
		{
			 if (isset($metadata[$key]))
			 {
				  unset($metadata[$key]);
					$updated = true;
			 }
		}

		if ($updated === true)
		{
			wp_update_attachment_metadata($this->id, $metadata);
		}

		return $updated;
	}

  // Convert from old metadata if needed.
  private function checkLegacy()
  {
      $metadata = $this->getWPMetaData();

      if (! isset($metadata['ShortPixel']))
      {
        return false;
      }

      $data = $metadata['ShortPixel'];

      if (count($data) == 0)  // This can happen. Empty array is still nothing to convert.
        return false;

			// Waiting for processing is a state where it's not optimized, or should be.
			// The last check is because it seems that it can be both improved and waiting something ( sigh ) // 04/07/22
			if (count($data) == 1 && isset($data['WaitingProcessing']) && ! isset($data['ShortPixelImprovement']))
			{
				 return false;
			}

      // This is a switch to prevent converted items to reconvert when the new metadata is removed ( i.e. restore )
      $was_converted = get_post_meta($this->id, '_shortpixel_was_converted', true);
      if ($was_converted == true || is_numeric($was_converted))
      {
				$updateTs = 1656892800; // July 4th 2022 - 00:00 GMT
				if ($was_converted < $updateTs && $this->hasBackup())
				{
					$this->resetPrevent();  // reset any prevented optimized. This would have prob. thrown a backup issue.
					if ($this->isProcessable())
					{
						 $this->deleteMeta();
						 Log::addDebug('Conversion pre-bug detected with backup and still processable. Trying to fix by redoing legacy.');
					}

				}
				else {
				   Log::addDebug('No SPIO5 metadata, but this item was converted, not converting again');
					 return false;
				}
      }

			$quotaController = QuotaController::getInstance();
			if ($quotaController->hasQuota() === false)
			{
				$adminNotices = AdminNoticesController::getInstance();
				$adminNotices->invokeLegacyNotice();
			}

        Log::addDebug("Conversion of legacy: ", array($metadata));

       $type = isset($data['type']) ? $this->legacyConvertType($data['type']) : '';

       $improvement = (isset($metadata['ShortPixelImprovement']) && is_numeric($metadata['ShortPixelImprovement']) && $metadata['ShortPixelImprovement'] > 0) ? $metadata['ShortPixelImprovement'] : 0;

       $status = $this->legacyConvertStatus($data, $metadata);

       $error_message = isset($metadata['ShortPixelImprovement']) && ! is_numeric($metadata['ShortPixelImprovement']) ? $metadata['ShortPixelImprovement'] : '';

    //   $retries = isset($data['Retries']) ? intval($data['Retries']) : 0;
       $optimized_thumbnails = (isset($data['thumbsOptList']) && is_array($data['thumbsOptList'])) ? $data['thumbsOptList'] : array();
       $exifkept = (isset($data['exifKept']) && $data['exifKept']  == 1) ? true : false;

       $tsAdded = time();

			 if ($status == self::FILE_STATUS_SUCCESS)
       {
         //strtotime($tsOptimized)
				 $thedate = (isset($data['date'])) ? $data['date'] : false;
				 $newdate = \DateTime::createFromFormat('Y-m-d H:i:s', $thedate);

				 if ($newdate === false)
				 {
					 $newdate = \DateTime::createFromFormat('Y-m-d H:i:s', get_post_time('Y-m-d H:i:s', false, $this->id));
				 }

         $newdate = $newdate->getTimestamp();

         $tsOptimized = $newdate;
         $this->image_meta->tsOptimized = $tsOptimized;
       }

       $this->image_meta->wasConverted = true;
       $this->image_meta->status = $status;
       //$this->image_meta->type = $type;
       $this->image_meta->improvement = $improvement;
       $this->image_meta->compressionType = $type;
       $this->image_meta->compressedSize = $this->getFileSize();
     //  $this->image_meta->retries = $retries;
       $this->image_meta->tsAdded = $tsAdded;
     //  $this->image_meta->has_backup = $this->hasBackup();
       $this->image_meta->errorMessage = $error_message;

       $this->image_meta->did_keepExif = $exifkept;

	      if ($this->hasBackup())
	      {
	        $backup = $this->getBackupFile();
	        $this->image_meta->originalSize = $backup->getFileSize();
	      }
				elseif ( isset($metadata['ShortPixelImprovement']))
				{
					 // If the improvement is set, calculate back originalsize.
					 $imp = intval($metadata['ShortPixelImprovement']); // try to make int. Legacy can contain errors / message / crap here.
	 			   if ($imp > 0)
	 				  	$this->image_meta->originalSize = ($this->getFileSize() / (100 - $imp)) * 100;
				}


        $this->image_meta->webp = $this->checkLegacyFileTypeFileName($this, 'webp');
				$this->image_meta->avif = $this->checkLegacyFileTypeFileName($this, 'avif');


       $this->width = isset($metadata['width']) ? $metadata['width'] : false;
       $this->height = isset($metadata['height']) ? $metadata['height'] : false;

			 $this->recordChanged(true);


       if (isset($metadata['ShortPixelPng2Jpg']))
       {
           $this->image_meta->did_png2jpg = true; //setMeta('did_png2jpg', true);
           $did_jpg2png = true;
       }
       else
           $did_jpg2png = false;
    //   $this->image_meta->did_cmyk2rgb = $exifkept;
      // $this->image_meta->tsOptimized =

       foreach($this->thumbnails as $thumbname => $thumbnailObj) // ThumbnailModel
       {
          if (in_array($thumbnailObj->getFileName(), $optimized_thumbnails))
          {
              $thumbnailObj->image_meta->status = $status;
              $thumbnailObj->image_meta->compressionType = $type;
              $thumbnailObj->image_meta->compressedSize = $thumbnailObj->getFileSize();
              $thumbnailObj->image_meta->did_jpg2png = $did_jpg2png;
          //    $thumbnailObj->image_meta->improvement = -1; // n/a
              if ($thumbnailObj->hasBackup())
              {
                $backup = $thumbnailObj->getBackupFile();
                $thumbnailObj->image_meta->originalSize = $backup->getFileSize();
              }

              $thumbnailObj->image_meta->tsAdded = $tsAdded;
              if (isset($tsOptimized))
                $thumbnailObj->image_meta->tsOptimized = $tsOptimized;

              $thumbnailObj->has_backup = $thumbnailObj->hasBackup();

							$thumbnailObj->image_meta->webp = $this->checkLegacyFileTypeFileName($thumbnailObj, 'webp');
							$thumbnailObj->image_meta->avif = $this->checkLegacyFileTypeFileName($thumbnailObj, 'avif');

              if (strpos($thumbname, 'sp-found') !== false) // File is 'unlisted', also save file information.
              {
                 $thumbnailObj->image_meta->file = $thumbnailObj->getFileName();
              }

							$thumbnailObj->recordChanged(true);
              $this->thumbnails[$thumbname] = $thumbnailObj;

          }
       }

       if ($this->isScaled())
       {
         $originalFile = $this->original_file;

         if (isset($metadata['original_image']))
         {

           $originalFile->image_meta->status = $status;
           $originalFile->image_meta->compressionType = $type;
           $originalFile->image_meta->compressedSize = $originalFile->getFileSize();
           $originalFile->image_meta->did_jpg2png = $did_jpg2png;
       //    $thumbnailObj->image_meta->improvement = -1; // n/a

			     if ($originalFile->hasBackup())
           {
             $backup = $originalFile->getBackupFile();
             $originalFile->image_meta->originalSize = $backup->getFileSize();
           }

           $originalFile->image_meta->tsAdded = $tsAdded;
           $originalFile->image_meta->tsOptimized = $tsOptimized;
           $originalFile->has_backup = $originalFile->hasBackup();

					 $originalFile->image_meta->webp = $this->checkLegacyFileTypeFileName($originalFile, 'webp');
					 $originalFile->image_meta->avif = $this->checkLegacyFileTypeFileName($originalFile, 'avif');


           if (strpos($thumbname, 'sp-found') !== false) // File is 'unlisted', also save file information.
           {
              $originalFile->image_meta->file = $originalFile->getFileName();
           }

					  $originalFile->recordChanged(true);
          }
       }

       if (isset($data['retinasOpt']))
       {
           $count = $data['retinasOpt'];

           $retinas = $this->getRetinas();

           foreach($retinas as $index => $retinaObj) // Thumbnail Model
           {

              $retinaObj->image_meta->status = $status;
              $retinaObj->image_meta->compressionType = $type;
              if ($status == self::FILE_STATUS_SUCCESS)
                $retinaObj->image_meta->compressedSize = $retinaObj->getFileSize();
              else
                $retinaObj->image_meta->originalSize = $retinaObj->getFileSize();
            //  $retinaObj->image_meta->improvement = -1; // n/a
              $retinaObj->image_meta->tsAdded = $tsAdded;
              $retinaObj->image_meta->tsOptimized = $tsOptimized;
              $retinaObj->image_meta->did_jpg2png = $did_jpg2png;
              if ($retinaObj->hasBackup())
              {
                $retinaObj->has_backup = true;
                if ($status == self::FILE_STATUS_SUCCESS)
                  $retinaObj->image_meta->originalSize = $retinaObj->getBackupFile()->getFileSize();
              }

							$retinaObj->recordChanged(true);
              $retinas[$index] = $retinaObj;
           }
           $this->retinas = $retinas;
           if ($count !== count($retinas))
           {
              Log::addWarning("Conversion: $count retinas expected in legacy, " . count($retinas) . 'found', $retinas);
           }
       }


       update_post_meta($this->id, '_shortpixel_was_converted', time());
       delete_post_meta($this->id, '_shortpixel_status');

      return true;
  }

	private function checkLegacyFileTypeFileName($fileObj, $type)
	{
		 	$fileType = $fileObj->getImageType($type);
			if ($fileType !== false)
			{
				return $fileType->getFileName();
			}

			$env = \wpSPIO()->env();
			$fs = \wpSPIO()->filesystem();

// try the whole thing, but fetching remote URLS, test if really S3 not in case something went wrong with is_virtual, or it's just something messed up.
			if ($fileObj->is_virtual() && $env->plugin_active('s3-offload') )
			{


				if ($type == 'webp')
				{
					$is_double = \wpSPIO()->env()->useDoubleWebpExtension();
				}
				if ($type == 'avif')
				{
					$is_double = \wpSPIO()->env()->useDoubleAvifExtension();
				}

				$url = str_replace('.' . $fileObj->getExtension(), '.' . $type, $fileObj->getURL());
				$double_url = $fileObj->getURL() . '.' . $type;

				$double_filename = $fileObj->getFileName() . '.' . $type;
				$filename =  $fileObj->getFileBase() . '.' . $type;

				if ($is_double)
				{
					$url_exists = $fs->url_exists($double_url);
					if ($url_exists === true)
						return $double_filename;
				}
				else
				{
					$url_exists = $fs->url_exists($url);
					if ($url_exists === true)
						 return $filename;
				}

				// If double extension is enabled, but no file, check the alternative.
					 if ($is_double)
					 {
							$url_exists = $fs->url_exists($url);
							if ($url_exists === true)
								 return $filename;
					 }
					 else
					 {
							$url_exists = $fs->url_exists($double_url);
							if ($url_exists === true)
								 return $double_filename;
					 }
			} // is_virtual

			return null;
	}

  private function legacyConvertType($string_type)
  {
    switch($string_type)
    {
        case 'lossy':
          $type = self::COMPRESSION_LOSSY;
        break;
        case 'lossless':
           $type = self::COMPRESSION_LOSSLESS;
        break;
        case 'glossy':
           $type = self::COMPRESSION_GLOSSY;
        break;
        default:
            $type = -1; //unknown state.
        break;
    }
    return $type;
  }

  /** Old Status can be anything*/
  private function legacyConvertStatus($data, $metadata)
  {

    $waiting = isset($data['WaitingProcessing']) ? true : false;
    $error = isset($data['ErrCode']) ? $data['ErrCode'] : -500;

    if (isset($metadata['ShortPixelImprovement']) &&
        is_numeric($metadata["ShortPixelImprovement"]) &&
        is_numeric($metadata["ShortPixelImprovement"]) > 0)
    {
      $status = self::FILE_STATUS_SUCCESS;
    }
    elseif($waiting)
    {
       $status = self::FILE_STATUS_PENDING;
    }
		elseif($error == 'backup-fail' || $error == 'write-fail' )
		{
			$status = self::FILE_STATUS_ERROR;
		}
    elseif ($error < 0)
    {
      $status = $error;
    }


    return $status;
  }

  public function __debugInfo() {
      return array(
        'id' => $this->id,
        'image_meta' => $this->image_meta,
        'thumbnails' => $this->thumbnails,
        'retinas' => $this->retinas,
        'original_file' => $this->original_file,
        'is_scaled' => $this->is_scaled,
      );

  }

  /** Adds Unlisted Image to the Media Library Item
  * This function is called in IsProcessable
  */
  protected function addUnlisted()
  {
       // Setting must be active.
       /*if (! \wpSPIO()->settings()->optimizeUnlisted )
         return; */

			$searchUnlisted = \wpSPIO()->settings()->optimizeUnlisted;

      // Don't check this more than once per run-time.
      if ( in_array($this->get('id'), self::$unlistedChecked ))
      {
          return;
      }

			if (defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES'))
			{
					$suffixes = explode(',', SHORTPIXEL_CUSTOM_THUMB_SUFFIXES);
			}
			else
				 $suffixes = array();

      if( defined('SHORTPIXEL_CUSTOM_THUMB_INFIXES') ){
	       $infixes = explode(',', SHORTPIXEL_CUSTOM_THUMB_INFIXES);
			}
			else
			{
				 $infixes = array();
			}

			$searchSuffixes = array_unique(apply_filters('shortpixel/image/unlisted_suffixes', $suffixes));
			$searchInfixes =  array_unique(apply_filters('shortpixel/image/unlisted_infixes', $infixes));

      // addUnlisted is called by IsProcessable, file might not exist.
      // If virtual, we can't read dir, don't do it.
      if (! $this->exists() || $this->is_virtual())
      {
				 self::$unlistedChecked[] = $this->get('id');
         return;
      }

			// if all have nothing to do, do nothing.
			if ($searchUnlisted == false && count($searchSuffixes) == 0 && count($searchInfixes) == 0)
			{
				 self::$unlistedChecked[] = $this->get('id');
				 return;
			}

        $currentFiles = array($this->getFileName());
        foreach($this->thumbnails as $thumbObj)
          $currentFiles[] = $thumbObj->getFileName();

        if ($this->isScaled())
           $currentFiles[] = $this->getOriginalFile()->getFileName();

				$processFiles = array();
				$unlisted = array();

				$processFiles[] = $this;
				if ($this->isScaled())
					$processFiles[] = $this->getOriginalFile();

  			$all_files = scandir($this->getFileDir(),  SCANDIR_SORT_NONE);
				$all_files = array_diff($all_files, $currentFiles);


				foreach($processFiles as $mediaItem)
				{

	        $base = $mediaItem->getFileBase();
	        $ext = $mediaItem->getExtension();
	        $path = (string) $mediaItem->getFileDir();

					if ($searchUnlisted)
					{
	        	$pattern = '/^' . preg_quote($base, '/') . '-\d+x\d+\.'. $ext .'/';
	        	$thumbs = array();
	        	$result_files = array_values(preg_grep($pattern, $all_files));
					}
					else
					{
						$result_files = array();
					}

					$unlisted = array_merge($unlisted, $result_files);

	        if( count($searchSuffixes) > 0){
	           // $suffixes = explode(',', SHORTPIXEL_CUSTOM_THUMB_SUFFIXES);
	            if (is_array($searchSuffixes))
	                {
	                  foreach ($searchSuffixes as $suffix){

	                      $pattern = '/^' . preg_quote($base, '/') . '-\d+x\d+'. $suffix . '\.'. $ext .'/';
	                      $thumbs = array_values(preg_grep($pattern, $all_files));

	                      if (count($thumbs) > 0)
	                        $unlisted = array_merge($unlisted, $thumbs);
	                  }
	                }
	            }
	            if( count($searchInfixes) > 0 ){
	               // $infixes = explode(',', SHORTPIXEL_CUSTOM_THUMB_INFIXES);
	                if (is_array($searchInfixes))
	                {
	                  foreach ($searchInfixes as $infix){
	                      //$thumbsCandidates = @glob($base . $infix  . "-*." . $ext);
	                      $pattern = '/^' . preg_quote($base, '/') . $infix . '-\d+x\d+' . '\.'. $ext .'/';
	                      $thumbs = array_values(preg_grep($pattern, $all_files));
	                      if (count($thumbs) > 0)
	                        $unlisted = array_merge($unlisted, $thumbs);
	                    //  $thumbs = array_merge($thumbs, self::getFilesByPattern($dirPath, $pattern));

	                      /*foreach($thumbsCandidates as $th) {
	                          if(preg_match($pattern, $th)) {
	                              $thumbs[]= $th;
	                          }
	                      } */
	                  }
	                }
	            }

			}  // processFiles loop

      // Quality check on the thumbs. Must exist,  must be same extension.
      $added = false;
      foreach($unlisted as $unName)
      {
				  if (isset($this->thumbnails[$unName]))
					{
						continue; // don't re-add if not needed.
					}
          $thumbObj = $this->getThumbnailModel($path . $unName, $unName);
          if ($thumbObj->getExtension() == 'webp' || $thumbObj->getExtension() == 'avif') // ignore webp/avif files.
          {
            continue;
          }
          elseif ($thumbObj->is_readable()) // exclude webps
          {
            $thumbObj->setName($unName);
            $thumbObj->setMeta('originalWidth', $thumbObj->get('width'));
            $thumbObj->setMeta('originalHeight', $thumbObj->get('height'));
            $thumbObj->setMeta('file', $thumbObj->getFileName() );
            $this->thumbnails[$unName] = $thumbObj;
            $added = true;
          }
          else
          {
            Log::addWarn("Unlisted Image $unName is not readable (permission error?)");
          }
      }

      //if ($added)
       // $this->saveMeta(); // Save it when we are adding images.

			self::$unlistedChecked[] = $this->get('id');
  }

} // class
