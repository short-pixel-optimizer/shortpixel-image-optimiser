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
      <div class='close'>X</div>
      <div class='steps'>
        <div class='step step-0 active'>
            <h4>Welcome aboard</h4>
            <p>
              Welcome to the Settings page of ShortPixel Image Optimizer! Click below to start a quick tour of the plugin features and settings." - should show up in the upper part, on the banner, with a "Next" button, as well as an X in the top-right corner, that should dismiss the wizard.
            </p>
        </div>
        <div class='step step-1' data-screen='overview'>
            on the Overview page, where the cocktail area is, with the text: "This is the Overview page, where you will see from a quick look the status of your site's media files. Besides statistics you will also have actionabile points to improve the performance of your website
        </div>
        <div class='step step-2' data-screen='optimisation'>
            switch to the "Image Optimization" tab and display another bubble with "This is where you can choose what type of files to optimize, as well as conversions and resize/Smart Cropping settings"
        </div>
        <div class='step step-3' data-screen='webp'>
            switch to the "WebP/AVIF & CDN" tab and display the folllowing text: "On this page you can choose the type of next-generation images to create, as well as to choose a delivery method, either directly from your website, or frum our CDN
        </div>
        <div class='step step-4'>
            switch to the upper right part and display a bubble with the text "This is where you will see most of the notifications from now on. You can also toggle between a simple and advanced mode of the settings, which will just hide or show some of the more advanced settings
        </div>
        <div class='step step-5'>
            This is where you can get access to our knowledge base as well as to our 24/7 support team. Feel free to suggest new features or leave a review, it will help us improve our products!
        </div>
      </div>

      <div class='navigation'>
          <span class='step_count'>0</span>
          <button type='button' class='previous button-setting hide'>Previous</button>
          <button type='button' class='next button-setting'>
                Start Tour
          </button>
      </div>
   </div>




</div>
