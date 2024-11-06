<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Helper\UiHelper as UiHelper;
?>

<section id="tab-help" class="<?php echo ($this->display_part == 'help') ? 'active setting-tab' :'setting-tab'; ?>" data-part="help" >

  <div class='help-center panels step-highlight-4'>
      <div>
          <span class='main-icon'><?php echo UIHelper::getIcon('res/images/icon/help.svg'); ?></span>
          <h4><?php _e('Knowledge base', 'shortpixel-image-optimiser'); ?></h4>
          <p><?php esc_html_e('Most customer questions are answered in our Knowledge Base.', 'shortpixel-image-optimiser'); ?></p>

          <a href="https://shortpixel.com/knowledge-base/" target="_blank" class="button-setting"><?php //echo UIHelper::getIcon('res/images/icon/external.svg'); ?>
             <?php esc_html_e('Knowledge Base', 'shortpixel-image-optimiser'); ?>
           <?php //echo UIHelper::getIcon('res/images/icon/arrow-right.svg'); ?> </a>
      </div>
      <div>
          <span class='main-icon'><?php echo UIHelper::getIcon('res/images/icon/envelope.svg'); ?></span>
          <h4><?php _e('Contact us', 'shortpixel-image-optimiser'); ?></h4>
          <p><?php esc_html_e('Contact us with any issues, bug reports, or questions.', 'shortpixel-image-optimiser'); ?></p>

          <a href="https://shortpixel.com/contact" target="_blank" class="button-setting"><?php //echo UIHelper::getIcon('res/images/icon/external.svg'); ?>
             <?php esc_html_e('Contact Us', 'shortpixel-image-optimiser'); ?>
           <?php //echo UIHelper::getIcon('res/images/icon/arrow-right.svg'); ?> </a>
      </div>
      <div>
          <span class='main-icon'><?php echo UIHelper::getIcon('res/images/icon/lightbulb.svg'); ?></span>
          <h4><?php _e('Feature Request', 'shortpixel-image-optimiser'); ?></h4>
          <p><?php esc_html_e('Is there a feature missing? Do you have suggestions for improving ShortPixel?', 'shortpixel-image-optimiser'); ?></p>

          <a href="mailto:help@shortpixel.com?subject=SPIO Feature Request" target="_blank" class="button-setting"><?php //echo UIHelper::getIcon('res/images/icon/external.svg'); ?>
             <?php esc_html_e('Feature Request', 'shortpixel-image-optimiser'); ?>
           <?php //echo UIHelper::getIcon('res/images/icon/arrow-right.svg'); ?> </a>
      </div>

</section>
