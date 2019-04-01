

    <div class="wrap short-pixel-bulk-page bulk-restore-all">
        <form action='<?php echo remove_query_arg('part'); ?>' method='POST' >
        <h1><?php _e('Bulk Image Optimization by ShortPixel','shortpixel-image-optimiser');?></h1>

        <div class="sp-notice sp-notice-info sp-floating-block sp-full-width">

        <h3><?php _e( "Are you sure you want to restore from backup all the images optimized with ShortPixel?", 'shortpixel-image-optimiser' ); ?></h3>

        <p><?php _e('Please read carefully. This function will: ', 'shortpixel-image-optimiser'); ?> </p>
        <ol>
          <li><?php _e('Remove all optimized images from media library', 'shortpixel-image-optimiser'); ?></li>
          <li><?php _e('Remove all optimized images from other media', 'shortpixel-image-optimiser'); ?></li>
        </ol>

        <div class='random_check'>
          <p><?php _e('To continue and agree with the warning, please check the correct value below', 'shortpixel-image-optimiser') ?>
            <div class='random_answer'><?php echo $controller->randomAnswer(); ?></div>
          </p>

          <div class='inputs'><?php echo $controller->randomCheck();  ?></div>
        </div>

        <div class='form-controls'>
          <a class='button' href="<?php echo remove_query_arg('part') ?>"><?php _e('Back', 'shortpixel-image-optimiser'); ?></a>
          <button class='button bulk restore disabled' disabled name='bulkRestore' id='bulkRestore'><?php _e('Bulk Restore', 'shortpixel-image-optimiser'); ?></button>
        </div>

        </form>
    </div>
