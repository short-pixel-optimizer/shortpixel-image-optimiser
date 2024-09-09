<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


?>


<section id="tab-processing" class="<?php echo ($this->display_part == 'processing') ? 'active setting-tab' :'setting-tab'; ?>" data-part="processing" >

<settinglist>

  <h2><?php esc_html_e('Processing','shortpixel-image-optimiser');?></h2>

  <!-- Optimize Media On Upload -->
  <setting class='switch'>
    <content>

       <switch>
           <label>
             <input type="checkbox" class="switch" name="autoMediaLibrary" id='autoMediaLibrary' value="1" <?php checked( $view->data->autoMediaLibrary, "1" );?>>
             <div class="the_switch">&nbsp; </div>
        <?php esc_html_e('Optimize media on upload','shortpixel-image-optimiser');?>
         </label>
      </switch>
      <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/521-settings-optimize-media-on-upload"></i>
      <name>

        <?php esc_html_e('Automatically optimize images after they are uploaded (recommended).','shortpixel-image-optimiser');?>

      </name>
    </content>
  </setting>
  <!-- // Optimize -->

  <!-- Background mode -->
  <setting class='switch'>

    <content>

     <switch>
         <label>
           <input type="checkbox" class="switch" name="doBackgroundProcess" id='doBackgroundProcess' value="1" <?php checked( $view->data->doBackgroundProcess, "1" );?> data-toggle="background_warning">
           <div class="the_switch">&nbsp; </div>
           <?php esc_html_e('Background mode','shortpixel-image-optimiser');?>
           <span class='new'><?php _e('New!', 'shortpixel-image-optimiser'); ?></span>

       </label>
    </switch>
     <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/584-background-processing-using-cron-jobs-in-shortpixel-image-optimizer"></i>

     <name>
            <?php esc_html_e('Utilize this feature to optimize images without the need to keep a browser window open, using cron jobs.','shortpixel-image-optimiser');?>
     </name>

    </content>
    <warning  id="background_warning">
        <message>
        <?php _e('I understand that background optimization may pause if there are no visitors on the website.', 'shortpixel-image-optimiser'); ?>
      </message>
    </warning>
  </setting>

  <!-- Backup -->
    <setting class='switch'>
      <content>
        <switch>
          <label>
            <input type="checkbox" class="switch" name="backupImages" value="1" <?php checked($view->data->backupImages, '1');?>>
            <div class="the_switch">&nbsp; </div>
            <?php esc_html_e('Backup Originals','shortpixel-image-optimiser');?>

          </label>
        </switch>
        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/515-settings-image-backup"></i>
        <name>
          <?php esc_html_e('Create a backup of the original images, saved on your server in /wp-content/uploads/ShortpixelBackups/.','shortpixel-image-optimiser');?>
        </name>


        <info>
          <?php esc_html_e('You can remove the backup folder at any moment but it is best to keep a local/cloud copy, in case you want to restore the optimized files to originals or re-optimize the images using a different compression type.','shortpixel-image-optimiser');?>
        </info>
      </content>
      <warning id="backup-warning">
        <message>
          <?php esc_html_e('Make sure you have a backup in place. When optimizing, ShortPixel will overwrite your images without recovery, which may result in lost images.', 'shortpixel-image-optimiser') ?>
        </message>
      </warning>
    </setting>
  <!-- // Backup -->

  <!-- Custom Media Folders -->
  <setting class='switch'>
    <content>
      <switch>
        <label>
          <input type="checkbox" class="switch" name="showCustomMedia" value="1" <?php checked( $view->data->showCustomMedia, "1" );?>>
          <div class="the_switch">&nbsp; </div>
        <?php esc_html_e('Custom Media folders','shortpixel-image-optimiser');?>
        </label>
      </switch>
      <name>
        <?php esc_html_e('Show Custom Media menu item','shortpixel-image-optimiser');?>

      </name>

    </content>
  </setting>
  <!-- // Custom media Folders -->

</settinglist>

<?php $this->loadView('settings/part-savebuttons', false); ?>


</section>
