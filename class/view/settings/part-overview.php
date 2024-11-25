<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use \ShortPixel\Helper\UiHelper as UiHelper;


$total_circle = 289.027;
$total =round($view->averageCompression);

if( $total  >0 ) {
		$total_circle = round($total_circle-($total_circle * $total /100));
}


$dashboard = $view->dashboard;
$mainblock = $dashboard->mainblock;
$bulkblock = $dashboard->bulkblock;
?>

<section id="tab-overview" class="<?php echo ($this->display_part == 'overview') ? 'active setting-tab' :'setting-tab'; ?>" data-part="overview" >

  <div class='wrapper top-row step-highlight-1'>
     <div class='panel first-panel'>

       <div class="first-line <?php echo $mainblock->icon ?>">
         <i class='shortpixel-icon mainblock-status '></i>

					<div class='status-ok'>
						<h4><?php  _e('Everything running smoothly.', 'shortpixel-image-optimiser'); ?></h4>
						<p><?php  _e('Stay calm and carry on ', 'shortpixel-image-optimiser'); ?></p>
					</div>
					<div class='status-warning'>
						<h4><?php  _e('There are a few warnings you could fix', 'shortpixel-image-optimiser'); ?></h4>
						<p><?php  _e('Check the warnings below. Don\'t worry, there is a simple solution for each one.', 'shortpixel-image-optimiser'); ?></p>
					</div>

						<?php //if (true === $mainblock->cocktail) : ?>
               <i class='shortpixel-illustration cocktail'></i>
					 <?php //endif; ?>

        </div>
         <hr>
         <div class="second-line">
        <?php if (property_exists($mainblock, 'optimized')): ?>
         <div class="optimized"><?php echo $mainblock->optimized ?></div>
             <i class='shortpixel-icon file'></i>
             <div class="optimized-message"><?php esc_html_e('Optimized images and thumbnails from the Media Library and Custom Media', 'shortpixel-image-optimiser'); ?></div>
        <?php endif; ?>
         </div>



     </div>

     <div class='panel second-panel'>
           <h4><?php esc_html_e('Average Optimization','shortpixel-image-optimiser'); ?></h4>
      <?php if ( $view->averageCompression <= 0 ):
      ?>

      <p class='small'><?php _e('The average optimization is calculated based on the last 1000 optimized images. Please optimize some images to see the statistics here.', 'shortpixel-image-optimiser'); ?></p>

      <?php else: ?>

           <svg class="opt-circle-average" viewBox="-10 0 150 140">
                         <path class="trail" d="
                             M 50,50
                             m 0,-46
                             a 46,46 0 1 1 0,92
                             a 46,46 0 1 1 0,-92
                             " stroke-width="16" fill-opacity="0">
                         </path>
                         <path class="path" d="
                             M 50,50
                             m 0,-46
                             a 46,46 0 1 1 0,92
                             a 46,46 0 1 1 0,-92
                             " stroke-width="16" fill-opacity="0" style="stroke-dasharray: 289.027px, 289.027px; stroke-dashoffset: <?php echo $total_circle ?>px;">
                         </path>
                         <text class="text" x="50" y="50"><?php
                         echo $view->averageCompression;
                          ?> %</text>
             </svg>
       <?php endif; ?>


       <?php if ($view->averageCompression > 30): ?>
         <div class='rating'>
          <?php echo UiHelper::getIcon('res/images/icon/7stars-empty.svg'); ?>
          <a class='button button-setting' href='https://wordpress.org/support/plugin/shortpixel-image-optimiser/reviews/?filter=5' target="_blank"><?php esc_html_e('Rate us', 'shortpixel-image-optimiser'); ?></a>
        </div>
       <?php endif; ?>
     </div>
  </div>

  <div class='wrapper middle-row step-highlight-1'>
     <div class='panel first-panel dashboard-optimize'>

        <i class='shortpixel-icon box-archive'></i>
        <h4><?php _e('Optimize new Images', 'shortpixel-image-optimizer'); ?></h4>

        <span class='status-wrapper'><i class='shortpixel-icon status-icon ok'></i><span class='status-line'></span></span>

        <?php echo $this->settingLink([
           'part' => 'processing',
           'title' => __("Fix now", "shortpixel-image-optimiser"),
           'icon' => 'shortpixel-icon fix',
           'icon_position' => 'left',
           'class' => 'dashboard-button'
         ]);
         ?>

     </div>

     <div class='panel second-panel dashboard-bulk'>
       <i class='shortpixel-icon bulk'></i>
       <h4><?php _e('Bulk Actions', 'shortpixel-image-optimizer'); ?></h4>


        <span class='status-wrapper'>
          <span class='status-line'>

            <?php echo $bulkblock->message ?>
            <i class='shortpixel-icon status-icon <?php echo $bulkblock->icon ?>'></i>
          </span>
      </span>

      <?php if (true == $bulkblock->show_button): ?>

        <a class="dashboard-button" href="<?php echo $bulkblock->link ?>"><?php _e('Go to Bulk Processing', 'shortpixel-image-optimiser'); ?><i class='shortpixel-icon arrow-right'></i></a>
     <?php else : ?>
        <a class='dashboard-button not-visible'>&nbsp;</a>
     <?php endif; ?>

     </div>

     <div class='panel third-panel dashboard-webp'>

       <i class='shortpixel-icon photo'></i>
       <h4><?php _e('WebP/AVIF', 'shortpixel-image-optimizer'); ?></h4>

        <span class='status-wrapper'><i class='shortpixel-icon status-icon ok'></i><span class='status-line'></span></span>

   <?php echo $this->settingLink([
      'part' => 'webp',
      'title' => __("Fix now", "shortpixel-image-optimiser"),
      'icon' => 'shortpixel-icon fix',
      'icon_position' => 'left',
      'class' => 'dashboard-button'
    ]);
    ?>

     </div>
  </div>


    <settinglist>
        <input type="checkbox" id="toggle-content" style="display: none;">
        <closed-apikey-dropdown>
            <name>
                <?php esc_html_e('API Key & Account Information ', 'shortpixel-image-optimiser'); ?>
            </name>
            <info>
                <?php if ($view->key->is_constant_key) {
                    esc_html_e('Key defined in wp-config.php.', 'shortpixel-image-optimiser');
                } ?>
                <span class="shortpixel-key-valid" <?php echo $view->key->is_verifiedkey ? '' : 'style="display:none;"' ?>>
                <?php esc_html_e('Yay! Your API Key is Valid ', 'shortpixel-image-optimiser'); ?><i class="shortpixel-icon ok"></i>
            </span>
            </info>
            <label for="toggle-content" class="toggle-link">
                <span class="toggle-text"><?php _e('Show API Key', 'shortpixel-image-optimiser'); ?></span>
                <span class="shortpixel-icon chevron"></span>
            </label>
        </closed-apikey-dropdown>

        <hr>

        <content>
            <div class="apifield">
                <input name="apiKey" type="password" id="key" value="<?php echo esc_attr($view->key->apiKey); ?>"
                       class="regular-text" <?php echo($view->key->is_editable ? '' : 'disabled') ?>>
                <i class="shortpixel-icon eye"></i>
            </div>

            <button type="submit" id="validate" class="button button-primary" title="<?php esc_html_e('Validate the provided API key','shortpixel-image-optimiser');?>"
                     <?php echo $view->key->is_editable ? '' : 'disabled' ?>>
                <i class='shortpixel-icon save'></i>
                <span class ="save-button-text"> <?php esc_html_e('Save settings & validate', 'shortpixel-image-optimiser'); ?></span>
            </button>
        </content>
    </settinglist>

 <!-- @todo Can this be removed? -->
    <!--  <script>
          document.addEventListener('DOMContentLoaded', function () {

              const toggleText = document.querySelector('.toggle-text');
              const toggleChevron = document.querySelector('.shortpixel-icon.chevron');
              const content = document.querySelector('content');

              document.querySelector('.toggle-link').addEventListener('click', function () {
                  const isVisible = content.style.opacity === '1';
                  content.style.opacity = isVisible ? '0' : '1';
                  content.style.maxHeight = isVisible ? '0' : '220px';
                  toggleText.textContent = isVisible ? 'Show API Key' : 'Hide API Key';
                  toggleChevron.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(180deg)';
              });
          });
      </script>-->

  <?php $this->loadView('settings/part-savebuttons', false); ?>

</section>
