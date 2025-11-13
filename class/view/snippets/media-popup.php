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
$previewImage = $this->data['previewImage'];
$fileName = $originalImage->getFileName();
$placeholderImage = $this->data['placeholderImage'];
$post_title = $this->data['post_title'];
$action_name = $this->data['action_name'];

switch($action_name)
{
	case 'remove': 
		$modal_title = __('Remove background', 'shortpixel-image-optimiser');
		$suggesteFileName = $originalImage->getFileBase() . '_nobg.' . $originalImage->getExtension(); 

	break; 

	case 'scale': 
		$modal_title = __('Scale Image', 'shortpixel-image-optimiser'); 
		$suggesteFileName = $originalImage->getFileBase() . '_upscale.' . $originalImage->getExtension(); 

	break; 
}
?>

<div class="modal-wrapper" id="media-modal" data-item-id="<?php echo $this->data['item_id'] ?>" data-action-name="<?php echo $action_name ?>" >
    <div class="title"><h3><?php echo $modal_title ?> <span data-action='close'>X</span></h3> </div>
	<div class='modal-content-wrapper'>

    <div class="image-wrapper">
            <div class="image-original">
                <i style="background-image: url('<?php echo $previewImage->getURL(); ?>');"></i>
				<span><?php _e('Before', 'shortpixel-image-optimiser'); ?>
            </div>
			<div class="image-arrow">
				<i class='shortpixel-icon arrow-right'></i>
			</div>
            <div class="image-preview">
				<span><?php _e('After', 'shortpixel-image-optimiser'); ?></span>
                <i data-placeholder="<?php echo $placeholderImage ?>" style="background-image: url('<?php echo $placeholderImage ?>');" ></i>
				<div class='error-message shortpixel-hide'>&nbsp;</div>
                <div class='load-preview-spinner'><img class='loadspinner' src="<?php echo esc_url(\wpSPIO()->plugin_url('res/img/bulk/loading-hourglass.svg')); ?>" /></div>
            </div>
    </div>

    <div class='action-bar'>

    <section class="remove action_wrapper">
		<h3><?php _e("Options", 'shortpixel-image-optimiser'); ?></h3>	
		<p><?php __('Note: transparency options only work with supported file formats, such as PNG', 'shortpixel-image-optimiser'); ?></p>

						<label for="transparent_background">
							<input id="transparent_background" type="radio" name="background_type" value="transparent" <?php checked('transparent', $view->settings['bg_type']); ?> checked >
							<?php esc_html_e('Transparent/white background', 'shortpixel-image-optimiser'); ?>
						</label>
						<p class="howto">
							<?php esc_html_e('Returns a transparent background if it is a PNG image, or a white one if it is a JPG image.', 'shortpixel-image-optimiser'); ?>
						</p>

                        <label for="solid_background">
							<input id="solid_background" type="radio" name="background_type" value="solid" <?php checked('solid', $view->settings['bg_type']); ?>>
							<?php esc_html_e('Solid background', 'shortpixel-image-optimiser'); ?>
						</label>
						<div id="solid_selector">
							<label for="bg_display_picker">
								<p><?php esc_html_e('Background Color:','shortpixel-image-optimiser'); ?> <strong>
									<span style="text-transform: uppercase;" id="color_range">
										<?php echo esc_attr($view->settings['bg_color']); ?></span>
									</strong>
								<input type="color" value="<?php echo esc_attr($view->settings['bg_color']); ?>" name="bg_display_picker" id="bg_display_picker" />
								<input type="hidden"  value="<?php echo esc_attr($view->settings['bg_color']); ?>" name="bg_color" id="bg_color" />
								</p>
							</label>
						
							<label for="bg_transparency">
								<p><?php esc_html_e('Opacity:', 'shortpixel-image-optimiser'); ?>
									<strong>
										<span id="transparency_range"><?php echo esc_attr($view->settings['bg_transparency']); ?></span>%</strong>
								</p>
								<input type="range" min="0" max="100" value="<?php echo esc_attr($view->settings['bg_transparency']); ?>" id="bg_transparency" />
							</label>  
						</div>



		</section>

		<section class="scale action_wrapper">
			<h3><?php _e("Options", 'shortpixel-image-optimiser'); ?></h3>
			<h4><?php _e('Scale image', 'shortpixel-image-optimiser'); ?></h4>
			<ul>
				<li><input type="radio" name="scale" value="2" checked> <?php _e('2x', 'shortpixel-image-optimiser'); ?></li>
				<li><input type="radio" name="scale" value="3"> <?php _e('3x', 'shortpixel-image-optimiser'); ?></li>
				<li><input type="radio" name="scale" value="4"> <?php _e('4x', 'shortpixel-image-optimiser'); ?></li>
			</ul>
		</section>

		<section class='new_file_title wrapper'>
			<span>
				<p><?php _e('New File Name', 'shortpixel-image-optimiser'); ?></p>
				<input type="text" name="new_filename" value="<?php echo $suggesteFileName ?>">
			</span>

			<span>	
				<p><?php _e('New Image Title', 'shortpixel-image-optimiser'); ?></p>
				<input type="text" name="new_posttitle" value="<?php echo $post_title ?>"> 
			</span>

		</section>

		<section class='filler'></section>

    </div> <!-- // action_bar -->
	<div class='button-wrapper'>
		<span>
			<button class='button' type='button' id='media-get-preview' data-action='media-get-preview'>
			<i class="shortpixel-icon eye"></i>	
			<?php _e('Preview','shortpixel-image-optimiser'); ?>
		</button>
		</span>
		<span>
			<button class='button' type='button button-primary'  id='media-save-button' data-action='media-save-button'>
				<i class="shortpixel-icon save"></i>	
				<?php _e('Save', 'shortpixel-image-optimiser'); ?>
			</button>
			<p><?php _e('A new image will be created', 'shortpixel-image-optimiser'); ?></p>
		</span>
	</div> <!-- button-wrapper -->
</div> <!-- modal-content-wrapper -->
</div> <!-- // modal --> 