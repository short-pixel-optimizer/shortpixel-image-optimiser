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

  <setting>      
        <content>
             <name><?php _e('General Context', 'shortpixel-image-optimiser'); ?></name>
             <textarea class="" name="ai_general_context"><?php echo $view->data->ai_general_context; ?></textarea>
             <info>Info</info>
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
          <name>
            <?php printf(esc_html__('[name]', 'shortpixel-image-optimiser')); ?>
          </name>
          <info>
            <?php printf(esc_html__('Desc %s', 'shortpixel-image-optimiser'), '<br>'); ?>
          </info>
        </content>
      </setting>

      <hr>

  </settinglist>

  <gridbox class="width_half">


<!-- AI Gen ALT -->
<setting class='' >
  <content class='switch'>
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
    <name>
      <?php printf(esc_html__('Apply compression to image thumbnails', 'shortpixel-image-optimiser')); ?>
    </name>
    <info>
      <?php printf(esc_html__('--- %s', 'shortpixel-image-optimiser'), '<br>'); ?>
    </info>
  </content>

  
  <content class='toggleTarget ai_gen_alt'>
    <name ><?php _e('Limit ALT Tag', 'shortpixel-image-optimiser'); ?></name>
    <input type="number" name="ai_limit_alt_chars" value="<?php echo $view->data->ai_limit_alt_chars ?>">
  </content>

  <content class='toggleTarget ai_gen_alt'>
    <name> <?php _e('Additional context for ALT Tags', 'shortpixel-image-optimiser'); ?></name>
    <input type="text" name='ai_alt_context' value='<?php echo $view->data->ai_alt_context ?>'>
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
    <name>
      <?php printf(esc_html__('-', 'shortpixel-image-optimiser')); ?>
    </name>
    <info>
      <?php printf(esc_html__('- %s', 'shortpixel-image-optimiser'), '<br>'); ?>
    </info>
  </content>

  <content class='toggleTarget ai_gen_description'>
    <name><?php _e('Limit Description', 'shortpixel-image-optimiser'); ?></name>
    <input type="number" name="ai_limit_description_chars" value="<?php echo $view->data->ai_limit_description_chars ?>">
  </content>

  
  <content class='toggleTarget ai_gen_description'>
    <name> <?php _e('Additional context for Description', 'shortpixel-image-optimiser'); ?></name>
    <input type="text" name='ai_description_context' value='<?php echo $view->data->ai_description_context ?>'>
  </content>


</setting>

<hr>

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
    <name>
      <?php printf(esc_html__('-', 'shortpixel-image-optimiser')); ?>
    </name>
    <info>
      <?php printf(esc_html__('- %s.', 'shortpixel-image-optimiser'), '<br>'); ?>
    </info>
  </content>

  <content class='toggleTarget ai_gen_caption'>
    <name><?php _e('Limit Description', 'shortpixel-image-optimiser'); ?></name>
    <input type="number" name="ai_limit_caption_chars" value="<?php echo $view->data->ai_limit_caption_chars ?>">
  </content>

  
  <content class='toggleTarget ai_gen_caption'>
    <name> <?php _e('Additional context for Description', 'shortpixel-image-optimiser'); ?></name>
    <input type="text" name='ai_caption_context' value='<?php echo $view->data->ai_caption_context ?>'>
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
    <name><?php _e('Limit filename to : ', 'shortpixel-image-optimiser'); ?></name>
    <input type="number" name="ai_limit_filename_chars" value="<?php echo $view->data->ai_limit_filename_chars ?>" />
  </content>

  <content class='nextline'>
    <name><?php _e('Additional context for filename : ', 'shortpixel-image-optimiser'); ?></name>
    <input type="text" name="ai_filename_context" value="<?php echo $view->data->ai_filename_context ?>" />
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
</setting>

  <gridbox class="width_half">

  <settinglist>




    <h3><?php _e('What to generate', 'shortpixel-image-optimiser'); ?></h3>
  </settinglist>
  <settinglist>
          <h3>Example based on settings</h3>
          <?php echo $view->latest_ai ?>

  </settinglist>
  </gridbox>



    <setting class='switch'>
        
        <content>
        <name><?php _e('Use image EXIF data', 'shortpixel-image-optimiser'); ?></name>
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
          <name><?php _e('Language', 'shortpixel-image-optimiser'); ?></name>
            <?php 
              wp_dropdown_languages([
                  'name' => 'ai_language', 
                  'selected' => $view->data->ai_language, 
                  'translations' => $view->languages, 
                  'languages' => get_available_languages(),
                  'explicit_option_en_us' => true,
              ]);
              ?>
          
          </content>
    </setting>

</settinglist>

<?php $this->loadView('settings/part-savebuttons', false); ?>

</section>