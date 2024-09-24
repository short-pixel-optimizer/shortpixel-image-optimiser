<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

$total_circle = 289.027;
$total =round($view->averageCompression);

if( $total  >0 ) {
		$total_circle = round($total_circle-($total_circle * $total /100));
}

?>

<section id="tab-overview" class="<?php echo ($this->display_part == 'overview') ? 'active setting-tab' :'setting-tab'; ?>" data-part="overview" >

  <div class='wrapper top-row'>
     <div class='panel first-panel'>
       <i class='shortpixel-icon ok'></i>
       <span>
         <h4>Everything running smoothly.</h4>
         <p>Stay calm and carry on </p>
       </span>
        <i class='shortpixel-illustration cocktail'></i>
     </div>
     <div class='panel second-panel'>
       <div class='average-optimization '>
           <h4><?php esc_html_e('Average Optimization','shortpixel-image-optimiser'); ?></h4>
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

       </div>
     </div>
  </div>

  <div class='wrapper middle-row'>
     <div class='panel first-panel dashboard-optimize'>

        <i class='shortpixel-icon box-archive'></i>
        <h4><?php _e('Optimize new Images', 'shortpixel-image-optimizer'); ?></h4>

        <span class='status-wrapper'><i class='shortpixel-icon status-icon ok'></i><span class='status-line'></span></span>

        <button>Take Action <i class='shortpixel-icon arrow-right'></i></button>

     </div>
     <div class='panel second-panel dashboard-bulk'>
       <i class='shortpixel-icon switch'></i>
       <h4><?php _e('Bulk Actions', 'shortpixel-image-optimizer'); ?></h4>

        <span class='status-wrapper'><i class='shortpixel-icon status-icon ok'></i><span class='status-line'></span></span>

       <button>Take Action <i class='shortpixel-icon arrow-right'></i></button>
     </div>

     <div class='panel third-panel dashboard-webp'>

       <i class='shortpixel-icon photo'></i>
       <h4><?php _e('Webp/Avif', 'shortpixel-image-optimizer'); ?></h4>

        <span class='status-wrapper'><i class='shortpixel-icon status-icon ok'></i><span class='status-line'></span></span>

       <button>Take Action <i class='shortpixel-icon arrow-right'></i></button>
     </div>
  </div>

  <settinglist>
    <setting>
      <name>
        <?php esc_html_e('API Key:','shortpixel-image-optimiser'); ?>
      </name>
      <content>

        <input name="apiKey" type="text" id="key" value="<?php echo esc_attr( $view->key->apiKey );?>"
           class="regular-text" <?php echo($view->key->is_editable ? "" : 'disabled') ?> 'onkeyup="ShortPixel.apiKeyChanged()"'>
         <button type="button" id="validate" class="button button-primary" title="<?php esc_html_e('Validate the provided API key','shortpixel-image-optimiser');?>"
            onclick="ShortPixel.validateKey(this)" <?php echo $view->key->is_editable ? "" : "disabled"?> >
            <i class='shortpixel-icon save'></i>
            <?php esc_html_e('Save settings & validate','shortpixel-image-optimiser');?>
        </button>

      <info>
          <?php if ($view->key->is_constant_key)
          {
              esc_html_e('Key defined in wp-config.php.','shortpixel-image-optimiser');
          }
          ?>
          <span class="shortpixel-key-valid" <?php echo $view->key->is_verifiedkey ? '' : 'style="display:none;"' ?>>
              <i class="shortpixel-icon ok"></i><?php esc_html_e('Your API key is valid.','shortpixel-image-optimiser');?>
          </span>
      </info>
      </content>
    </setting>
  </settinglist>

  <?php $this->loadView('settings/part-savebuttons', false); ?>

</section>
