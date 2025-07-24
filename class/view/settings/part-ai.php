<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

?>


<section id="tab-ai" class="<?php echo ($this->display_part == 'ai') ? 'active setting-tab' :'setting-tab'; ?>" data-part="ai" >

<settinglist>

  <h2><?php esc_html_e('AI by ShortPixel','shortpixel-image-optimiser');?></h2>

  <setting class='switch'>
    <content>

      <?php $this->printSwitchButton(
            ['name' => 'enable_ai',
             'checked' => $view->data->enable_ai,
             'label' => esc_html__('Enable AI','shortpixel-image-optimiser'),
            ]);
      ?>

      <i class='documentation dashicons dashicons-editor-help' data-link="-todo-"></i>
      <name>

        <?php esc_html_e('Show AI options throughout ShortPixel Image Optimiser','shortpixel-image-optimiser');?>

      </name>
    </content>
  </setting>

  <setting class='switch'>
    <content>

      <?php $this->printSwitchButton(
            ['name' => 'autoAI',
             'checked' => $view->data->autoAI,
             'label' => esc_html__('Auto AI','shortpixel-image-optimiser'),
            ]);
      ?>

      <i class='documentation dashicons dashicons-editor-help' data-link="-todo-"></i>
      <name>

        <?php esc_html_e('Automatically add alt and descriptions when uploading the image','shortpixel-image-optimiser');?>

      </name>
    </content>
  </setting>

  <setting class='textarea'>      
        <content>
             <name><?php _e('General site context', 'shortpixel-image-optimiser'); ?></name>
             <info>&nbsp;</info>
             <textarea class="ai_general_context" name="ai_general_context"><?php echo $view->data->ai_general_context; ?></textarea>
        </content>
        
    </setting>

    <setting class='switch'>
        <content>
          <?php $this->printSwitchButton(
            [
              'name' => 'ai_use_post',
              'checked' => $view->data->ai_use_post,
              'label' => esc_html__('Use connected Post / Page for AI', 'shortpixel-image-optimiser')
            ]
          );
          ?>

          <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/settings-optimize-thumbnails/?target=iframe"></i>

        </content>
      </setting>

      <hr>

  </settinglist>

  <settinglist class="generate_ai_items">

  <gridbox class="width_half">

<!-- AI Gen ALT -->
<setting class='switch' >
  <content>
    <?php $this->printSwitchButton(
      [
        'name' => 'ai_gen_alt',
        'checked' => $view->data->ai_gen_alt,
        'label' => esc_html__('Generate Alt Tag', 'shortpixel-image-optimiser'),
        'data' => ['data-toggle="ai_gen_alt"'], 
      ]
    );
    ?>

    <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/settings-optimize-thumbnails/?target=iframe"></i>

  </content>

  
  <content class='toggleTarget ai_gen_alt is-advanced'>
    <?php 
    $input = "<input type='number' name='ai_limit_alt_chars' value='" . $view->data->ai_limit_alt_chars . "'>";
    ?>
    <name><?php printf(__('Limit ALT Tag to %s characters', 'shortpixel-image-optimiser'), $input); ?></name>
  </content>

  <content class='toggleTarget ai_gen_alt is-advanced'>
    <name> <?php _e('Additional context for ALT Tags', 'shortpixel-image-optimiser'); ?></name>
    <textarea name="ai_alt_context"><?php echo $view->data->ai_alt_context ?></textarea>
  </content>

</setting>

<!-- Ai Gen Description -->
<setting class='switch'>
  <content>
    <?php $this->printSwitchButton(
      [
        'name' => 'ai_gen_description',
        'checked' => $view->data->ai_gen_description,
        'label' => esc_html__('Generate Image Description', 'shortpixel-image-optimiser'),
        'data' => ['data-toggle="ai_gen_description"'], 
      ]
    );
    ?>

    <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/settings-optimize-thumbnails/?target=iframe"></i>

  </content>

  <content class='toggleTarget ai_gen_description'>
    <?php 
    $input = "<input type='number' name='ai_limit_description_chars' value='" . $view->data->ai_limit_description_chars . "'>"; 
    ?>
    <name><?php printf(__('Limit Image Description to %s characters', 'shortpixel-image-optimiser'), $input); ?></name>
  </content>
  
  <content class='toggleTarget ai_gen_description'>
    <name> <?php _e('Additional context for image description', 'shortpixel-image-optimiser'); ?></name>
    <textarea name='ai_description_context'><?php echo $view->data->ai_description_context ?></textarea>
  </content>


</setting>

