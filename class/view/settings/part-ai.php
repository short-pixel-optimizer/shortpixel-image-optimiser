<?php

namespace ShortPixel;

if (! defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

?>
<section id="tab-ai" class="<?php echo ($this->display_part == 'ai') ? 'active setting-tab' : 'setting-tab'; ?>" data-part="ai">

  <settinglist>

    <h2><?php esc_html_e('AI Image SEO & Accessibility', 'shortpixel-image-optimiser'); ?></h2>

    <gridbox class="width_half">

    <setting class='switch'>
      <content>

        <?php $this->printSwitchButton(
          [
            'name' => 'enable_ai',
            'checked' => $view->data->enable_ai,
            'label' => esc_html__('Enable AI Image SEO', 'shortpixel-image-optimiser'),
            'data' => ['data-toggle="autoAiOptions"'],

            ]
        );
        ?>

        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/#0-toc-title?target=iframe"></i>
        <name>

          <?php esc_html_e('Show AI image SEO options throughout ShortPixel Image Optimizer. The generated ALT tag is also very useful for accessibility.', 'shortpixel-image-optimiser'); ?>

        </name>
      </content>
    </setting>

    <setting class='switch toggleTarget autoAiOptions'>
      <content>

        <?php $this->printSwitchButton(
          [
            'name' => 'autoAI',
            'checked' => $view->data->autoAI,
            'label' => esc_html__('Generate image SEO data on upload', 'shortpixel-image-optimiser'),
          ]
        );
        ?>

        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/#1-toc-title?target=iframe"></i>
        <name>

          <?php esc_html_e('Automatically generate image SEO data with AI after uploading the image, based on the settings below.', 'shortpixel-image-optimiser'); ?>

        </name>
      </content>
    </setting>

    <setting class='switch toggleTarget autoAiOptions'>
      <content>

        <?php $this->printSwitchButton(
          [
            'name' => 'autoAIBulk',
            'checked' => $view->data->autoAIBulk,
            'label' => esc_html__('Generate image SEO data during Bulk Processing', 'shortpixel-image-optimiser'),
          ]
        );
        ?>

        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/#2-toc-title?target=iframe"></i>
        <name>

          <?php esc_html_e('Automatically generate image SEO data using AI for all the images queued during the Bulk Processing. The settings below will be taken into account when running the bulk.', 'shortpixel-image-optimiser'); ?>

        </name>
      </content>
    </setting>


    <setting class='switch toggleTarget autoAiOptions'>
      <content>

        <?php $this->printSwitchButton(
          [
            'name' => 'aiPreserve',
            'checked' => $view->data->aiPreserve,
            'label' => esc_html__('Preserve existing Image SEO data', 'shortpixel-image-optimiser'),
          ]
        );
        ?>
        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/#2-toc-title?target=iframe"></i>

        <name>

          <?php esc_html_e('When enabled, all existing ALT tags, captions and descriptions are retained. Disabling the switch means that the SEO data for images created with AI will overwrite the existing data.', 'shortpixel-image-optimiser'); ?>

        </name>
      </content>
    </setting>


    </gridbox>

    <hr>

    <setting class='textarea'>
      <content>
        <name><?php _e('General site context', 'shortpixel-image-optimiser'); ?></name>
        <info><?php _e('This is a general context that will be passed to the AI model to provide more relevant data for your website.', 'shortpixel-image-optimiser'); ?></info>
        <textarea class="ai_general_context" name="ai_general_context"><?php echo $view->data->ai_general_context; ?></textarea>
      </content>

    </setting>

  </settinglist>

  <settinglist class="generate_ai_items">

    <gridbox class="width_half">

      <!-- AI Gen ALT -->
      <setting class='switch'>
        <content>
          <?php $this->printSwitchButton(
            [
              'name' => 'ai_gen_alt',
              'checked' => $view->data->ai_gen_alt,
              'label' => esc_html__('Generate image ALT tag', 'shortpixel-image-optimiser'),
              'data' => ['data-toggle="ai_gen_alt"'],
            ]
          );
          ?>

        </content>


        <content class='toggleTarget ai_gen_alt is-advanced'>
          <?php
          $input = "<input type='number' name='ai_limit_alt_chars' value='" . $view->data->ai_limit_alt_chars . "' max='200' min='0'>";
          ?>
          <name><?php printf(__('Limit generated ALT Tag to %s characters', 'shortpixel-image-optimiser'), $input); ?></name>
        </content>

        <content class='toggleTarget ai_gen_alt is-advanced'>
          <name> <?php _e('Additional context for generating ALT Tags:', 'shortpixel-image-optimiser'); ?></name>
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
              'label' => esc_html__('Generate image description', 'shortpixel-image-optimiser'),
              'data' => ['data-toggle="ai_gen_description"'],
            ]
          );
          ?>

        </content>

        <content class='toggleTarget ai_gen_description is-advanced'>
          <?php
          $input = "<input type='number' name='ai_limit_description_chars' value='" . $view->data->ai_limit_description_chars . "' max='500' min='0'>";
          ?>
          <name><?php printf(__('Limit generated image description to %s characters', 'shortpixel-image-optimiser'), $input); ?></name>
        </content>

        <content class='toggleTarget ai_gen_description is-advanced'>
          <name> <?php _e('Additional context for generating image description', 'shortpixel-image-optimiser'); ?></name>
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
              'label' => esc_html__('Generate image caption', 'shortpixel-image-optimiser'),
              'data' => ['data-toggle="ai_gen_caption"'],

            ]
          );
          ?>

        </content>

        <content class='toggleTarget ai_gen_caption is-advanced'>
          <?php
          $input = '<input type="number" name="ai_limit_caption_chars" value="' . $view->data->ai_limit_caption_chars . '" max="250" min="0" >';
          ?>
          <name><?php printf(__('Limit generated image caption to %s characters', 'shortpixel-image-optimiser'), $input); ?></name>
        </content>


        <content class='toggleTarget ai_gen_caption is-advanced'>
          <name> <?php _e('Additional context for generating image caption', 'shortpixel-image-optimiser'); ?></name>
          <textarea name='ai_caption_context'><?php echo $view->data->ai_caption_context ?></textarea>
        </content>

      </setting>

      <setting class="ai_filename_setting">
        <content>

          <?php $this->printSwitchButton(
            [
              'name' => 'ai_gen_filename',
              'checked' => $view->data->ai_gen_filename,
              'label' => esc_html__('Update image filename with an SEO-friendly one', 'shortpixel-image-optimiser'),
              'data' => ['data-toggle="ai_gen_filename"'],
              'disabled' => true
            ]
          );
          ?>
        </content>

        <content class='nextline ai_gen_filename is-advanced'>
          <?php
          $input  = '<input type="number" name="ai_limit_filename_chars" value="' . $view->data->ai_limit_filename_chars . '" max="200" min="0">';
          ?>
          <name><?php printf(__('Limit filename to %s characters ', 'shortpixel-image-optimiser'), $input); ?></name>
        </content>

        <content class='nextline ai_gen_filename is-advanced'>
          <name><?php _e('Additional context for filename generation: ', 'shortpixel-image-optimiser'); ?></name>
          <textarea name="ai_filename_context"><?php echo $view->data->ai_filename_context ?></textarea>

        </content>

        <content class='nextline ai_gen_filename is-advanced'>
          <?php $this->printSwitchButton(
            [
              'name' => 'ai_filename_prefercurrent',
              'checked' => $view->data->ai_filename_prefercurrent,
              'label' => esc_html__('Prefer keeping current filename if relevant', 'shortpixel-image-optimiser'),
              'disabled' => true
            ]
          );
          ?>
        </content>

        <content class='nextline'>
            <name><?php printf(esc_html__('This is a feature we are currently evaluating. If you would like to see it implemented in a future version of our plugin, please %svote for it here%s.','shortpixel-image-optimiser'), '<a target="_blank" href="https://ideas.shortpixel.com/update-image-filename-with-an-seo-friendly-one~4cMEvKmvFbosoYTI9T4UgK?from=board">', '</a>' ); ?></name>
        </content>


    </gridbox>
  </settinglist>

  <hr>

  <!-- will add this later
    <setting class='switch'>
        
        <content>
        <name><?php // _e('Use image EXIF data', 'shortpixel-image-optimiser'); 
              ?></name>
        <?php $this->printSwitchButton(
          [
            'name' => 'ai_use_exif',
            'checked' => $view->data->ai_use_exif,
            'label' => esc_html__('Take into account the image EXIF data when generating image SEO data', 'shortpixel-image-optimiser')
          ]
        );
        ?>
        </content>
    </setting>
  -->
  <gridbox class="width_half step-highlight-2">
    <setting class='switch'>
      <content>
        <?php $this->printSwitchButton(
          [
            'name' => 'ai_use_post',
            'checked' => $view->data->ai_use_post,
            'label' => esc_html__('Use parent Post / Page title for image SEO data', 'shortpixel-image-optimiser')
          ]
        );
        ?>

        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/#3-toc-title?target=iframe"></i>

        <info><?php _e('When this is enabled, the title of the image\'s parent post or page will be sent to the AI model for more accurate image SEO results.', 'shortpixel-image-optimiser'); ?></info>
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
      <info><?php _e('Select the language you would like to be used for generating image SEO data.','shortpixel-image-optimiser'); ?></info>
    </content>
  </setting>
  </gridbox>
  </settinglist>

  <settingslist class='preview_wrapper'>
    <input type="hidden" name="ai_preview_image_id" value="" />
    <div class='ai_preview'>
      <gridbox class='width_half'>
        <span><img src="" class='image_preview'></span>
        <span><h2><i class='shortpixel-icon ai'></i><?php _e('AI Image SEO Preview','shortpixel-image-optimiser'); ?></h2>
          <p><?php _e('Preview only: the current image data will not be touched!', 'shortpixel-image-optimiser'); ?></p>
          <p>
            <button type='button' name='open_change_photo'>
              <i class='shortpixel-icon optimization'></i><?php _e('Select test image', 'shortpixel-image-optimiser'); ?></button>
            <button type='button' name='refresh_ai_preview'>
              <i class='shortpixel-icon refresh'></i><?php _e('Generate AI SEO data preview', 'shortpixel-image-optimiser'); ?></button>
          </p>
          <div class='preview_result'>
              
          </div>
      </gridbox>
    </div>
    <hr>
    <gridbox class='width_two_with_middle result_wrapper'>
      <div class='current result_info'>
        <h3><?php _e('Current SEO Data', 'shortpixel-image-optimiser'); ?></h3>
        <ul>
      <!--    <li <label><?php _e('Image filemame', 'shortpixel-image-optimiser'); ?>:</label> <span class='filename'></span>
          </li> -->
          <li><label><?php _e('Image ALT tag', 'shortpixel-image-optimiser'); ?>:</label> <span class='alt'></span></li>
          <li><label><?php _e('Image caption', 'shortpixel-image-optimiser'); ?>:</label> <span class='caption'></span></li>
          <li><label><?php _e('Image description', 'shortpixel-image-optimiser'); ?>:</label> <span class='description'></span>
          </li>
        </ul>
      </div>
      <div class='icon'><i class='shortpixel-icon chevron rotate_right'></i>&nbsp;</div>
      <div class='result result_info'>
        <h3><?php _e('Generated AI image SEO data', 'shortpixel-image-optimiser'); ?></h3>
        <ul>
<!--          <li><label><?php _e('Image filemame', 'shortpixel-image-optimiser'); ?>:</label> <span class='filename'></span>
          </li> -->
          <li><label><?php _e('Image ALT tag', 'shortpixel-image-optimiser'); ?>:</label> <span class='alt'></span></li>
          <li><label><?php _e('Image caption', 'shortpixel-image-optimiser'); ?>:</label> <span class='caption'></span></li>
          <li><label><?php _e('Image description', 'shortpixel-image-optimiser'); ?>:</label> <span class='description'></span>
        </ul>
      </div>

    </gridbox>

  </settingslist>


  <?php $this->loadView('settings/part-savebuttons', false); ?>

</section>
