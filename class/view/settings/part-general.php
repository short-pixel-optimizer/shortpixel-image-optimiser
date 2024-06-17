<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

?>

<section id="tab-settings" class="<?php echo ($this->display_part == 'settings') ? 'sel-tab' :''; ?>" >
    <h2><a class='tab-link' href='javascript:void(0);' data-id="tab-settings">
      <?php esc_html_e('General','shortpixel-image-optimiser');?></a>
    </h2>

    <div class="wp-shortpixel-options wp-shortpixel-tab-content" style="visibility: hidden">

      <?php


if (true === \wpSPIO()->env()->useTrustedMode())
      {
        ?>
            <div class='compression-notice warning'>
              <p><?php
                  _e('Trusted file mode is active. This means that ShortPixel will depend on the metadata and not check the fileystem while loading the UI. Information may be incorrect and error may occur during optimization ', 'shortpixel-image-optimiser');
                  ?></p>
              <?php if (true === \ShortPixel\Pantheon::IsActive())
              {
                echo '<p>'; _e('(You are on Pantheon. This setting was automatically activated)'); echo '</p>';
              }
              ?>

            </div>
          <?php
      }
      ?>

    <!-- general settings -->
    <settinglist>

        <!-- Api Key -->
        <setting>
          <name>
            <?php esc_html_e('API Key:','shortpixel-image-optimiser'); ?>
          </name>
          <content>

						<input name="apiKey" type="text" id="key" value="<?php echo esc_attr( $view->key->apiKey );?>"
							 class="regular-text" <?php echo($view->key->is_editable ? "" : 'disabled') ?> 'onkeyup="ShortPixel.apiKeyChanged()"'>
						 <button type="button" id="validate" class="button button-primary" title="<?php esc_html_e('Validate the provided API key','shortpixel-image-optimiser');?>"
								onclick="ShortPixel.validateKey(this)" <?php echo $view->key->is_editable ? "" : "disabled"?> >
								<?php esc_html_e('Save settings & validate','shortpixel-image-optimiser');?>
						</button>
						<span class="shortpixel-key-valid" <?php echo $view->key->is_verifiedkey ? '' : 'style="display:none;"' ?>>
								<span class="dashicons dashicons-yes"></span><?php esc_html_e('Your API key is valid.','shortpixel-image-optimiser');?>
						</span>
					<info>
							<?php if ($view->key->is_constant_key)
							{
							 		esc_html_e('Key defined in wp-config.php.','shortpixel-image-optimiser');
							}
							?>
					</info>
          </content>
        </setting>

         <!-- compression type -->
          <setting id='compression-type'>
              <name>
                  <?php esc_html_e('Compression type:','shortpixel-image-optimiser');?>
              </name>
              <content class='shortpixel-compression'>
                <input type="hidden" id="compressionType-database" value="<?php echo esc_attr($view->data->compressionType) ?>">

                    <div class="shortpixel-compression-options">
                        <label class="lossy" title="<?php esc_html_e('This is the recommended option in most cases, producing results that look the same as the original to the human eye.','shortpixel-image-optimiser');?>">
                            <input type="radio" class="shortpixel-radio-lossy" name="compressionType" value="1"  <?php echo( $view->data->compressionType == 1 ? "checked" : "" );?>><span><?php esc_html_e('Lossy','shortpixel-image-optimiser');?></span>
                        </label>

                        <label class="glossy" title="<?php esc_html_e('Best option for photographers and other professionals that use very high quality images on their sites and want best compression while keeping the quality untouched.','shortpixel-image-optimiser');?>">
                            <input type="radio" class="shortpixel-radio-glossy" name="compressionType" value="2" <?php echo( $view->data->compressionType == 2 ? "checked" : "" );?>><span><?php esc_html_e('Glossy','shortpixel-image-optimiser');?></span>
                        </label>

                        <label class="lossless" title="<?php esc_html_e('Make sure not a single pixel looks different in the optimized image compared with the original. In some rare cases you will need to use this type of compression. Some technical drawings or images from vector graphics are possible situations.','shortpixel-image-optimiser');?>">
                            <input type="radio" class="shortpixel-radio-lossless" name="compressionType" value="0" <?php echo( $view->data->compressionType == 0 ? "checked" : "" );?>><span><?php esc_html_e('Lossless','shortpixel-image-optimiser');?></span>
                        </label>

                  <?php printf(esc_html__('%s Run a few tests%s to help you decide.', 'shortpixel-image-optimiser'), '<a href="https://shortpixel.com/online-image-compression" style="margin-left:20px;" target="_blank">', '</a>'); ?>
                </div>

                  <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/11-lossy-glossy-or-lossless-which-one-is-the-best-for-me"></i>

                  <info>
                    <p class="settings-info shortpixel-radio-info shortpixel-radio-lossy" <?php echo( $view->data->compressionType == 1 ? "" : 'style="display:none"' );?>>
                        <?php printf(esc_html__('%sLossy SmartCompression (recommended): %s offers the best compression rate. %s What is SmartCompress? %s This is the recommended option for most users, producing results that look the same as the original to the human eye.','shortpixel-image-optimiser'),'<b>','</b>', '<a href="https://shortpixel.com/blog/introducing-smartcompress/" target="_blank" class="shortpixel-help-link"><span class="dashicons dashicons-editor-help"></span>', '</a><br />');?>
                    </p>
                    <p class="settings-info shortpixel-radio-info shortpixel-radio-glossy" <?php echo( $view->data->compressionType == 2 ? "" : 'style="display:none"' );?>>
                        <?php printf(esc_html__('%sGlossy SmartCompression: %s creates images that are almost pixel-perfect identical with the originals. %s What is SmartCompress? %s Best option for photographers and other professionals that use very high quality images on their sites and want the best compression while keeping the quality untouched.','shortpixel-image-optimiser'), '<b>','</b>', '<a href="https://shortpixel.com/blog/introducing-smartcompress/" target="_blank" class="shortpixel-help-link"><span class="dashicons dashicons-editor-help"></span>', '</a><br>');?>

  </p>
                    <p class="settings-info shortpixel-radio-info shortpixel-radio-lossless" <?php echo( $view->data->compressionType == 0 ? "" : 'style="display:none"' );?>>
                        <?php printf(esc_html__('%s Lossless compression: %s the resulting image is pixel-identical with the original image. %sMake sure not a single pixel looks different in the optimized image compared with the original.
                        In some rare cases you will need to use this type of compression. Some technical drawings or images from vector graphics are possible situations.','shortpixel-image-optimiser'),'<b>','</b>', '<br>');?>
                    </p>
                  </info>

              </content>

              <warning id='compression-notice'>
                    <h4><?php _e('Changing compression type', 'shortpixel-image-optimiser'); ?></h4>
                    <message>
                      <p><?php printf(esc_html__('This compression type will apply only to new or unprocessed images. Images that were already processed will not be re-optimized. If you want to change the compression type of already optimized images, %s restore them from the backup %s first.', 'shortpixel-image-optimiser' ),'<a href="options-general.php?page=wp-shortpixel-settings&part=tools">', '</a>'); ?></p>
                      <p><?php esc_html_e('The current optimization processes in the queue will be stopped.', 'shortpixel-image-optimiser'); ?></p>
                    </message>
              </warning>
          </setting>

          <!-- / compression type  -->

          <!-- Thumbnail compression -->
          <setting>
            <name>
              <?php esc_html_e('Thumbnail compression:','shortpixel-image-optimiser');?>
            </name>
            <content>
                <switch>
                  <label>
                    <input type="checkbox" class="switch" name="processThumbnails" value="1" <?php checked($view->data->processThumbnails, '1');?>>
                    <div class="the_switch">&nbsp; </div>
                    <?php printf(esc_html__('Apply compression also to %s image thumbnails.%s ','shortpixel-image-optimiser'), '<strong>', '</strong>'); ?>
                  </label>
                </switch>
                <info>
                      <?php printf(esc_html__('It is highly recommended that you optimize the thumbnails as they are usually the images most viewed by end users and can generate most traffic. %s Please note that thumbnails count up to your total quota.','shortpixel-image-optimiser'), '<br>'); ?>
                </info>
            </content>
          </setting>
          <!-- // Thumbnail compression -->

          <!-- Enable Smartcrop -->
            <setting>
              <name>
                  <?php esc_html_e('Enable SmartCrop:','shortpixel-image-optimiser');?>
              </name>
              <content>
                <switch>
                  <label>
                    <input type="checkbox" class="switch" name="useSmartcrop" value="1" <?php checked($view->data->useSmartcrop, '1');?>>
                    <div class="the_switch">&nbsp; </div>
                    <?php printf(esc_html__('Enable %s Smart cropping %s of the images where applicable.','shortpixel-image-optimiser'), '<strong>', '</strong>'); ?>
                  </label>
                </switch>

                <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/182-what-is-smart-cropping"></i>

                <info>
                  <?php printf(esc_html__('Generate subject-centered thumbnails using ShortPixel\'s AI engine (%sexample%s). The new thumbnails look sharper (and can be slightly bigger) than the ones created by WordPress. Ideal for e-commerce websites and blogs where the images sell the products/content.','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/knowledge-base/article/182-what-is-smart-cropping" target="_blank">', '</a>'); ?>
                </info>
                <?php
                $smartcrop = (
                  true === \wpSPIO()->env()->plugin_active('s3-offload') ||
                  true === \wpSPIO()->env()->plugin_active('s3-offload-pro')
                ) ? 1 : 0; ?>
              </content>
                <warning id="smartcrop-warning" data-smartcrop="<?php echo esc_attr($smartcrop) ?>">
                    <message>
    									<?php esc_html_e('It looks like you have the Offload Media plugin enabled. Please note that SmartCropping will not work if you have set the Offload Media plugin to remove files from the server, and strange effects may occur! We recommend you to disable this option in this case.', 'shortpixel-image-optimiser'); ?>
                    </message>
                </warning>
            </setting>
          <!-- // Enable Smartcrop -->

          <!-- Backup -->
            <setting>
              <name>
                <?php esc_html_e('Backup','shortpixel-image-optimiser');?>
              </name>
              <content>
                <switch>
                  <label>
                    <input type="checkbox" class="switch" name="backupImages" value="1" <?php checked($view->data->backupImages, '1');?>>
                    <div class="the_switch">&nbsp; </div>
                   <?php esc_html_e('Create a backup of the original images, saved on your server in /wp-content/uploads/ShortpixelBackups/.','shortpixel-image-optimiser');?>
                  </label>
                </switch>
                <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/515-settings-image-backup"></i>
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

          <!-- Remove Exif -->
          <setting>
            <name>
              <?php esc_html_e('Remove EXIF','shortpixel-image-optimiser');?>
            </name>
            <content>
              <switch>
                <label>
                  <input type="checkbox" class="switch" name="removeExif" value="1" <?php checked($view->data->keepExif, 0);?>>
                  <div class="the_switch">&nbsp; </div>
                  <?php esc_html_e('Remove the EXIF tag of the image (recommended).','shortpixel-image-optimiser');?>
                </label>
              </switch>
              <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/483-spai-remove-exif"></i>
            </content>
            <warning id="exif-warning">
              <message>
                <?php printf(esc_html__('Warning - Converting from PNG to JPG will %s not %s keep the EXIF information!'), "<strong>","</strong>"); ?>
              </message>
            </warning>
            <?php $imagick = (\wpSPIO()->env()->hasImagick()) ? 1 : 0; ?>
            <warning id="exif-imagick-warning" data-imagick="<?php echo esc_attr($imagick) ?>">
                <message>
                  <?php printf(esc_html__('Warning - Imagick library not detected on server. WordPress will use another library to resize images, which may result in loss of EXIF information'), "<strong>","</strong>"); ?>
                </message>
            </warning>
          </setting>
          <!-- // Remove Exif -->

          <!-- Resize Large Image -->
          <setting>
            <name>
              <?php esc_html_e('Resize large images','shortpixel-image-optimiser');?>
            </name>
            <content>
							<?php  $resizeDisabled = (! $this->view->data->resizeImages) ? 'disabled' : '';
								 // @todo Inline styling here can be decluttered.
							?>
							<input type="hidden" id="min-resizeWidth" value="<?php echo esc_attr($view->minSizes['width']);?>" data-nicename="<?php esc_html_e('Width', 'shortpixel-image-optimiser'); ?>" />

							<input type="hidden" id="min-resizeHeight" value="<?php echo esc_attr($view->minSizes['height']);?>" data-nicename="<?php esc_html_e('Height', 'shortpixel-image-optimiser'); ?>"/>

							<div class='switch_button'>
								<label>
									<input type="checkbox" class="switch" name="resizeImages" id='resize' value="1" <?php checked($view->data->resizeImages, true);?>>
									<div class="the_switch">&nbsp; </div>
									<?php esc_html_e('to maximum','shortpixel-image-optimiser') ?>
								</label>
							</div>

						<input type="number" min="1" max="20000" name="resizeWidth" id="width" class="resize-sizes"
									 value="<?php echo esc_attr( $view->data->resizeWidth > 0 ? $view->data->resizeWidth : min(1200, $view->minSizes['width']) );?>" <?php echo esc_attr( $resizeDisabled );?>/> <?php
									 esc_html_e('pixels wide &times;','shortpixel-image-optimiser');?>

						<input type="number" min="1" max="20000" name="resizeHeight" id="height" class="resize-sizes"
									 value="<?php echo esc_attr( $view->data->resizeHeight > 0 ? $view->data->resizeHeight : min(1200, $view->minSizes['height']) );?>" <?php echo esc_attr( $resizeDisabled );?>/> <?php
									 esc_html_e('pixels high (preserves the original aspect ratio and doesn\'t crop the image)','shortpixel-image-optimiser');?>

							<info>
								<?php esc_html_e('Recommended for large photos, like the ones taken with your phone. Saved space can go up to 80% or more after resizing. Please note that this option does not prevent thumbnails from being created that should  be larger than the selected dimensions, but these thumbnails will also be resized to the dimensions selected here.','shortpixel-image-optimiser');?>
                <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/208-can-shortpixel-automatically-resize-new-image-uploads"></i>
							</info>

              <div class="resize-type-wrap" <?php echo( $view->data->resizeImages ? '' : 'style="display:none;"' );?>>
                  <div class="resize-options-wrap">
                      <label title="<?php esc_html_e('Sizes will be greater or equal to the corresponding value. For example, if you set the resize dimensions at 1000x1200, an image of 2000x3000px will be resized to 1000x1500px while an image of 3000x2000px will be resized to 1800x1200px','shortpixel-image-optimiser');?>">
                          <input type="radio" name="resizeType" id="resize_type_outer" value="outer" <?php echo esc_attr($view->data->resizeType) == 'inner' ? '' : 'checked'; ?>>
                          <?php esc_html_e( 'Cover', 'shortpixel-image-optimiser' ); ?>
                      </label><br>
                      <label title="<?php esc_html_e('Sizes will be smaller or equal to the corresponding value. For example, if you set the resize dimensions at 1000x1200, an image of 2000x3000px will be resized to 800x1200px while an image of 3000x2000px will be resized to 1000x667px','shortpixel-image-optimiser');?>">
                          <input type="radio" name="resizeType" id="resize_type_inner" value="inner" <?php echo esc_attr($view->data->resizeType) == 'inner' ? 'checked' : ''; ?>>
                          <?php esc_html_e( 'Contain', 'shortpixel-image-optimiser' ); ?>
                      </label><br>


                  </div>
                  <?php
                  $resize_width  = (int) ( $view->data->resizeWidth > 0 ? $view->data->resizeWidth : min( 1200, $view->minSizes[ 'width' ] ) );
                  $resize_height = (int) ( $view->data->resizeHeight > 0 ? $view->data->resizeHeight : min( 1200, $view->minSizes[ 'height' ] ) );
                  $ratio         = $resize_height / $resize_width;

                  $frame_style = 'padding-top:' . round( ( $ratio < 1.5 ? ( $ratio < 0.5 ? 0.5 : $ratio ) : 1.5 ) * 100, 0 ) . '%;';

                  $image_size = getimagesize( wpSPIO()->plugin_path( 'res/img/resize-type.png' ) );
                  ?>
                  <div class="presentation-wrap">
                      <div class="spai-resize-frame"></div>
                      <img class="spai-resize-img" src="<?php echo esc_url(wpSPIO()->plugin_url('res/img/resize-type.png'));?>" data-width="300" data-height="160"
                           srcset="<?php echo esc_url(wpSPIO()->plugin_url('res/img/resize-type@2x.png'));?> 2x" alt="">
                  </div>

              </div>
            </content>
          </setting>
          <!-- / Resize Large Image -->


    </settinglist>


  <p class="submit">
      <input type="submit" name="save" id="save" class="button button-primary" title="<?php esc_attr_e('Save Changes','shortpixel-image-optimiser');?>" value="<?php esc_attr_e('Save Changes','shortpixel-image-optimiser');?>"> &nbsp;
      <input type="submit" name="save_bulk" id="bulk" class="button button-primary" title="<?php esc_attr_e('Save and go to the Bulk Processing page','shortpixel-image-optimiser');?>" value="<?php esc_attr_e('Save and Go to Bulk Process','shortpixel-image-optimiser');?>"> &nbsp;
  </p>
</div>

</section>