<!-- Ai Gen Caption --> 
<setting class='switch'>
  <content>
    <?php $this->printSwitchButton(
      [
        'name' => 'ai_gen_caption',
        'checked' => $view->data->ai_gen_caption,
        'label' => esc_html__('Generate Image Caption', 'shortpixel-image-optimiser'),
        'data' => ['data-toggle="ai_gen_caption"'], 

      ]
    );
    ?>

    <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/settings-optimize-thumbnails/?target=iframe"></i>

  </content>

  <content class='toggleTarget ai_gen_caption'>
    <?php 
    $input = '<input type="number" name="ai_limit_caption_chars" value="' . $view->data->ai_limit_caption_chars . '">';
    ?>
    <name><?php printf(__('Limit Image Description to %s characters', 'shortpixel-image-optimiser'), $input); ?></name>
  </content>

  
  <content class='toggleTarget ai_gen_caption'>
    <name> <?php _e('Additional context for image caption', 'shortpixel-image-optimiser'); ?></name>
    <textarea name='ai_caption_context'><?php echo $view->data->ai_caption_context ?></textarea>
  </content>

</setting>

<setting>
  <content>
  
  <?php $this->printSwitchButton(
      [
        'name' => 'ai_gen_filename',
        'checked' => $view->data->ai_gen_filename,
        'label' => esc_html__('Update image filename with SEO-Friendly one', 'shortpixel-image-optimiser')
      ]
    );
  ?>
  </content>

  <content class='nextline'>
    <?php 
    $input  = '<input type="number" name="ai_limit_filename_chars" value="' . $view->data->ai_limit_filename_chars . '">';
    ?>
    <name><?php printf(__('Limit filename to %s characters ', 'shortpixel-image-optimiser'), $input); ?></name>
  </content>

  <content class='nextline'>
    <name><?php _e('Additional context for filename : ', 'shortpixel-image-optimiser'); ?></name>
    <textarea name="ai_filename_context"><?php echo $view->data->ai_filename_context ?></textarea>
    
  </content>

  <content class='nextline'>
    <?php $this->printSwitchButton(
        [
          'name' => 'ai_filename_prefercurrent',
          'checked' => $view->data->ai_filename_prefercurrent,
          'label' => esc_html__('Prefer keeping current filename if relevant', 'shortpixel-image-optimiser')
        ]
      );
    ?>
  </content>


  </gridbox>
  </settinglist>

    <setting class='switch'>
        
        <content>
        <name><?php //_e('Use image EXIF data', 'shortpixel-image-optimiser'); ?></name>
        <?php $this->printSwitchButton(
            [
              'name' => 'ai_use_exif',
              'checked' => $view->data->ai_use_exif,
              'label' => esc_html__('Take into account image Exif data', 'shortpixel-image-optimiser')
            ]
          );
        ?>
        </content>
    </setting>

    <setting>
          <content>
          <name><?php _e('Language', 'shortpixel-image-optimiser'); ?>
            <?php 
              wp_dropdown_languages([
                  'name' => 'ai_language', 
                  'selected' => $view->data->ai_language, 
                  'translations' => $view->languages, 
                  'languages' => get_available_languages(),
                  'explicit_option_en_us' => true,
              ]);
              ?>
          </name>
          </content>
    </setting>

</settinglist>

<settingslist class='preview_wrapper'>
    <input type="hidden" name="ai_preview_image_id" value="" />
    <div class='ai_preview'>
        <gridbox class='width_half'>
          <span><img src="" class='image_preview'></span>
          <span><i class='shortpixel-icon eye'></i> Ai Image Seo Preview  <i class='shortpixel-icon ai'></i>
            <p>This is a preview - no data will be saved</p>
            <p>
              <button type='button' name='open_change_photo'><i class='shortpixel-icon optimization'></i> Change photo</button> 
              <button type='button' name='refresh_ai_preview'><i class='shortpixel-icon refresh'></i> Refresh with latest settings</button>
            </p>
        </gridbox>
    </div>
    <hr>
    <gridbox class='width_two_with_middle result_wrapper'>
        <div class='current result_info'>
            <h3>Current Seo Data</h3>
            <ul>
              <li><label>Image FileName:</label> <span class='filename'>Example.jpg</span>
            </li>
            <li><label>Image SEO ALt Tag:</label> <span class='alt'>Alt</span></li>
            <li><label>Caption:</label> <span class='caption'>Caption</span></li>
            <li><label>Image description:</label> <span class='description'>Loerum Ipsum</span>
            </li>
            </ul>
        </div>      
        <div class='icon' ><i class='shortpixel-icon chevron rotate_right'></i>&nbsp;</div>
        <div class='result result_info'>
            <h3>Ai Image Seo Result </h3>
            <ul>
            <li><label>Image FileName:</label> <span class='filename'>Example.jpg</span>
            </li>
            <li><label>Image SEO ALt Tag:</label> <span class='alt'>Alt</span></li>
            <li><label>Caption:</label> <span class='caption'>Caption</span></li>
            <li><label>Image description:</label> <span class='description'>Loerum Ipsum</span>
             </ul>
        </div>

    </gridbox>

</settingslist>


<?php $this->loadView('settings/part-savebuttons', false); ?>

</section>