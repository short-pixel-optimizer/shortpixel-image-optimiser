<?php 
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

$view->settings = [];
$view->settings['bg_type'] = 'placeholder'; // @todo Add something here.
$view->settings['bg_color'] = '#000';
$view->settings['bg_transparency'] = 80; 


$originalImage = $this->data['originalImage'];
$placeholderImage = $this->data['placeholderImage'];
?>

<div class="modal-wrapper" id="media-modal" data-item-id="<?php echo $this->data['item_id'] ?>" >
    <div class="title"><?php __('Remove background', 'shortpixel-image-optimiser'); ?> <span data-action='close'>X</span></div>

    <div class="image-wrapper">
            <div class="image-original">
                <i style="background-image: url('<?php echo $originalImage->getURL(); ?>');"></i>
            </div>
            <div class="image-preview">
                <i style="background-image: url('<?php echo $placeholderImage ?>');" ></i>
				<div class='error-message shortpixel-hide'>Message in error</div>
                <div class='load-preview-spinner'><img class='loadspinner' src="<?php echo esc_url(\wpSPIO()->plugin_url('res/img/bulk/loading-hourglass.svg')); ?>" /></div>
            </div>

    </div>

    <div class='action-bar'>

    <section class="replace_type wrapper">
						<label for="transparent_background">
							<input id="transparent_background" type="radio" name="background_type" value="transparent" <?php checked('transparent', $view->settings['bg_type']); ?> >
							<?php esc_html_e('Transparent/white background', 'shortpixel-image-optimiser'); ?>
						</label>
						<p class="howto">
							<?php esc_html_e('Returns a transparent background if it is a PNG image, or a white one if it is a JPG image.', 'shortpixel-image-optimiser'); ?>
						</p>

                        <label for="solid_background">
							<input id="solid_background" type="radio" name="background_type" value="solid" <?php checked('solid', $view->settings['bg_type']); ?>>
							<?php esc_html_e('Solid background', 'shortpixel-image-optimiser'); ?>
						</label>
						<p class="howto">
							<?php esc_html_e('If you select this option, the image will have a solid color background and you can choose the color code from the color picker below.', 'shortpixel-image-optimiser'); ?>
						</p>
						<div id="solid_selecter">
							<label for="bg_display_picker">
								<p><?php esc_html_e('Background Color:','shortpixel-image-optimiser'); ?> <strong>
									<span style="text-transform: uppercase;" id="color_range">
										<?php echo esc_attr($view->settings['bg_color']); ?></span>
									</strong>
								</p>
								<input type="color" value="<?php echo esc_attr($view->settings['bg_color']); ?>" name="bg_display_picker" id="bg_display_picker" />
								<input type="hidden"  value="<?php echo esc_attr($view->settings['bg_color']); ?>" name="bg_color" id="bg_color" />
							</label>
							<hr>
							<label for="bg_transparency">
								<p><?php esc_html_e('Opacity:', 'shortpixel-image-optimiser'); ?>
									<strong>
										<span id="transparency_range"><?php echo esc_attr($view->settings['bg_transparency']); ?></span>%</strong>
								</p>
								<input type="range" min="0" max="100" value="<?php echo esc_attr($view->settings['bg_transparency']); ?>" id="bg_transparency" />
							</label>
						</div>
				</section>


        <button class='button' type='button' id='media-get-preview' data-action='media-get-preview'>
			<?php _e('Preview','shortpixel-image-optimiser'); ?>
		</button>
		<button class='button' type='button'  id='media-save-button' data-action='media-save-button'>
			<?php _e('Save', 'shortpixel-image-optimiser'); ?>
			<p><?php _e('A new image will be created', 'shortpixel-image-optimiser'); ?></p>
		</button>
    
    </div>
</div>