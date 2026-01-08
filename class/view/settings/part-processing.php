<?php
namespace ShortPixel;

use ShortPixel\Helper\UiHelper;

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
             'data' => ['data-dashboard="' . __('New images are not optimized', 'shortpixel-image-optimiser') . '"'],
            ]);
      ?>

      <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/settings-optimize-media-on-upload/?target=iframe"></i>
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
             'data' => ['data-toggle="background_warning"', 'data-dashboard="' . __('Background mode is recommended', 'shortpixel-image-optimser') . '"'],
            ]);
      ?>

     <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/background-processing-using-cron-jobs-in-shortpixel-image-optimizer/?target=iframe"></i>

     <name>
            <?php esc_html_e('Utilize this feature to optimize images without the need to keep a browser window open, using cron jobs.','shortpixel-image-optimiser');?>
     </name>

    </content>
    <warning class="background_warning">
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
               'data' => ['data-dashboard="' . __('Backups are strongly recommended!', 'shortpixel-image-optimiser') . '"'],
              ]);
        ?>

        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/settings-image-backup/?target=iframe"></i>
        <name>
          <?php esc_html_e('Create a backup of the original images, saved on your server in /wp-content/uploads/ShortpixelBackups/.','shortpixel-image-optimiser');?>
        </name>

        <info>
          <?php printf(esc_html__('You can delete the backup folder at any time, but it is best to %skeep a local or cloud copy.%s This way, you can easily restore the optimized files to their originals or re-optimize the images with a different compression type if needed.','shortpixel-image-optimiser'),
             '<a href="https://shortpixel.com/knowledge-base/article/where-is-the-backup-folder-located/" target="_blank">','</a>'
             );
         ?>
        </info>
      </content>
      <warning id="backup-warning">
        <message>
          <?php esc_html_e('Make sure you have a backup in place. When optimizing, ShortPixel will overwrite your images without recovery, which may result in lost images.', 'shortpixel-image-optimiser') ?>
        </message>
      </warning>
    </setting>

    <setting class='switch'> 
          <content>
          <?php $this->printSwitchButton(
            ['name' => 'autoRemoveBackups',
             'checked' => $view->data->autoRemoveBackups,
             'label' => esc_html__('Automatically remove backups','shortpixel-image-optimiser'),
             'data' => ['data-toggle="autoremovebackups"'],
            ]);
      ?>
          </content>
          <warning id="backup-autoremove-warning">
            <?php esc_html_e('This will free up disk space. Might lead to dataloss, make sure the original images are available elsewhere.', 'shortpixel-image-optimiser') ?>
          </warning>

      <content class='autoremovebackups toggleTarget'>
        <name>
          <?php printf(esc_html__('Period of removal', 'shortpixel-image-optimiser')); ?>
        </name>
        <?php
          $removeperiods = [
            'month'  =>  __('Month', 'shortpixel-image-optimiser'), 
            '3month' => __('3 Months', 'shortpixel-image-optimiser'),
            '6month' => __('6 Months', 'shortpixel-image-optimiser'), 
            '1year' =>  __('1 year', 'shortpixel-image-optimiser'),
            '2year' => __('2 year', 'shortpixel-image-optimiser'),
            '5year' => __('3 year', 'shortpixel-image-optimiser'), 
          ]; 

        ?>

        <select name="autoRemoveBackupsPeriod">
          <?php foreach ($removeperiods as $value => $name) {
            $checked = ($value == $view->data->autoRemoveBackupsPeriod) ? 'selected' : '';
            printf('<option value="%s" %s>%s</option>', $value, $checked,  $name);
          }
          ?>

        </select>
      </content>

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
        <?php esc_html_e('Display the Media > Custom Media menu, which allows optimization of images not listed in the Media Library.','shortpixel-image-optimiser');?>

      </name>

    </content>
  </setting>
  <!-- // Custom media Folders -->

</settinglist>

<?php $this->loadView('settings/part-savebuttons', false); ?>


</section>
