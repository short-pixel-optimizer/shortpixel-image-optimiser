<?php
namespace ShortPixel;
use ShortPixel\Helper\UiHelper as UiHelper;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


?>

<section id="tab-optimisation" class="<?php echo ($this->display_part == 'optimisation') ? 'active setting-tab' :'setting-tab'; ?>" data-part="optimisation" >

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
    <settinglist >

        <h2><?php esc_html_e('Image Optimization Settings','shortpixel-image-optimiser');?></h2>

         <!-- compression type -->
          <setting id='compression-type' class="step-highlight-2">

            <div class='wp-clearfix'>
                <content class='shortpixel-compression'>
                  <name>
                      <?php esc_html_e('Compression type:','shortpixel-image-optimiser');?>
                  </name>
                  <input type="hidden" id="compressionType-database" value="<?php echo esc_attr($view->data->compressionType) ?>">

                  <div class="shortpixel-compression-options">
                    <label class="lossy" title="<?php esc_html_e('This is the recommended option in most cases, producing results that look the same as the original to the human eye.','shortpixel-image-optimiser');?>">
                        <input type="radio" class="shortpixel-radio-lossy" name="compressionType" value="1"  <?php echo( $view->data->compressionType == 1 ? "checked" : "" );?>><span><?php esc_html_e('Lossy','shortpixel-image-optimiser');?></span>
                    </label><label class="glossy" title="<?php esc_html_e('Best option for photographers and other professionals that use very high quality images on their sites and want best compression while keeping the quality untouched.','shortpixel-image-optimiser');?>">
                        <input type="radio" class="shortpixel-radio-glossy" name="compressionType" value="2" <?php echo( $view->data->compressionType == 2 ? "checked" : "" );?>><span><?php esc_html_e('Glossy','shortpixel-image-optimiser');?></span>
                    </label><label class="lossless" title="<?php esc_html_e('Make sure not a single pixel looks different in the optimized image compared with the original. In some rare cases you will need to use this type of compression. Some technical drawings or images from vector graphics are possible situations.','shortpixel-image-optimiser');?>">
                        <input type="radio" class="shortpixel-radio-lossless" name="compressionType" value="0" <?php echo( $view->data->compressionType == 0 ? "checked" : "" );?>><span><?php esc_html_e('Lossless','shortpixel-image-optimiser');?></span>
                    </label>
                    <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/11-lossy-glossy-or-lossless-which-one-is-the-best-for-me?target=iframe"></i>
                </div>

                <info>
                    <p class="settings-info shortpixel-radio-info shortpixel-radio-lossy" <?php echo( $view->data->compressionType == 1 ? "" : 'style="display:none"' );?>>
                        <?php printf(esc_html__('%sLossy SmartCompression (recommended): %s offers the best compression rate.  This option is recommended for most users, as it produces results that appear identical to the original to the human eye.','shortpixel-image-optimiser'),'<b>','</b>');?>
                    </p>
                    <p class="settings-info shortpixel-radio-info shortpixel-radio-glossy" <?php echo( $view->data->compressionType == 2 ? "" : 'style="display:none"' );?>>
                        <?php printf(esc_html__('%sGlossy SmartCompression: %s creates images that are nearly pixel-perfect replicas of the originals. It is the best option for photographers and other professionals who use high-quality images on their sites and want optimal compression without compromising quality.','shortpixel-image-optimiser'), '<b>','</b>');?>

                    </p>
                    <p class="settings-info shortpixel-radio-info shortpixel-radio-lossless" <?php echo( $view->data->compressionType == 0 ? "" : 'style="display:none"' );?>>
                        <?php printf(esc_html__('%s Lossless compression: %s the resulting image is pixel-identical to the original. %sEnsure that not a single pixel differs between the optimized image and the original.
                        This type of compression may be necessary in rare cases, such as with certain technical drawings or images from vector graphics.','shortpixel-image-optimiser'),'<b>','</b>', '<br><br>');?>
                    </p>

                    <?php printf(esc_html__('%s Run a few tests%s to help you decide.', 'shortpixel-image-optimiser'), '<a href="https://shortpixel.com/online-image-compression" target="_blank">', '</a>'); ?>
                </info>
              </content>
              <content class='smart-compression-explainer' >
                    <?php echo UIHelper::getIcon('res/images/illustration/robo_info.svg'); ?>
                    <h4><?php esc_html_e('What is SmartCompression?', 'shortpixel-image-optimiser'); ?></h4>

                    <?php _e('Images that look just good as the original, but with the smallest possible file size', 'shortpixel-image-optimiser'); ?>
                    <a href="https://shortpixel.com/blog/introducing-smartcompress/" target="_blank" class="shortpixel-help-link"><?php _e('Learn more', 'shortpixel-image-optimiser'); ?></a>
              </content>
            </div>

              <warning id='compression-notice'>
                    <h4><?php _e('Changing compression type', 'shortpixel-image-optimiser'); ?></h4>
                    <message>
                      <p><?php printf(esc_html__('This compression type will apply only to new or unprocessed images. Images that were already processed will not be re-optimized. If you want to change the compression type of already optimized images, %s restore them from the backup %s first.', 'shortpixel-image-optimiser' ),'<a href="options-general.php?page=wp-shortpixel-settings&part=tools">', '</a>'); ?></p>
                      <p><?php esc_html_e('The current optimization processes in the queue will be stopped.', 'shortpixel-image-optimiser'); ?></p>
                    </message>
              </warning>
          </setting>
          <!-- / compression type  -->

					<h3><?php _e('What to Optimize', 'shortpixel-image-optimiser'); ?></h3>

          <gridbox class='width_half step-highlight-2'>

          <!-- Thumbnail compression -->
          <setting class='switch' class="step-highlight-2">
            <content>
              <?php $this->printSwitchButton(
                    ['name' => 'processThumbnails',
                     'checked' => $view->data->processThumbnails,
                     'label' => esc_html__('Optimize Thumbnails','shortpixel-image-optimiser')
                    ]);
              ?>

                <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/settings-optimize-thumbnails/?target=iframe"></i>
		<name>
                    <?php printf(esc_html__('Apply compression to image thumbnails','shortpixel-image-optimiser') ); ?>
		</name>
                <info>
                      <?php printf(esc_html__('It is highly recommended to optimize thumbnails, as they are often the images most viewed by end users and can generate the most traffic. %s Please note that thumbnails count toward your total quota.','shortpixel-image-optimiser'), '<br>'); ?>
                </info>
            </content>
          </setting>
          <!-- // Thumbnail compression -->

          <!--- Optimize Other Image -->
          <setting class='switch'>
              <content>
                <?php $this->printSwitchButton(
                      ['name' => 'optimizeUnlisted',
                       'checked' => $view->data->optimizeUnlisted,
                       'label' => esc_html__('Optimize unlisted thumbnails','shortpixel-image-optimiser')
                      ]);
                ?>

                <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/519-settings---optimize-other-thumbs?target=iframe"></i>
                <name>
                  <?php esc_html_e('Optimize unlisted thumbnails, if found.','shortpixel-image-optimiser');?>
                </name>

              </content>
              <warning class="heavy-feature-virtual unlisted">
                  <message>
                    <?php printf(esc_html__('This feature has been disabled in offload mode for performance reasons. You can enable it again with a %s filter hook %s ', 'shortpixel-image-optimiser' ),'<a target="_blank" href="https://shortpixel.com/knowledge-base/article/577-performance-improvement-shortpixel-image-optimization-media-offload-plugin">', '</a>'); ?>
                  </message>
              </warning>
          </setting>


          <setting class='switch'>
            <content>
              <?php $this->printSwitchButton(
                    ['name' => 'optimizePdfs',
                     'checked' => $view->data->optimizePdfs,
                     'label' => esc_html__('Optimize PDFs','shortpixel-image-optimiser')
                    ]);
              ?>
               <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/520-settings-optimize-pdfs?target=iframe"></i>
               <name>
                 <?php esc_html_e('Also optimize PDF documents.','shortpixel-image-optimiser');?>
               </name>
            </content>
         </setting>

         <!-- Optimize retina -->
          <setting class='switch'>
             <content>
               <?php $this->printSwitchButton(
                     ['name' => 'optimizeRetina',
                      'checked' => $view->data->optimizeRetina,
                      'label' => esc_html__('Optimize Retina images','shortpixel-image-optimiser')
                     ]);
               ?>

              <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/settings-optimize-retina-images/?target=iframe"></i>
              <name>
                  <?php esc_html_e('Also optimize the Retina images (@2x) if they exist.','shortpixel-image-optimiser');?>
              </name>
             </content>

             <warning class='heavy-feature-virtual retina'>
               <message>
                   <?php printf(esc_html__('This feature has been disabled in offload mode for performance reasons. You can enable it again with a %s filter hook %s ', 'shortpixel-image-optimiser' ),'<a target="_blank" href="https://shortpixel.com/knowledge-base/article/577-performance-improvement-shortpixel-image-optimization-media-offload-plugin">', '</a>'); ?>
               </message>
             </warning>
         </setting>


         <!-- Nextgen setting -->
         <?php if($this->has_nextgen) : ?>
          <setting>
            <name>
              <?php esc_html_e('NextGen','shortpixel-image-optimiser');?>
            </name>
            <content>
              <?php $this->printSwitchButton(
                    ['name' => 'includeNextGen',
                     'checked' => $view->data->includeNextGen,
                     'label' => esc_html__('Optimize NextGen galleries','shortpixel-image-optimiser')
                    ]);
              ?>
              <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/how-to-optimize-your-nextgen-galleries-with-shortpixel-image-optimizer/?target=iframe"></i>
              <name>
                  <?php esc_html_e('Enable this option to optimize automatically the NextGen galleries.','shortpixel-image-optimiser');?>
              </name>
            </content>
         </setting>
         <?php endif; ?>
         <!-- // Nextgen setting -->



        </gridbox>

          <h3><?php _e('Conversions', 'shortpixel-image-optimiser'); ?></h3>

          <gridbox class='width_half step-highlight-2'>
          <!-- convert png2jpg -->
          <setting class='switch'>
            <content>

              <switch class='option-png2jpg'>
                <label>
                  <input type="checkbox" class="switch" name="png2jpg" data-disable="png2jpgForce" value="1" <?php checked( ($view->data->png2jpg > 0), true);?> <?php echo($this->is_gd_installed ? '' : 'disabled') ?> >
                  <div class="the_switch">&nbsp; </div>
                <?php esc_html_e('Convert PNG images to JPEG','shortpixel-image-optimiser');?>
                </label>
              </switch>

              <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/516-settings-convert-png-images-to-jpeg?target=iframe"></i>
              <name>
                  <?php esc_html_e('Automatically convert the PNG images to JPEG, if possible.','shortpixel-image-optimiser'); ?>
              </name>
            </content>

            <?php  if(false === $this->is_gd_installed): ?>

            <warning class='is-visible'>
              <message>
                  <?php esc_html_e('You need PHP GD with support for JPEG and PNG files for this feature. Please ask your hosting 	provider to install it.','shortpixel-image-optimiser');  ?>
              </message>
           </warning>
           <?php endif; ?>

           <warning class='exif-warning'>
             <message>
                <?php printf(esc_html__('Warning - Converting from PNG to JPG will %s not %s keep the EXIF information!', 'shortpixel-image-optimiser'), "<strong>","</strong>"); ?>
             </message>
          </warning>
        </setting>
        <!-- // convert png2jpg -->

        <!-- Force convert -->
        <setting class='switch'>
          <content>
          <switch class='switch_button option-png2jpgforce suboption' id="png2jpgforce">
            <label>
              <input type="checkbox" class="switch" name="png2jpgForce" value="1" <?php checked(($view->data->png2jpg > 1), true);?> <?php echo($this->is_gd_installed ? '' : 'disabled') ?>>
              <div class="the_switch">&nbsp; </div>
              <?php esc_html_e('Force conversion of transparent images', 'shortpixel-image-optimiser'); ?>
            </label>
          </switch>
          <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/settings-force-conversion-of-images-when-transparent/?target=iframe"></i>
          <name>
            <?php esc_html_e('The transparency will be lost.','shortpixel-image-optimiser'); ?>
          </name>
        </content>
        </setting>

        <!-- Cmyk to rgb -->
        <setting class='switch'>
            <content>

              <?php $this->printSwitchButton(
                    ['name' => 'cmyk2rgb',
                     'checked' => $view->data->CMYKtoRGBconversion,
                     'label' => esc_html__('CMYK to RGB conversion','shortpixel-image-optimiser')
                    ]);
              ?>
              <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/517-settings---cmyk-to-rgb-conversion?target=iframe"></i>
              <name>
                  <?php esc_html_e('Adjust your images\' colors for computer and mobile displays.','shortpixel-image-optimiser');?>
              </name>
            </content>
         </setting>
         <!-- // Cmyk to rgb -->

          <!-- Remove Exif -->
          <setting class='switch'>
            <content>

              <switch>
                <label>
                  <input type="checkbox" class="switch" name="removeExif" value="1" <?php checked($view->data->keepExif, 0);?>>
                  <div class="the_switch">&nbsp; </div>
                  <?php esc_html_e('Remove EXIF','shortpixel-image-optimiser');?>

                </label>
              </switch>
              <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/483-spai-remove-exif?target=iframe"></i>
              <name>
                <?php esc_html_e('Remove the EXIF data of the images (recommended).','shortpixel-image-optimiser');?>

              </name>
            </content>
            <warning class="exif-warning">
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

        </gridbox>

          <h3><?php _e('SmartCrop & Resize', 'shortpixel-image-optimiser'); ?></h3>

          <!-- Enable Smartcrop -->
            <setting class='switch step-highlight-2'>
              <content>
                <switch>
                  <label>
                    <!-- @todo Should use the switch method as others -->
                    <input type="checkbox" class="switch" name="useSmartcrop" value="1" <?php checked($view->data->useSmartcrop, '1');?>>
                    <div class="the_switch">&nbsp; </div>
                    <?php esc_html_e('Enable SmartCrop','shortpixel-image-optimiser');?>

                  </label>
                </switch>
                <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/182-what-is-smart-cropping?target=iframe"></i>
                <name>
                    <?php printf(esc_html__('%s Smart crop %s images where applicable.','shortpixel-image-optimiser'), '<strong>', '</strong>'); ?>
                </name>
                <info>
                  <?php printf(esc_html__('Generate subject-centered thumbnails using ShortPixel\'s AI engine (%ssee sexample%s). These new thumbnails appear sharper and may be slightly larger than those created by WordPress, making them ideal for e-commerce websites and blogs where images are key to showcasing products and content.','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/knowledge-base/article/182-what-is-smart-cropping" target="_blank">', '</a>'); ?>
                </info>
                <?php
                $smartcrop = (
                  true === \wpSPIO()->env()->plugin_active('s3-offload')
                ) ? 1 : 0; ?>
              </content>

              <input type='checkbox' name='offload-active' class='shortpixel-hide' value='1' <?php echo ($smartcrop === 1) ? 'checked' : '' ?> />

                <warning id="smartcrop-warning">
                    <message>
    									<?php esc_html_e('It looks like you have the Offload Media plugin enabled. Please note that SmartCropping will not work if you have set the Offload Media plugin to remove files from the server, and strange effects may occur! We recommend you to disable this option in this case.', 'shortpixel-image-optimiser'); ?>
                    </message>
                </warning>
            </setting>
          <!-- // Enable Smartcrop -->

<!--
          <div class='cross-border'>
              <span class='text'>OR</span> <hr>
          </div>
-->
          <!-- Resize Large Image -->
          <setting class="step-highlight-2">
            <content>
							<?php  $resizeDisabled = (! $this->view->data->resizeImages) ? 'disabled' : '';
								 // @todo Inline styling here can be decluttered.
							?>
							<input type="hidden" id="min-resizeWidth" value="<?php echo esc_attr($view->minSizes['width']);?>" data-nicename="<?php esc_html_e('Width', 'shortpixel-image-optimiser'); ?>" />

							<input type="hidden" id="min-resizeHeight" value="<?php echo esc_attr($view->minSizes['height']);?>" data-nicename="<?php esc_html_e('Height', 'shortpixel-image-optimiser'); ?>"/>

							<switch>
								<label>
									<input type="checkbox" class="switch" name="resizeImages" id='resize' value="1"

                  <?php checked($view->data->resizeImages, true);?>>
              		<div class="the_switch">&nbsp; </div>
                  <?php esc_html_e('Resize large images','shortpixel-image-optimiser');?>
								</label>
							</switch>
                					<i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/208-can-shortpixel-automatically-resize-new-image-uploads?target=iframe"></i>

            <info>
            <?php esc_html_e('Resize to maximum','shortpixel-image-optimiser') ?>

						<input type="number" min="1" max="20000" name="resizeWidth" id="width" class="resize-sizes"
									 value="<?php echo esc_attr( $view->data->resizeWidth > 0 ? $view->data->resizeWidth : min(1200, $view->minSizes['width']) );?>" <?php echo esc_attr( $resizeDisabled );?>/> <?php
									 esc_html_e('pixels wide &times;','shortpixel-image-optimiser');?>

						<input type="number" min="1" max="20000" name="resizeHeight" id="height" class="resize-sizes"
									 value="<?php echo esc_attr( $view->data->resizeHeight > 0 ? $view->data->resizeHeight : min(1200, $view->minSizes['height']) );?>" <?php echo esc_attr( $resizeDisabled );?>/> <?php
									 esc_html_e('pixels high ','shortpixel-image-optimiser');?>
            </info>
							<info>

								<?php esc_html_e('Preserves the original aspect ratio without cropping the image. Recommended for large photos, such as those taken with a smartphone. This can save up to 80% or more in storage space after resizing. Please note that this option does not prevent thumbnails from being created at dimensions larger than the selected size; however, these thumbnails will also be resized to match the dimensions chosen here.','shortpixel-image-optimiser');?>
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



    <?php $this->loadView('settings/part-savebuttons', false); ?>

</section>
