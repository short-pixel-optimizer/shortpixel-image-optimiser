<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


/** Class for handling changes done by WP in the Image Edit section. **/
class ImageEditorController
{

	protected static $instance;


	public function __construct()
	{

	}

	public static function getInstance()
	{
		if (is_null(self::$instance))
				self::$instance = new ImageEditorController();

		return self::$instance;
	}

	public static function localizeScript()
	{
		  $local = array(
					'test' => 'test',
			);

			$fs = \wpSPIO()->filesystem();

			if (isset($_REQUEST['post']))
			{
				$post_id  = intval($_REQUEST['post']);
				$mediaImage = $fs->getImage($post_id, 'media');
				if ($mediaImage)
				{
						$local['is_restorable'] = ($mediaImage->isRestorable()) ? 'true' : 'false';
						$local['post_id'] = $post_id;

						$local['optimized_text'] = sprintf(__('This image is optimized. It\'s strongly %s recommended %s to restore the image before editing it.  After saving the image all optimization data will be lost. When the image is not restored Shortpixel will re-optimize the result which could result in quality loss', 'shortpixel-image-optimiser'), '<strong>', '</strong>');
						$local['restore_link']  = 'javascript:window.ShortPixelProcessor.screen.RestoreItem(' . $post_id  . ')';
						$local['restore_link_text'] = __('Restore backup now', 'shortpixel-image-optimiser');

				}

			}


			return $local;
	}


	/*
	* If SPIO has a backup of this image, load the backup file for editing instead of the (optimized) image
	*/
	public function getImageForEditor( $filepath, $attachment_id, $size)
	{

		$fs = \wpSPIO()->filesystem();
		$mediaImage = $fs->getImage($attachment_id, 'media');

		// Not an image, let's not get into this.
		if (false === $mediaImage)
			return $filepath;

		$imagepath = false;
		if ($size == 'full')
		{
				Log::addTemp('Size full');
				$optimized_and_backup = ($mediaImage->isOptimized() && $mediaImage->hasBackup());
				if ( true === $optimized_and_backup)
					$imagepath = $mediaImage->getBackupFile()->getFullPath();
		}
		elseif (false !== $mediaImage->getThumbNail($size)) {
			 	$thumbObj = $mediaImage->getThumbNail($size);
				$optimized_and_backup = ($thumbObj->isOptimized() && $thumbObj->hasBackup());

				if (true === $optimized_and_backup)
					$imagepath = $thumbObj->getBackupFile()->getFullPath();
		}

Log::addTemp('imagePath', $imagepath);
		if (true === $optimized_and_backup)
		{
			Log::addTemp('Returning ImagePath');
			 return $imagepath;
		}

		 return $filepath;
	}

	public function saveImageFile( $null, $filename, $image, $mime_type, $post_id		)
	{
		Log::addTemp('Save Image File');
			// Check image and if needed,restore backups.
			$fs = \wpSPIO()->filesystem();
			$mediaImage = $fs->getImage($post_id, 'media');

			if (is_object($mediaImage))
			{
				if ($mediaImage->isRestorable())
				{
					 $mediaImage->restore();
				}
				$mediaImage->onDelete();
			}
			return $null;
	}

//'updated_postmeta', $meta_id, $object_id, $meta_key, $meta_value
	// Detect post meta update, because this is the only we have to detect a restore to oringal.
	public function checkUpdateMeta( $meta_id, $object_id, $meta_key, $meta_value)
	{
		 if ($meta_key !== '_wp_attachment_backup_sizes')
		 {
			 return;
		 }

//		 Log::addTemp('meta is _wp_attachment_backup_sizes');

		 $file = get_attached_file($object_id);
		 $parts = pathinfo( $file );
//		 Log::addTemp('File Attach ' . $object_id . ' -- ' . $file . ' ' . basename($file));

		 // make sure that the file is not of 'edited' kind still ( original ) - preg match returns false or 0 if not there
		 $result = preg_match( '/-e[0-9]{13}\./', basename($file), $matches);
//		 Log::addTemp('Matches' . $result , $matches);
		 if ( false === $result || $result === 0  ) {
			 $fs = \wpSPIO()->filesystem();
			 $mediaImage = $fs->getImage($object_id, 'media');


			// Log::addTemp('meta Value', $meta_value);
			 Log::addTemp('Result from Get Attached' . $file);
			 Log::addTemp('Check if can be restored (edited image) -- ' . $mediaImage->getFullPath());

			 // Will remove most of data, and backups if lucky.
			 if (is_object($mediaImage))
			 {
				 Log::addTemp('MediaImage Objet, Ondelete doin');
				  $mediaImage->onDelete();
			 }

			 // Just nuke any backup in sight.
			 if (is_array($meta_value))
			 {
				   foreach($meta_value as $name => $data)
					 {
						  if (! isset($data['file']))
							{
								Log::addTemp('not set file', $data);
								 continue;
							}
							$fileObj = $fs->getFile(path_join($parts['dirname'], $data['file']));
							if (is_object($fileObj))
							{
								 if ($fileObj->hasBackup())
								 {
									  $fileObj->getBackupFile()->delete();
								 }
							}
							else {
							}
					 }
			 }
			 else {
			 	 	Log::addtEMP('Not arrey, this meta calue');
			 }

		 }

	}



	public function addWarning()
	{
			$open = isset( $_GET['image-editor'] );
		if ($open)
		 echo 'This is blah!';
	}


} //class
