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

      <?php $this->printSwitchButton(
            ['name' => 'autoMediaLibrary',
             'checked' => $view->data->autoMediaLibrary,
             'label' => esc_html__('Optimize media on upload','shortpixel-image-optimiser'),
             'data' => ['data-dashboard="' . __('Not automatically optimizing', 'shortpixel-image-optimiser') . '"'],
            ]);
      ?>

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
      <?php $this->printSwitchButton(
            ['name' => 'doBackgroundProcess',
             'checked' => $view->data->doBackgroundProcess,
             'label' => esc_html__('Background mode','shortpixel-image-optimiser'),
             'data' => ['data-toggle="background_warning"', 'data-dashboard="' . __('Recommended background mode', 'shortpixel-image-optimser') . '"'],
            ]);
      ?>

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

        <?php $this->printSwitchButton(
              ['name' => 'backupImages',
               'checked' => $view->data->backupImages,
               'label' => esc_html__('Backup Originals','shortpixel-image-optimiser'),
               'data' => ['data-dashboard="' . __('Strongly recommend turning on backups', 'shortpixel-image-optimiser') . '"'],
              ]);
        ?>

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
      <?php $this->printSwitchButton(
            ['name' => 'showCustomMedia',
             'checked' => $view->data->showCustomMedia,
             'label' => esc_html__('Custom Media folders','shortpixel-image-optimiser'),
            ]);
      ?>

      <name>
        <?php esc_html_e('Show Custom Media menu item','shortpixel-image-optimiser');?>

      </name>

    </content>
  </setting>
  <!-- // Custom media Folders -->

</settinglist>

<?php $this->loadView('settings/part-savebuttons', false); ?>


</section>
