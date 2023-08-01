<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class Woocommerce
{
	//	public function $new_sizes = array();

		protected static $SIGNAL = false;

		public function __construct()
		{
			 add_action('plugins_loaded', array($this, 'hooks'));
		}

		public function hooks()
		{
			if (\wpSPIO()->env()->plugin_active('woocommerce'))
			{
				 add_filter('woocommerce_regenerate_images_intermediate_image_sizes', array($this, 'signalWC'));

				 add_filter('woocommerce_debug_tools', array($this, 'addWarning'));

				 // If new images are created, drop the optimize data of them . Late as possible, this is a hook often used by plugins to refine.
				 add_filter('intermediate_image_sizes_advanced', array($this, 'handleCreateImages'), 99, 3);

			}
		}

		// This hook is ran just before create new images / regenerating them. Only then signal to check for optimized thumbs et al.
		public function signalWC()
		{
				self::$SIGNAL = true;
		}

		/** Hook to run when Wordpress is about to generate new thumbnails.  Remove backup and optimize data if that happens */
		public function handleCreateImages($new_sizes, $image_meta, $attach_id)
		{
				// No signal, no run.
				if (false === self::$SIGNAL)
				{
					 return $new_sizes;
				}

				if (count($new_sizes) === 0)
				{
					 self::$SIGNAL = false;
					 return $new_sizes;
				}
				$fs = \wpSPIO()->filesystem();

				$mediaImage = $fs->getMediaImage($attach_id);
				$changes = false;
				if (is_object($mediaImage))
				{
						// Performance; This item is not in database, hence not optimized in any way.
						if (! is_null($mediaImage->getMeta('databaseID')))
						{

								foreach($new_sizes as $new_size => $data)
								{
										$thumbnailObj = $mediaImage->getThumbNail($new_size);
										if (is_object($thumbnailObj) && $thumbnailObj->isOptimized())
										{
												$thumbnailObj->onDelete();
												$changes = true;
										}
								}
						}
						else {
						}
				}

				if (true === $changes)
				{
					$mediaImage->saveMeta();
				}

				self::$SIGNAL = false;
				return $new_sizes;
		}

		public function addWarning($tools)
		{
			 if (isset($tools['regenerate_thumbnails']) && \wpSPIO()->env()->is_autoprocess)
			 {
				  $text = $tools['regenerate_thumbnails']['desc'];
					$text .= sprintf(
					'<br><br><strong class="red">%1$s</strong> %2$s',
					__( 'ShortPixel Image Optimizer Note:', 'shortpixel-image-optimiser' ),
					__( 'The ShortPixel Image Optimizer plugin is set to automatically optimize images on upload. When running the thumbnails tools, each image that is not optimized will be added to the queue. It is recommend to disable this option while running these tools', 'shortpixel-image-optimiser')
				);
				$tools['regenerate_thumbnails']['desc'] = $text;
			 }

			 return $tools;
		}


} // class

$w = new Woocommerce();
