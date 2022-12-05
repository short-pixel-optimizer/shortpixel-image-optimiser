<?php
namespace ShortPixel\Tests;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


class SPIO_UnitTestCase extends \WP_UnitTestCase_Base
{
		protected static $assets = array(); // setup assets on the tests.  Loader.
		protected static $attachmentAssets = array();

		protected static $attachments = array(); // the loaded attachments.

		public static function wpSetUpBeforeClass($factory)
		{
				Log::getInstance()->setLogPath('/tmp/shortpixel_log');

				$assetDir = __DIR__ . '/Assets/';
				foreach(static::$attachmentAssets as $fileName)
				{
					$post = $factory->post->create_and_get();
					$attachment_id = $factory->attachment->create_upload_object( $assetDir . $fileName);

					static::$attachments[$fileName]  = $attachment_id;
				}

				// @todo See if this needs some doing.
				/*foreach (self::$assets as $fileName)
				{

				} */
		}

		public static function wpTearDownAfterClass()
	  {
	    // delete png
			$path = false;
			foreach (static::$attachments as $fileName => $attach_id)
			{
				 if ($path === false) // ugly solution
				 {
					 	$path = dirname(get_attached_file($attach_id));
				 }
				 wp_delete_attachment($attach_id, true);
			}

	    // wipe the dir.
	    foreach (new \DirectoryIterator($path) as $fileInfo) {
	    if(!$fileInfo->isDot()) {
	        unlink($fileInfo->getPathname());
	    		}
	    }

			$backupDir = \wpSPIO()->filesystem()->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
			$backupDir->recursiveDelete();

			self::wipeDatabase();
		}

		// Remove our data after test is done, otherwise this can influence.
		public static function wipeDatabase()
		{
			  global $wpdb;

				foreach ( array(
					$wpdb->prefix . 'shortpixel_postmeta',
					$wpdb->prefix . 'shortpixel_meta',
					$wpdb->prefix . 'shortpixel_folders',
				) as $table ) {
					//phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( "DELETE FROM {$table}" );
				}
		}

		public function settings()
		{
			return  \wpSPIO()->settings();
		}

		public function filesystem()
		{
			return  \wpSPIO()->filesystem();
		}

	  public function getAsset($filename)
		{

		}

		public function getAttachmentAsset($fileName)
		{
			  if ( isset (static::$attachments[$fileName]))
				{
					 return static::$attachments[$fileName];
				}
		}

	  public function getMediaImage($fileName)
		{
			 	$attach_id = $this->getAttachmentAsset($fileName);
				return $this->filesystem()->getMediaImage($attach_id);
		}



} // class
