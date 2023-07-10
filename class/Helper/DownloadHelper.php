<?php
namespace ShortPixel\Helper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ResponseController as ResponseController;


class DownloadHelper
{
		  private static $instance;

			public function __construct()
			{
					$this->checkEnv();
			}

			public static function getInstance()
			{
				 if (is_null(self::$instance))
				 {
					  self::$instance = new DownloadHelper();
				 }

				 return self::$instance;
			}

			protected function checkEnv()
			{
				if ( ! function_exists( 'download_url' ) ) {
						require_once ABSPATH . 'wp-admin/includes/file.php';
				}
			}

			public function downloadFile($url, $args = array())
			{
					$defaults = array(
						'expectedSize' => null,
            'destinationPath' => null,

					);
					$args = wp_parse_args($args, $defaults);

					Log::addDebug('Downloading file :' . $url, $args);

					$fileURL = $this->setPreferredProtocol(urldecode($url));

					$downloadTimeout = max(ini_get('max_execution_time') - 10, 15);
					$tempFile = \download_url($fileURL, $downloadTimeout);

		      Log::addInfo(' Download ' . $fileURL . ' to : '. json_encode($tempFile) . '  (timeout: )' . $downloadTimeout);

					if(is_wp_error( $tempFile ))
		      { //try to switch the default protocol
               Log::addWarning('Download_URL failed, recheck protocol and try again');
		          $fileURL = $this->setPreferredProtocol(urldecode($fileURL), true); //force recheck of the protocol
		          $tempFile = \download_url($fileURL, $downloadTimeout);
		      }

					if (is_wp_error($tempFile))
					{
            Log::addWarning('Second Attempt failed, trying remote get', $tempFile);
						//get_temp_dir
						$tmpfname = tempnam(get_temp_dir(), 'spiotmp');

						$args_for_get = array(
							'stream' => true,
							'filename' => $tmpfname,
							'timeout' => $downloadTimeout,
						);

						$tempFile = wp_remote_get( $url, $args_for_get );
					}

					if (is_wp_error($tempFile))
					{
						Log::addError('Failed to download File', $tempFile);
						ResponseController::addData('is_error', true);
						Responsecontroller::addData('message', $tempFile->get_error_message());
						return false;
					}

					$fs = \wpSPIO()->filesystem();
					$file = $fs->getFile($tempFile);

          if (! is_null($args['destinationPath']))
          {
             $result = $this->moveDownload($file, $args['destinationPath']);
             if (false === $result)
             {
               Log::addError('Failed to move Download', $args);
               ResponseController::addData('is_error', true);
               Responsecontroller::addData('message', __('Failed to move download to destination!', 'shortpixel-image-optimiser'));
               return false;
             }
             else {
               $file = $result;
             }
          }

					return $file;
			}

      protected function moveDownload($fileObj, $destinationPath)
      {
          $fs = \wpSPIO()->filesystem();

          $destinationFile = $fs->getFile($destinationPath);
          // If file is non-existing, check directory and write-permissions.
          if (false == $destinationFile->exists())
          {
            $dirObj =  $destinationFile->getFileDir();
            $dirObj->check(true);
          }

          $result = $fileObj->copy($destinationFile);

          if ($result === false)
            return false;

          return $destinationFile;

      }

			private function setPreferredProtocol($url, $reset = false) {
		      //switch protocol based on the formerly detected working protocol
		      $settings = \wpSPIO()->settings();

		      if($settings->downloadProto == '' || $reset) {
		          //make a test to see if the http is working
		          $testURL = 'http://' . SHORTPIXEL_API . '/img/connection-test-image.png';
		          $result = download_url($testURL, 10);
		          $settings->downloadProto = is_wp_error( $result ) ? 'https' : 'http';
		      }
		      return $settings->downloadProto == 'http' ?
		              str_replace('https://', 'http://', $url) :
		              str_replace('http://', 'https://', $url);
		  }
}
