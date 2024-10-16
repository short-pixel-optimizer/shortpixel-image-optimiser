<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Helper\UiHelper as UiHelper;


?>


<section id="tab-help" class="<?php echo ($this->display_part == 'help') ? 'active setting-tab' :'setting-tab'; ?>" data-part="help" >

  <div class='help-center panels'>
      <div>
          <span class='main-icon'><?php echo UIHelper::getIcon('res/images/icon/help.svg'); ?></span>
          <h4><?php _e('Knowledge base', 'shortpixel-image-optimiser'); ?></h4>
          <p><?php esc_html_e('For any questions please check our knowledge base', 'shortpixel-image-optimiser'); ?></p>

          <a href="" target="_blank" class="button-setting"><?php echo UIHelper::getIcon('res/images/icon/external.svg'); ?>
             <?php esc_html_e('Knowledge Base', 'shortpixel-image-optimiser'); ?>
           <?php echo UIHelper::getIcon('res/images/icon/arrow-right.svg'); ?> </a>
      </div>
      <div>
          <span class='main-icon'><?php echo UIHelper::getIcon('res/images/icon/envelope.svg'); ?></span>
          <h4><?php _e('Contact us', 'shortpixel-image-optimiser'); ?></h4>
          <p><?php esc_html_e('Contact us for any issue, bug, report or question', 'shortpixel-image-optimiser'); ?></p>

          <a href="" target="_blank" class="button-setting"><?php echo UIHelper::getIcon('res/images/icon/external.svg'); ?>
             <?php esc_html_e('Contact Us', 'shortpixel-image-optimiser'); ?>
           <?php echo UIHelper::getIcon('res/images/icon/arrow-right.svg'); ?> </a>
      </div>
      <div>
          <span class='main-icon'><?php echo UIHelper::getIcon('res/images/icon/lightbulb.svg'); ?></span>
          <h4><?php _e('Feature Request', 'shortpixel-image-optimiser'); ?></h4>
          <p><?php esc_html_e('Any suggestion on how we can improve the experience', 'shortpixel-image-optimiser'); ?></p>

          <a href="" target="_blank" class="button-setting"><?php echo UIHelper::getIcon('res/images/icon/external.svg'); ?>
             <?php esc_html_e('Feature Request', 'shortpixel-image-optimiser'); ?>
           <?php echo UIHelper::getIcon('res/images/icon/arrow-right.svg'); ?> </a>
      </div>

</section>
