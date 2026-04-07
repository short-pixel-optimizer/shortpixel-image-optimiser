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

      protected $last_download_error; 

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

      /** Helper to download file from remote. 
       * 
       * @param string $url The remote URL to download.
       * @param array $args  if DestinationPath is included it will try to move file there, otherwise remain in /tmp. 
       * @return Object|bool File Object or false 
       */
      
			public function downloadFile($url, $args = array())
			{
					$defaults = array(
						'expectedSize' => null,
            'destinationPath' => null,
					);

					$args = wp_parse_args($args, $defaults);
          $success = false;

					Log::addDebug('Downloading file :' . $url, $args);

          $methods = array(
              "download_url" => array(array($this, 'downloadURLMethod'), $url, false),
              "download_url_force" => array(array($this, 'downloadURLMethod'), $url, true),
              "remote_get" => array(array($this, 'remoteGetMethod'), $url)
          );

          foreach($methods as $name => $data)
          {
             $function = $data[0];
             if (is_callable($function))
             {
                $result = call_user_func_array($function, array_slice($data, 1) );

                if (false !== $result)
                {
                   $tempFile = $result;
                   $success = true;
                   break;
                }
             }
          }

					if (false === $success)
					{
						Log::addError('Failed to download File', $result);
            $this->last_download_error = $tempFile->get_error_message();
						//ResponseController::addData('is_error', true);
						//Responsecontroller::addData('message', $tempFile->get_error_message());
						return false;
					}

          /*
          Log::addError('Nulling tempfile to zero for testing!'); 
          $file = fopen($tempFile, 'r+'); 
          ftruncate($file,0);
          fclose($file);
          */

					$fs = \wpSPIO()->filesystem();
					$file = $fs->getFile($tempFile);

          if ($file->getFileSize() === 0)
          {
              Log::addError('Tmp File zero bytes', $tempFile); 
              $this->last_download_error =__('Temp file zero bytes', 'shortpixel-image-optimiser'); 

              $file->delete(); // Prevent it from hanging around 
              return false; 
          }

          $allowed_exts = ['pdf'];  // other than 'image' . 

          if (false === in_array($file->getExtension(), $allowed_exts) && false === $file->isImage())
          {
            Log::addError('Download: File came back as not being an image! ', $file);
            $file->delete();
            $this->last_download_error = __('File Download failed - seems not an image.', 'shortpixel-image-optimiser'); 

            return false; 
          }

          if (! is_null($args['destinationPath']))
          {
             $result = $this->moveDownload($file, $args['destinationPath']);
             if (false === $result)
             {
               Log::addError('Failed to move Download', $args);
               $this->last_download_error = __('Failed to move download to destination!', 'shortpixel-image-optimiser'); 

               return false;
             }
             else {
               $file = $result;
             }
          }

					return $file;
			}

      public function getLastError()
      {
          return $this->last_download_error;
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

          $result = $fileObj->move($destinationFile);

          if ($result === false)
            return false;

          return $destinationFile;

      }

      /** Get a sensible timeout for how long the download should be allowed to take */
      private function getMaxDownloadTime()
      {
        $executionTime = ini_get('max_execution_time');
        if (! is_numeric($executionTime)) // edge case
        {
           $executionTime = 0;
        }
        // min here, so maximum value of downloadtimeout is 25 seconds, which should be more than enough. To prevent hanging downloads eating up server time
        $downloadTimeout = min($executionTime - 10, 25);
        if ($downloadTimeout < 10) // asume something went wrong here, or edge case up, allow minimum 10 seconds for download.
        {
            $downloadTimeout = 10; 
        }

        return $downloadTimeout; 
      }

      private function downloadURLMethod($url, $force = false)
      {

        $downloadTimeout = $this->getMaxDownloadTime(); 

        $url = $this->setPreferredProtocol(urldecode($url), $force);
        $tempFile = \download_url($url, $downloadTimeout);

        if (is_wp_error($tempFile))
        {
           Log::addError('Failed to Download File from ' . $url , $tempFile);
           Responsecontroller::addData('message', $tempFile->get_error_message());
           return false;
        }

        return $tempFile;
      }

      private function remoteGetMethod($url)
      {
            //get_temp_dir
            $tmpfname = tempnam(get_temp_dir(), 'spiotmp');

            $downloadTimeout = $this->getMaxDownloadTime(); 

            $args_for_get = array(
              'stream' => true,
              'filename' => $tmpfname,
              'timeout' => $downloadTimeout,
            );

            $response = wp_remote_get( $url, $args_for_get );

            if (wp_remote_retrieve_response_code($response) == 200 && isset($response['filename']))
            {
                $filepath = $response['filename'];
                return $filepath; // body is the full image is all went well.
            }
            else {
               Log::addError('Wp Remote Get failed', $response);
            }

            return false;
      }

			private function setPreferredProtocol($url, $reset = false) {
		      //switch protocol based on the formerly detected working protocol
		      $settings = \wpSPIO()->settings();

		      if($settings->downloadProto == '' || $reset) {
		          //make a test to see if the http is working
		          $testURL = 'http://' . SHORTPIXEL_API . '/img/connection-test-image.png';
		          $result = download_url($testURL, 10);
		          $settings->downloadProto = is_wp_error( $result ) ? 'https' : 'http';

              // remove test.
              if (false === is_wp_error($result))
              {
                @unlink($result);
              }
              
		      }
		      return $settings->downloadProto == 'http' ?
		              str_replace('https://', 'http://', $url) :
		              str_replace('http://', 'https://', $url);
		  }
}
