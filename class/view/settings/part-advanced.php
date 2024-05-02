<?php
namespace ShortPixel;
use \ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>
<section id="tab-adv-settings" class="clearfix <?php echo esc_attr(($this->display_part == 'adv-settings') ? ' sel-tab ' :''); ?> ">
    <h2><a class='tab-link' href='javascript:void(0);' data-id="tab-adv-settings"><?php esc_html_e('Advanced','shortpixel-image-optimiser');?></a></h2>

    <?php
    $deliverWebpAlteredDisabled = '';
    $deliverWebpUnalteredDisabled = '';
    $deliverWebpAlteredDisabledNotice = false;
    $deliverWebpUnalteredLabel ='';
    $deliverAVIFLabel ='';

    if( $this->is_nginx ){
        $deliverWebpUnaltered = '';                         // Uncheck
        $deliverWebpUnalteredDisabled = 'disabled';         // Disable
        $deliverWebpUnalteredLabel = __('It looks like you\'re running your site on an NGINX server. This means that you can only achieve this functionality by directly configuring the server config files. Please follow this link for instructions:','shortpixel-image-optimiser')." <a class=\"shortpixel-help-link\" href=\"https://shortpixel.com/knowledge-base/article/111-configure-nginx-to-transparently-serve-webp-files-when-supported\" target=\"_blank\" data-beacon-article=\"5bfeb9de2c7d3a31944e78ee\"><span class=\"dashicons dashicons-editor-help\"></span></a>";
        $deliverAVIFLabel = __('<strong>It looks like you\'re running your site on an NGINX server. You may need additional configuration for the AVIF delivery to work as expected</strong>','shortpixel-image-optimiser')." <a class=\"shortpixel-help-link\" href=\"https://shortpixel.com/knowledge-base/article/499-how-do-i-configure-my-web-server-to-deliver-avif-images\" target=\"_blank\"><span class=\"dashicons dashicons-editor-help\"></span></a>";
    } else {
        if( !$this->is_htaccess_writable ){
            $deliverWebpUnalteredDisabled = 'disabled';     // Disable
            if( $view->data->deliverWebp == 3 ){
                $deliverWebpAlteredDisabled = 'disabled';   // Disable
                $deliverWebpUnalteredLabel = __('It looks like you recently moved from an Apache server to an NGINX server, while the option to use .htacces was in use. Please follow this tutorial to see how you could implement by yourself this functionality, outside of the WP plugin: ','shortpixel-image-optimiser') . '<a href="https://shortpixel.com/knowledge-base/article/111-configure-nginx-to-transparently-serve-webp-files-when-supported" target="_blank" data-beacon-article="5bfeb9de2c7d3a31944e78ee"></a>';
            } else {
                $deliverWebpUnalteredLabel = __('It looks like your .htaccess file cannot be written. Please fix this and then return to refresh this page to enable this option.','shortpixel-image-optimiser');
            }
        } elseif (isset($_SERVER['HTTP_USER_AGENT']) && strpos( wp_unslash($_SERVER['HTTP_USER_AGENT']), 'Chrome') !== false) {
            // Show a message about the risks and caveats of serving WEBP images via .htaccess
            $deliverWebpUnalteredLabel = '<span style="color: initial;">'. esc_html__('Based on testing your particular hosting configuration, we determined that your server','shortpixel-image-optimiser').
                '&nbsp;<img alt="can or can not" src="'. esc_url(plugins_url( 'res/img/test.jpg' , SHORTPIXEL_PLUGIN_FILE)) .'">&nbsp;'.
                esc_html__('serve the WebP or AVIF versions of the JPEG files seamlessly, via .htaccess.','shortpixel-image-optimiser').' <a href="https://shortpixel.com/knowledge-base/article/127-delivering-webp-images-via-htaccess" target="_blank" data-beacon-article="5c1d050e04286304a71d9ce4">Open article to read more about this.</a></span>';
        }
    }

    ?>

    <div class="wp-shortpixel-options wp-shortpixel-tab-content" style='visibility: hidden'>
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Next Generation Images','shortpixel-image-optimiser');?></th>
                <td>
                    <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/286-how-to-serve-webp-files-using-spio"></span></div>
									 <div class='switch_button'>
										 <label>
											 <input type="checkbox" class="switch" name="createWebp" value="1" <?php checked( $view->data->createWebp, "1" );?>>
											 <div class="the_switch">&nbsp; </div>
											  <?php printf(esc_html__('Create %s WebP versions %s of the images. Each image/thumbnail will use an additional credit unless you use the %s Unlimited plan. %s','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/blog/how-webp-images-can-speed-up-your-site/" target="_blank">', '</a>', '<a href="https://shortpixel.com/knowledge-base/article/555-how-does-the-unlimited-plan-work" target="_blank">', '</a>' );?>
										 </label>
									 </div>

                    <p>&nbsp;</p>
										<?php
											$avifEnabled = $this->access()->isFeatureAvailable('avif');
											$createAvifChecked = ($view->data->createAvif == 1 && $avifEnabled === true) ? true : false;
											$disabled = ($avifEnabled === false) ? 'disabled' : '';
											$avifEnabledNotice = false;
											if ($avifEnabled == false)
											{
												 $avifEnabledNotice = '<div class="sp-notice sp-notice-warning  avifNoticeDisabled">';
												 $avifEnabledNotice .=  __('The creation of AVIF files is not possible with this license type.', 'shortpixel-image-optimiser') ;
												 $avifEnabledNotice .=  '<div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/555-how-does-the-unlimited-plan-work"></span></div>';
												 $avifEnabledNotice .= '</div>';
											}
										?>

                    <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/467-how-to-create-and-serve-avif-files-using-shortpixel-image-optimizer"></span></div>
									 <div class='switch_button'>
										 <label>
											 <input type="checkbox" class="switch" name="createAvif" value="1" <?php echo $disabled ?> <?php checked( $createAvifChecked );?>>
											 <div class="the_switch">&nbsp; </div>
											 <?php printf(esc_html__('Create %s AVIF versions %s of the images. Each image/thumbnail will use an additional credit. ','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/blog/what-is-avif-and-why-is-it-good/" target="_blank">', '</a>');?>
										 </label>
									 </div>

                   <?php if(strlen($deliverAVIFLabel)){ ?>
                                <p class="sp-notice sp-notice-warning">
                               <?php echo ( $deliverAVIFLabel );?>
                                </p>
                   <?php } ?>
									 <?php if ($avifEnabledNotice !== false) {  echo $avifEnabledNotice;  } ?>

                    <p>&nbsp;</p>

                    <div class="deliverWebpSettings">
                        <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/126-which-webp-files-delivery-method-is-the-best-for-me"></span></div>
											 <div class='switch_button'>
												 <label>
													 <input type="checkbox" class="switch" name="deliverWebp" data-toggle="deliverTypes" value="1" <?php checked( ($view->data->deliverWebp > 0), true);?>>
													 <div class="the_switch">&nbsp; </div>
													 <?php esc_html_e('Deliver the next generation versions of the images in the front-end:','shortpixel-image-optimiser');?>
												 </label>
											 </div>


                        <ul class="deliverWebpTypes toggleTarget" id="deliverTypes">
                            <li>
                                <input type="radio" name="deliverWebpType" id="deliverWebpAltered" <?php checked( ($view->data->deliverWebp >= 1 && $view->data->deliverWebp <= 2), true); ?> <?php echo esc_attr( $deliverWebpAlteredDisabled );?> value="deliverWebpAltered" data-toggle="deliverAlteringTypes">
                                <label for="deliverWebpAltered">
                                    <?php esc_html_e('Using the &lt;PICTURE&gt; tag syntax','shortpixel-image-optimiser');?>
                                </label>

                                <?php if($deliverWebpAlteredDisabledNotice){ ?>
                                    <p class="sp-notice">
                                        <?php esc_html_e('After the option to work on .htaccess was selected, the .htaccess file has become unaccessible / read-only. Please make the .htaccess file writeable again to be able to further set this option up.','shortpixel-image-optimiser')?>
                                    </p>
                                <?php } ?>

                                <p class="settings-info">
                                     <?php esc_html_e('Each &lt;img&gt; will be replaced with a &lt;picture&gt; tag that will also provide AVIF and WebP images for browsers that support it.  You don\'t need to activate this if you\'re using the Cache Enabler plugin because your AVIF\WebP images are already handled by this plugin. <strong>Please run some tests before using this option!</strong> If the styles that your theme is using rely on the position of your &lt;img&gt; tags, you may experience display problems.','shortpixel-image-optimiser'); ?>
                                    <strong><?php esc_html_e('You can revert anytime to the previous state just by deactivating the option.','shortpixel-image-optimiser'); ?></strong>
                                </p>

                                <ul class="deliverWebpAlteringTypes toggleTarget" id="deliverAlteringTypes">
                                    <li>
                                        <input type="radio" name="deliverWebpAlteringType" id="deliverWebpAlteredWP" <?php checked(($view->data->deliverWebp == 2), true);?> value="deliverWebpAlteredWP">
                                        <label for="deliverWebpAlteredWP">
                                            <?php esc_html_e('Only via Wordpress hooks (like the_content, the_excerpt, etc)');?>
                                        </label>
                                    </li>
                                    <li>
                                        <input type="radio" name="deliverWebpAlteringType" id="deliverWebpAlteredGlobal" <?php checked(($view->data->deliverWebp == 1),true)?>  value="deliverWebpAlteredGlobal">
                                        <label for="deliverWebpAlteredGlobal">
                                            <?php esc_html_e('Global (processes the whole output buffer before sending the HTML to the browser)','shortpixel-image-optimiser');?>
                                        </label>
                                    </li>
                                </ul>
                            </li>
                            <li>
                                <input type="radio" name="deliverWebpType" id="deliverWebpUnaltered" <?php checked(($view->data->deliverWebp == 3), true);?> <?php echo esc_attr( $deliverWebpUnalteredDisabled );?> value="deliverWebpUnaltered" data-toggle="deliverAlteringTypes" data-toggle-reverse>

                                <label for="deliverWebpUnaltered">
                                    <?php esc_html_e('Without altering the page code (via .htaccess)','shortpixel-image-optimiser')?>
                                </label>
                                <?php if(strlen($deliverWebpUnalteredLabel)){ ?>
                                    <p class="sp-notice sp-notice-warning"><strong>
                                        <?php echo( $deliverWebpUnalteredLabel );?>
																			</strong>
                                    </p>
                                <?php } ?>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Optimize media on upload','shortpixel-image-optimiser');?></th>
                <td>
                    <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/521-settings-optimize-media-on-upload"></span></div>
									 <div class='switch_button'>
										 <label>
											 <input type="checkbox" class="switch" name="autoMediaLibrary" id='autoMediaLibrary' value="1" <?php checked( $view->data->autoMediaLibrary, "1" );?>>
											 <div class="the_switch">&nbsp; </div>
											 	<?php esc_html_e('Automatically optimize images after they are uploaded (recommended).','shortpixel-image-optimiser');?>
									 </label>
									 </div>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('Background mode','shortpixel-image-optimiser');?>
	              <span class='new'><?php _e('New!', 'shortpixel-image-optimiser'); ?></span>
		</th>
                <td>
                    <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/584-background-processing-using-cron-jobs-in-shortpixel-image-optimizer"></span></div>
									 <div class='switch_button'>
										 <label>
											 <input type="checkbox" class="switch" name="doBackgroundProcess" id='doBackgroundProcess' value="1" <?php checked( $view->data->doBackgroundProcess, "1" );?> data-toggle="background_warning">
											 <div class="the_switch">&nbsp; </div>
											 	<?php esc_html_e('Utilize this feature to optimize images without the need to keep a browser window open, using cron jobs.','shortpixel-image-optimiser');?>
									 </label>
									 </div>
                   <div class='view-notice warning toggleTarget' id="background_warning">
                     <p class=""><?php _e('I understand that background optimization may pause if there are no visitors on the website.', 'shortpixel-image-optimiser'); ?></p>
                   </div>
                </td>
            </tr>

						<?php if ( $view->data->frontBootstrap == 1):  ?>


            <tr id="frontBootstrapRow">
                <th scope="row"><?php esc_html_e('Process in the front-end','shortpixel-image-optimiser');?></th>
                <td>
                    <input name="frontBootstrap" type="checkbox" id="frontBootstrap" value="1" <?php checked( $view->data->frontBootstrap, '1' );?>>
                    <label for="frontBootstrap"><?php esc_html_e('Automatically optimize images added by users in front-end of the site.','shortpixel-image-optimiser');?></label>

                </td>
            </tr>
						<tr>
							<th scope='row'>&nbsp;</th>
							<td>
                             <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/536-why-is-the-option-process-in-the-front-end-gone"></span></div>
								<div class='view-notice warning'><p><?php esc_html_e('Important. From version 5 the front processing option is no longer available. There will be no processing on the frontend. To enable optimizing images without visiting the backend, please see the options available for command line optimization.', 'shortpixel-image-optimiser') ?></p>
									<p><?php esc_html_e('To turn off this message, click the checkbox and save settings', 'shortpixel-image-optimiser'); ?></p>
								</div>
							</td>
						</tr>

					<?php endif; ?>



            <?php if($this->has_nextgen) { ?>
            <tr>
                <th scope="row"><?php esc_html_e('NextGen','shortpixel-image-optimiser');?></th>
                <td>
									<div class='switch_button'>
										<label>
                    	<input name="includeNextGen" type="checkbox" id="nextGen" value='1' <?php echo  checked($view->data->includeNextGen,'1' );?>>

										<div class="the_switch">&nbsp; </div>
										<?php esc_html_e('Optimize NextGen galleries.','shortpixel-image-optimiser');?>
									</label>
								</div>

                </td>
            </tr>
            <?php } ?>
            <tr>
                <th scope="row"><?php esc_html_e('Optimize PDFs','shortpixel-image-optimiser');?></th>
                <td>
                    <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/520-settings-optimize-pdfs"></span></div>
									 <div class='switch_button'>

										 <label>
											 <input type="checkbox" class="switch" name="optimizePdfs" value="1" <?php checked( $view->data->optimizePdfs, "1" );?>>
											 <div class="the_switch">&nbsp; </div>
											 <?php esc_html_e('Also optimize PDF documents.','shortpixel-image-optimiser');?>
										 </label>
									 </div>

                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Optimize Retina images','shortpixel-image-optimiser');?></th>
                <td>
                    <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/518-settings-optimize-retina-images"></span></div>
									 <div class='switch_button'>
										 <label>
											 <input type="checkbox" class="switch" name="optimizeRetina" value="1" <?php checked( $view->data->optimizeRetina, "1" );?>>
											 <div class="the_switch">&nbsp; </div>
											 		<?php esc_html_e('Also optimize the Retina images (@2x) if they exist.','shortpixel-image-optimiser');?>
								 			</label>
									 </div>
                </td>
            </tr>

            <?php if (true === $this->disable_heavy_features): ?>
            <tr class="heavy-feature-virtual retina view-notice-row">
              <th scope="row">&nbsp;</th>
              <td>
                <div class='heavy-feature-virtual warning view-notice'>
                  <p><?php printf(esc_html__('This feature has been disabled in offload mode for performance reasons. You can enable it again with a %s filter hook %s ', 'shortpixel-image-optimiser' ),'<a target="_blank" href="https://shortpixel.com/knowledge-base/article/577-performance-improvement-shortpixel-image-optimization-media-offload-plugin">', '</a>'); ?></p>
                </div>
              </td>
            </tr>
          <?php endif; ?>

            <tr>
                <th scope="row"><?php esc_html_e('Optimize other thumbnails','shortpixel-image-optimiser');?></th>
                <td>
                    <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/519-settings---optimize-other-thumbs"></span></div>
									 <div class='switch_button'>
										 <label>
											 <input type="checkbox" class="switch" name="optimizeUnlisted" value="1" <?php checked( $view->data->optimizeUnlisted, "1" );?>>
											 <div class="the_switch">&nbsp; </div>
													<?php esc_html_e('Also optimize unlisted thumbnails, if found.','shortpixel-image-optimiser');?>
											</label>
									 </div>
                </td>
            </tr>

            <?php if (true === $this->disable_heavy_features): ?>
            <tr class="heavy-feature-virtual unlisted view-notice-row ">
              <th scope="row">&nbsp;</th>
              <td>
                <div class='heavy-feature-virtual warning view-notice'>
                  <p><?php printf(esc_html__('This feature has been disabled in offload mode for performance reasons. You can enable it again with a %s filter hook %s ', 'shortpixel-image-optimiser' ),'<a target="_blank" href="https://shortpixel.com/knowledge-base/article/577-performance-improvement-shortpixel-image-optimization-media-offload-plugin">', '</a>'); ?></p>
                </div>
              </td>
            </tr>
          <?php endif; ?>

            <tr>
                <th scope="row"><?php esc_html_e('Convert PNG images to JPEG','shortpixel-image-optimiser');?></th>
                <td>
                    <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/516-settings-convert-png-images-to-jpeg"></span></div>
									 <div class='switch_button option-png2jpg'>
										 <label>
											 <input type="checkbox" class="switch" name="png2jpg" value="1" <?php checked( ($view->data->png2jpg > 0), true);?> <?php echo($this->is_gd_installed ? '' : 'disabled') ?> data-toggle="png2jpgforce">
											 <div class="the_switch">&nbsp; </div>
											 <?php esc_html_e('Automatically convert the PNG images to JPEG, if possible.','shortpixel-image-optimiser'); ?>
										 </label>
									 </div>

								 <?php  if(!$this->is_gd_installed):
								  ?>
									 <div style="color:red;"><?php esc_html_e('You need PHP GD with support for JPEG and PNG files for this feature. Please ask your hosting 	provider to install it.','shortpixel-image-optimiser');  ?>
									 </div>
								 <?php endif; ?>


										<div class='switch_button option-png2jpgforce toggleTarget suboption' id="png2jpgforce">
											<p>&nbsp;</p>
											<label>
												<input type="checkbox" class="switch" name="png2jpgForce" value="1" <?php checked(($view->data->png2jpg > 1), true);?> <?php echo($this->is_gd_installed ? '' : 'disabled') ?>>
												<div class="the_switch">&nbsp; </div>
												<?php esc_html_e('Also force the conversion of images with transparency (the transparency will be lost).','shortpixel-image-optimiser'); ?>
											</label>
										</div>

                </td>
            </tr>
            <tr class='exif_warning view-notice-row'>
                <th scope="row">&nbsp;</th>
                <td>
                   <div class='view-notice warning'><p><?php printf(esc_html__('Warning - Converting from PNG to JPG will %s not %s keep the EXIF information!', 'shortpixel-image-optimiser'), "<strong>","</strong>"); ?></p></div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('CMYK to RGB conversion','shortpixel-image-optimiser');?></th>
                <td>
                    <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/517-settings---cmyk-to-rgb-conversion"></span></div>
									 <div class='switch_button'>
										 <label>
											 <input type="checkbox" class="switch" name="cmyk2rgb" value="1" <?php checked( $view->data->CMYKtoRGBconversion, "1" );?>>
											 <div class="the_switch">&nbsp; </div>
											 <?php esc_html_e('Adjust your images\' colors for computer and mobile displays.','shortpixel-image-optimiser');?>
										 </label>
									 </div>

                </td>
            </tr>
            <tr>
                <th scope="row"><label for="excludeSizes"><?php esc_html_e('Exclude thumbnail sizes','shortpixel-image-optimiser');?></label></th>
                <td>
                    <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/113-how-can-i-optimize-only-certain-thumbnail-sizes"></span></div>
									<div class="option-content">

                    <?php
                    foreach($view->allThumbSizes as $sizeKey => $sizeVal) {
										?>
                        <span class="excludeSizeOption">
                          <label>

														<?php
                            $excludeSizes = property_exists($view->data, 'excludeSizes') ? $view->data->excludeSizes : array();
														$checked = in_array($sizeKey, $excludeSizes) ? 'checked' : '';
														$width = isset($sizeVal['width']) ? $sizeVal['width'] : '*';
														$height = isset($sizeVal['height']) ? $sizeVal['height'] : '*';

                            $name = isset($sizeVal['nice-name']) ? $sizeVal['nice-name'] : ucfirst($sizeKey);
														$label = $name . " ( $width &times $height )";

														printf(' <input name="excludeSizes[]" type="checkbox" id="excludeSizes_%s" value="%s" %s>%s  ', esc_attr($sizeKey), esc_attr($sizeKey), $checked, $label);
														?>
                            </label>
                        </span>

								    <?php } // exclude sizes ?>
									</div>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="excludePatterns"><?php esc_html_e('Exclude patterns','shortpixel-image-optimiser');?></label></th>
                <td>
			<div class="option-content">
				<div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/88-how-to-exclude-images-from-being-optimized"></span></div>

				<p class="settings-info" data-toggle="exclude-settings-expanded">

          <button class='button button-primary new-exclusion-button' type='button' name="addNewExclusion">
            <?php _e('Add new Exclusion', 'shortpixel-image-optimiser'); ?>
          </button>

						<?php
						printf(esc_html__('Use this section to exclude images based on patterns. There are three types of exclusions: based on the file name, on the file path or on the file size. Each exclusion type can be applied to: all images and thumbnails of that image (including the scaled or original image), only thumbnails (in this case the original and scaled images are not excluded), only Custom Media images (in this case the items from the Media Library are not excluded) or only for a selection of thumbnails of your choice. Examples can be found in the fold-out area below.','shortpixel-image-optimiser'),
							'<b>','</b>',
							'<b>','</b>'
						);
            ?>

			 </p>

       <p  class="settings-info">
           <label><input type='checkbox' class='shortpixel-hide' data-toggle='exclude-settings-expanded'> >> <?php		printf(esc_html__('See examples')); ?></label>
        </p>

        <div class='exclude-settings-expanded toggleTarget ' id="exclude-settings-expanded">
          <p  class="settings-info">
          <?php
              printf(esc_html__('For the %s"Name"%s type, only the file name is matched, i.e. if you enter %s"flower.jpg"%s in the "Value" field, ShortPixel excludes all JPEG images ending in "flower" (lower case). If, on the other hand, you enter %s"logo"%s in the "Value" field, all images – PNG/JPEG/GIF – that contain the word "logo" in their name will be excluded: "nicelogo.jpg", "alllogos.png", "logo.gif"..', 'shortpixel-image-optimiser'),
              '<b>','</b>',
              '<b>','</b>',
              '<b>','</b>'
              );
          ?>

        </p>
        <br />
        <p  class="settings-info">
          <?php
              printf(esc_html__('With the %s"Path"%s type, the entire path is matched (useful for excluding certain (sub)directories altogether). For example, if you enter %s"2022"%s in the "Value" field, all images uploaded in 2022 will be excluded, but also images that contain 2022 in the file name (as this is also part of the path). If you only want to exclude images uploaded in 2022, enter %s"/2022/"%s instead.','shortpixel-image-optimiser'),
              '<b>','</b>',
              '<b>','</b>',
              '<b>','</b>'
              );
              ?>
            </p>
            <br />
            <p  class="settings-info">
          <?php
              printf(esc_html__('For both types mentioned above ("Name" and "Path") you can activate the option %s"Check as regular expression"%s. It works in the same way, but requires a valid regular expression between slashes in the "Value" field. Special characters should be preceded by a \ as an escape character. For example, %s/[0-9]+[^\/]*\.(PNG|png)/%s in the "Value" field for the "Name" type excludes all PNG images that have a numeric prefix.','shortpixel-image-optimiser'),
              '<b>','</b>',
              '<b>','</b>'
            );
            ?>
          </p>
          <br />
          <p  class="settings-info">
            <?php
              printf(esc_html__('The %s"Size"%s type is applied to all images and thumbnails whose size is within the specified range. You can either use intervals or specify an exact size if you enable the %s"Exact sizes"%s option.','shortpixel-image-optimiser'),
              '<b>','</b>',
              '<b>','</b>'
            );
            ?>
          </p>

      </div>


<?php
 $exclusions = UtilHelper::getExclusions();
		$excludeArray = $exclusions; //(strlen($excludePatterns) > 0) ? explode(',', $excludePatterns) : array();

		if (is_array($excludeArray) && count($excludeArray) > 0)
		{
				echo "<ul class='exclude-list'>";
        echo '<input type="hidden" id="new-exclusion-index" name="new-index" value="' . (count($excludeArray)  -1) . '">';
        $i = 0;
				foreach($excludeArray as $index => $option)
				{
            $exclude_id  = 'id="exclude-' . $i . '"';
						$type = $option['type'];
						$value = $option['value'];
						$apply = $option['apply'];
            $thumblist = isset($option['thumblist']) ? $option['thumblist'] : array();
            $hasError = (isset($option['has-error']) && true == $option['has-error']) ? true : false;

						$option_code = json_encode($option);

            $typeStrings  = UiHelper::getSettingsStrings('exclusion_types');
            $applyStrings = UiHelper::getSettingsStrings('exclusion_apply');


            $apply_name = isset($applyStrings[$apply]) ? $applyStrings[$apply] : '';

						switch($type)
						{
							 case 'name':
               case 'regex-name':
							 	 $field_name = $typeStrings['name'];
							 break;
							 case 'path':
               case 'regex-path':
							 	$field_name = $typeStrings['path']; // __('Path', 'shortpixel-image-optimiser');
							 break;
               case 'size':
                 $field_name = $typeStrings['size']; // __('Size', 'shortpixel-image-optimiser');
               break;
							 default:
							 	 $field_name = __('Unknown', 'shortpixel-image-optimiser');
							 break;
						}


            $classes = array();
            if (true === $hasError)
            {
               $classes[] = 'has-error';
            }

            if (strpos($type, 'regex') !== false)
            {
                $classes[] = 'is-regex';
            }

            $class = '';
            if (count($classes) > 0)
            {
               $class = 'class="' . implode(' ', $classes) . '"';
            }


            $title = '';
            if ('selected-thumbs' == $apply)
            {
               $thumbTitles = array();
               foreach($thumblist as $thumbName)
               {
                  $thumb = $view->allThumbSizes[$thumbName];
                  $thumbTitles[] = (isset($thumb['nice-name'])) ? $thumb['nice-name'] : $thumbName;
               }
               $title = 'title="' . implode(', ', $thumbTitles) . '"';
            }


						echo "<li $class $title $exclude_id>";

						echo "<input type='hidden' name='exclusions[]' value='$option_code' />";
						echo "<span>$field_name :</span>
                  <span>$value</span>";
            echo "<span>$apply_name</span>";

						echo "</li>";
            $i++;
				}
				echo "</ul>";
		}
    else {
      echo '<input type="hidden" id="new-exclusion-index" name="new-index" value="0">';

       echo '<ul class="exclude-list"><li class="no-exclusion-item">' . __('No exclusions', 'shortpixel-image-optimiser') . '</li></ul>';
    }

?>

</div> <!-- option-content -->

					  <div class='new-exclusion not-visible'>
                <input type="hidden" name="edit-exclusion" value="">
								<h3 class='new-title not-visible'><?php _e('New Exclusion' ,'shortpixel-image-optimiser'); ?></h3>
                <h3 class='edit-title not-visible'><?php _e('Edit Exclusion' ,'shortpixel-image-optimiser'); ?></h3>
								<div>
									<label><?php _e('Type:', 'shortpixel-image-optimiser'); ?></label>
									 <select name="exclusion-type" class='new-exclusion-type'>
											<option value='name'><?php _e('Name', 'shortpixel-image-optimiser'); ?></option>
											<option value='path' data-example="/path/"><?php _e('Path', 'shortpixel-image-optimiser'); ?></option>
											<option value='size' data-example="widthXheight-widthXheight"><?php _e('Size', 'shortpixel-image-optimiser'); ?></option>

									</select>

								</div>

                <div class='regex-option'>
                  <label>&nbsp;</label>
                  <div class='switch_button'>
                    <label>
                      <input type="checkbox" class="switch" name="exclusion-regex">
                      <div class="the_switch">&nbsp; </div>
                      <?php esc_html_e('Check as regular expression','shortpixel-image-optimiser');?>
                    </label>
                  </div>
                </div>

								<div class='value-option '>
									<label><?php _e('Value:', 'shortpixel-image-optimiser'); ?></label>
									<input type="text" name="exclusion-value" value="">
								</div>

                <div class='size-option not-visible'>
                    <div class='exact-option'>
                      <label>&nbsp;</label>
                      <div class='switch_button'>
                        <label>
                          <input type="checkbox" class="switch" name="exclusion-exactsize">
                          <div class="the_switch">&nbsp; </div>
                          <?php esc_html_e('Exact sizes','shortpixel-image-optimiser');?>
                        </label>
                      </div>
                    </div>

                    <div class='size-option-range'>
                      <div class='width'>
											    <label><?php _e('Width between:', 'shortpixel-image-optimiser'); ?></label>
                          <input type="number" class='small' name="exclusion-minwidth" value="">px -
                          <input type="number" class='small' name="exclusion-maxwidth" value="">px
                      </div>
                      <div class='height'>
                          <label><?php _e('Height between:', 'shortpixel-image-optimiser'); ?></label>
                          <input type="number" class='small' name="exclusion-minheight" value="">px -
                          <input type="number" class='small' name="exclusion-maxheight" value="">px
                      </div>
                    </div>

                    <div class='size-option-exact not-visible'>
                      <div class='exact'>
                        <label>
                          <?php _e('Exact size:', 'shortpixel-image-optimiser'); ?></label>
                          <input type="number" class='small' name="exclusion-width" value="">px x
                          <input type="number" class='small' name="exclusion-height" value="">px
                       </div>
										</div>
                </div>

								<div>
									<label><?php _e('Apply To:', 'shortpixel-image-optimiser'); ?></label>
									<select name='apply-select' class='thumbnail-type-option'>
											<option value='all'><?php _e('All Images', 'shortpixel-image-optimiser'); ?></option>
											<option value='only-thumbs'><?php _e('Only Thumbnails','shortpixel-image-optimiser'); ?>
                      </option>
                      <option value='only-custom'><?php _e('Only Custom Media images', 'shortpixel-image-optimiser'); ?>
                      </option>
                      <option value='selected-thumbs'><?php _e('Selected thumbnails', 'shortpixel-image-optimiser'); ?></option>
                  </select>

                  <select multiple="multiple" name='thumbnail-select' class='not-visible thumbnail-option'>
											<?php foreach($view->allThumbSizes as $name => $data)
											{
                          $nice_name = isset($data['nice-name']) ? $data['nice-name'] : $name;
													echo "<option value='$name'>$nice_name</option>";
											} ?>
									</select>

								</div>
								<div class='button-actions'>
                  <button type="button" class="button" name='cancelEditExclusion'><?php _e('Close', 'shortpixel-image-optimiser'); ?></button>

									<button type="button" class="button button-primary not-visible" name="addExclusion">
                    <?php _e('Add Exclusion', 'shortpixel-image-optimiser'); ?></button>

                    <button type="button" class="button button-primary not-visible" name="updateExclusion">
                        <?php _e("Update", 'shortpixel-image-optimiser');  ?>
                    </button>

                  <button type="button" class="button button-primary not-visible" name="removeExclusion">
                      <?php _e("Remove", 'shortpixel-image-optimiser');  ?>
                  </button>

								</div>
							</div> <!-- new exclusion -->

              <p class='exclusion-save-reminder hidden'><?php _e('Reminder: Save the settings for the exclusion changes to take effect!', 'shortpixel-image-optimiser'); ?></p>

                </td>
            </tr> <!--- exclusions -->


            <tr>
                <th scope="row"><label for="additional-media"><?php esc_html_e('Custom Media folders','shortpixel-image-optimiser');?></label></th>
                <td>
									<div class='switch_button'>
										<label>
											<input type="checkbox" class="switch" name="showCustomMedia" value="1" <?php checked( $view->data->showCustomMedia, "1" );?>>
											<div class="the_switch">&nbsp; </div>
											<?php esc_html_e('Show Custom Media menu item','shortpixel-image-optimiser');?>
										</label>
									</div>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="authentication"><?php esc_html_e('HTTP AUTH credentials','shortpixel-image-optimiser');?></label></th>
                <td>
									<?php if (! defined('SHORTPIXEL_HTTP_AUTH_USER')): ?>
		                  <input name="siteAuthUser" type="text" id="siteAuthUser" value="<?php echo( esc_html(wp_unslash($view->data->siteAuthUser )));?>" class="regular-text" placeholder="<?php esc_html_e('User','shortpixel-image-optimiser');?>" style="margin-bottom: 8px"><br>
	                    <input name="siteAuthPass" type="text" id="siteAuthPass" value="<?php echo( esc_html(wp_unslash($view->data->siteAuthPass )));?>" class="regular-text" placeholder="<?php esc_html_e('Password','shortpixel-image-optimiser');?>" style="margin-bottom: 8px">
	                    <p class="settings-info">
	                        <?php printf(esc_html__('Only fill in these fields if your site (front-end) is not publicly accessible and visitors need a user/pass to connect to it.
                                    If you don\'t know what is this then just %sleave the fields empty%s.','shortpixel-image-optimiser'), '<strong>', '</strong>'); ?>
	                    </p>
									<?php else:  ?>
												<p><?php esc_html_e('The HTTP AUTH credentials have been defined in the wp-config file.', 'shortpixel-image-optimiser'); ?></p>
									<?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
    <p class="submit">
        <input type="submit" name="save" id="saveAdv" class="button button-primary" title="<?php esc_attr_e('Save Changes','shortpixel-image-optimiser');?>" value="<?php esc_attr_e('Save Changes','shortpixel-image-optimiser');?>"> &nbsp;
        <input type="submit" name="save_bulk" id="bulkAdvGo" class="button button-primary" title="<?php esc_attr_e('Save and go to the Bulk Processing page','shortpixel-image-optimiser');?>" value="<?php esc_attr_e('Save and Go to Bulk Process','shortpixel-image-optimiser');?>"> &nbsp;
    </p>
    </div>
    <script>
		<!-- @todo // Inline JS -->
        jQuery(document).ready(function () { ShortPixel.setupAdvancedTab();});
    </script>
</section>
