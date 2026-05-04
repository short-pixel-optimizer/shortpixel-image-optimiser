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
               'label' => esc_html__('Backup original files','shortpixel-image-optimiser'),
               'data' => ['data-dashboard="' . __('Backups are strongly recommended!', 'shortpixel-image-optimiser') . '"'],
              ]);
        ?>

        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/settings-image-backup/?target=iframe"></i>
        <name>
          <?php esc_html_e('Keep a copy of your original files so you can restore them later if needed. Copies are stored in /wp-content/uploads/ShortpixelBackups/.','shortpixel-image-optimiser');?>
        </name>

        <info>
          <?php printf(esc_html__('Backups are saved on your server. For extra safety, we recommend also keeping a %local or cloud copy.%s','shortpixel-image-optimiser'),
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


    <!-- Single File Backup --> 
    <setting class='switch'> 
          <content>
          <?php $this->printSwitchButton(
            ['name' => 'singleFileBackup',
             'checked' => $view->data->singleFileBackup,
             'label' => esc_html__('Smart backup','shortpixel-image-optimiser'),
             'data' => ['data-toggle="backup-single-warning"'],
            ]);
      ?>
        <name>
          <?php esc_html_e('Back up only the main file and regenerate thumbnails when restoring.','shortpixel-image-optimiser');?>
        </name>

        <info>
          <?php esc_html_e('Use this only if you want to save disk space. In some cases, restored thumbnails may not look exactly like the originals.','shortpixel-image-optimiser');?>
        </info>
          </content>
    </setting>

    <!--- AUTO REMOVE BACKUP --> 
    <setting class='switch'> 
          <content>
          <?php $this->printSwitchButton(
            ['name' => 'autoRemoveBackups',
             'checked' => $view->data->autoRemoveBackups,
             'label' => esc_html__('Automatic backup cleanup','shortpixel-image-optimiser'),
             'data' => ['data-toggle="autoremovebackups"'],
            ]);
      ?>
        <name>
          <?php esc_html_e('Automatically remove old backups after the selected time to save disk space.','shortpixel-image-optimiser');?>
        </name>
          </content>
          <warning id="backup-autoremove-warning" class='autoremovebackups toggleTarget'>
            <?php esc_html_e('Once removed, backups cannot be restored from this plugin. Make sure you have another copy if you need the originals.', 'shortpixel-image-optimiser') ?>
          </warning>

      <content class='autoremovebackups toggleTarget'>
        <name>
          <?php printf(esc_html__('Delete backups older than:', 'shortpixel-image-optimiser')); ?>
        </name>
        <?php
          $removeperiods = [
            'month'  =>  __('1 month', 'shortpixel-image-optimiser'), 
            '3month' => __('3 months', 'shortpixel-image-optimiser'),
            '6month' => __('6 months', 'shortpixel-image-optimiser'), 
            '1year' =>  __('1 year', 'shortpixel-image-optimiser'),
            '2year' => __('2 years', 'shortpixel-image-optimiser'),
            '5year' => __('5 years', 'shortpixel-image-optimiser'), 
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
