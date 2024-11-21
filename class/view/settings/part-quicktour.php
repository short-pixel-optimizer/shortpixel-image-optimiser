<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>


<div class='quick-tour'>
  <div class='ufo'>
  <?php
  echo UiHelper::getIcon('res/images/illustration/quicktour-ufo.svg');
   ?>
 </div>

   <div class='content-wrapper'>
      <div class='close'><i class='shortpixel-icon close'></i></div>
      <div class='steps'>
        <div class='step step-0 active' data-screen='overview'>
						<h4><?php _e('Welcome aboard', 'shortpixel-image-optimiser') ?></h4>
            <p>
							<?php _e('Welcome to the Settings page of ShortPixel Image Optimizer! Click below to start a quick tour of the plugin features and settings.', 'shortpixel-image-optimiser'); ?>
            </p>
        </div>
        <div class='step step-1' data-screen='overview'>
						<p><?php _e('This is the Overview page, where you will see from a quick look the status of your site\'s media files. Besides statistics you will also have actionable points to improve the performance of your website','shortpixel-image-optimiser'); ?>
          </p>
        </div>
        <div class='step step-2' data-screen='optimisation'>
						<p><?php _e('This is where you can choose what type of files to optimize, as well as conversions and resize/Smart Cropping settings', 'shortpixel-image-optimiser'); ?></p>
        </div>
        <div class='step step-3' data-screen='webp'>
						<p><?php _e('On this page you can choose the type of next-generation images to create, as well as to choose a delivery method, either directly from your website, or frum our CDN', 'shortpixel-image-optimiser'); ?></p>
        </div>
      <!--  <div class='step step-4'>
            switch to the upper right part and display a bubble with the text "This is where you will see most of the notifications from now on. You can also toggle between a simple and advanced mode of the settings, which will just hide or show some of the more advanced settings
        </div> -->
        <div class='step step-4' data-screen='help'>
						<p><?php _e('This is where you can get access to our knowledge base as well as to our 24/7 support team. Feel free to suggest new features or leave a review, it will help us improve our products!', 'shortpixel-image-optimiser'); ?>
            </p>
        </div>
      </div>

      <div class='navigation'>
          <span class='step_count'>0</span>
        <!--  <button type='button' class='previous button-setting hide'>Previous</button> -->
          <?php for($i = 1; $i < 5; $i++)
          {
              printf('<a class="stepdot hide" data-step="%d">&nbsp;</a>', $i);
          }
          ?>

          <button type='button' class='next show-start button-setting'>
								<span class='start'><i class='shortpixel-icon rocket'></i><?php _e('Start Tour', 'shortpixel-image-optimiser'); ?></span>
								<span class='next'><?php _e('Next', 'shortpixel-image-optimiser'); ?> <i class='shortpixel-icon arrow-right'></i></span>
          </button>
          <button type="button" class='close hide'>
						<i class='shortpixel-icon robo'></i> <?php _e('Start images optimization', 'shortpixel-image-optimiser'); ?>
          </button>
      </div>
   </div>

</div>
