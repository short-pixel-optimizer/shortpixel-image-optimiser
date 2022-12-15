<?php
namespace ShortPixel\Helper;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

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

					);
					$args = wp_parse_args($args, $defaults);

					Log::addDebug('Downloading file :' . $url, $args);

					$fileURL = $this->setPreferredProtocol(urldecode($url));

					$downloadTimeout = max(ini_get('max_execution_time') - 10, 15);
					$tempFile = \download_url($fileURL, $downloadTimeout);

		      Log::addInfo('Downloading ' . $fileURL . ' to : '.json_encode($tempFile));

					if(is_wp_error( $tempFile ))
		      { //try to switch the default protocol
		          $fileURL = $this->setPreferredProtocol(urldecode($optimizedUrl), true); //force recheck of the protocol
		          $tempFile = \download_url($fileURL, $downloadTimeout);
		      }

					$fs = \wpSPIO()->filesystem();
					$file = $fs->getFile($tempFile);

					return $file;
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
