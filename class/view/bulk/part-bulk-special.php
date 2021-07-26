<section class=' panel bulk-restore' data-panel="bulk-restore"  >
  <h3 class='heading'>
    <?php _e("Bulk Restore", 'shortpixel-image-optimiser'); ?>
  </h3>

  <h4><?php _e('Warning', 'shortpixel-image-optimiser'); ?></h4>

  <p><?php printf(__('By starting the bulk restore process, the plugin will try to restore %s all images %s to the original state. All images will become unoptimized.', 'shortpixel-image-optimiser'), '<b>', '</b>'); ?></p>

  <p><input type="checkbox" id="bulk-restore-agree" value="agree" data-action="ToggleButton" data-target="bulk-restore-button"> <?php _e('I want to restore all images. I understand this action is permanent and nonreversible', 'shortpixel-image-optimiser'); ?></p>

  <button class="button disabled" disabled id='bulk-restore-button' data-action="BulkRestoreAll"  ><?php _e('Bulk Restore All Images', 'shortpixel-image-optimiser') ?></button>


  <nav>
    <button class="button" data-action="open-panel" data-panel="dashboard"><?php _e('Back','shortpixel-image-optimiser'); ?></button>
  </nav>

</section>


<section class=' panel bulk-migrate' data-panel="bulk-migrate"  >
  <h3 class='heading'>
    <?php _e("Bulk Migrate", 'shortpixel-image-optimiser'); ?>
  </h3>

  <h4><?php _e('Warning', 'shortpixel-image-optimiser'); ?></h4>

  <p><?php printf(__('By starting the bulk migrate process, the plugin will try to migrate %s all images %s . There might be exceptions and the migration might fails. It is strongly adviced to create a backup.', 'shortpixel-image-optimiser'), '<b>', '</b>'); ?></p>

  <p><input type="checkbox" id="bulk-migrate-agree" value="agree" data-action="ToggleButton" data-target="bulk-migrate-button"> <?php _e('I want to migrate all images. I understand this action is permanent. I made a backup of my site including images and database', 'shortpixel-image-optimiser'); ?></p>

  <button class="button disabled" disabled id='bulk-migrate-button' data-action="BulkMigrateAll"  ><?php _e('Search and migrate All Images', 'shortpixel-image-optimiser') ?></button>


  <nav>
    <button class="button" data-action="open-panel" data-panel="dashboard"><?php _e('Back','shortpixel-image-optimiser'); ?></button>
  </nav>

</section>
