<?php

namespace ShortPixel;

use ShortPixel\Helper\UiHelper as UiHelper;

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

        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#0-toc-title"></i>
        <info>

          <?php esc_html_e('Turn on AI-generated alt text, captions, descriptions, titles and filenames throughout ShortPixel Image Optimizer. Alt text also makes your images accessible to screen readers.', 'shortpixel-image-optimiser'); ?>

        </info>
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

        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#1-toc-title"></i>
        <info>

          <?php esc_html_e('Automatically generate image SEO data with AI after uploading the image, based on the settings below.', 'shortpixel-image-optimiser'); ?>

        </info>
      </content>
    </setting>

    <setting class='switch toggleTarget autoAiOptions'>
      <content>

        <?php $this->printSwitchButton(
          [
            'name' => 'autoAIBulk',
            'checked' => $view->data->autoAIBulk,
            'label' => esc_html__('Generate image SEO data during Bulk','shortpixel-image-optimiser'),
            'tooltip_link' => 'https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#2-toc-title',
          ]
        );
        ?>

        <info>

          <?php esc_html_e('Bulk Processing also generates AI Image SEO for each image it processes, using the options below. You can change this on the Bulk Processing page too.', 'shortpixel-image-optimiser'); ?>

        </info>
      </content>
    </setting>

    <!-- What AI does with text that is already there (Media Library fields + alt text inside posts). -->
    <setting class='switch toggleTarget autoAiOptions ai-existing-text'>
      <content>
        <?php $this->printSwitchButton(
          [
            'name' => 'aiPreserve',
            'checked' => $view->data->aiPreserve,
            'label' => esc_html__('Keep existing image SEO data', 'shortpixel-image-optimiser'),
            'data' => ['data-toggle="ai_overwrite_warning"']
          ]
        );
        ?>
        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#3-toc-title"></i>

        <info>

          <?php esc_html_e('On: AI fills only empty fields.', 'shortpixel-image-optimiser'); ?><br>
          <?php esc_html_e('Off: AI replaces existing text.', 'shortpixel-image-optimiser'); ?><br>
          <?php esc_html_e('Filenames follow the filename setting below.', 'shortpixel-image-optimiser'); ?>

        </info>
      </content>

      <content>
        <name><?php esc_html_e('Alt text in existing posts and pages', 'shortpixel-image-optimiser'); ?></name>
        <info><?php esc_html_e('Images already placed in posts keep their own copy of the alt text. Choose whether AI updates that copy too.', 'shortpixel-image-optimiser'); ?></info>
        <select name="ai_content_replace">
          <option value="none" <?php selected($view->data->ai_content_replace, 'none'); ?>><?php esc_html_e("Don't change posts and pages", 'shortpixel-image-optimiser'); ?></option>
          <option value="missing" <?php selected($view->data->ai_content_replace, 'missing'); ?>><?php esc_html_e("Add alt text only where it's missing (recommended)", 'shortpixel-image-optimiser'); ?></option>
          <option value="overwrite" <?php selected($view->data->ai_content_replace, 'overwrite'); ?>><?php esc_html_e('Replace existing alt text', 'shortpixel-image-optimiser'); ?></option>
        </select>
      </content>
    </setting>


    </gridbox>

    <hr class='toggleTarget autoAiOptions'>

    <setting class='textarea toggleTarget autoAiOptions'>
      <content>
        <name><?php _e('General site context', 'shortpixel-image-optimiser'); ?></name>
        <info><?php _e('This is a general context that will be passed to the AI model to provide more relevant data for your website.', 'shortpixel-image-optimiser'); ?></info>
        <textarea class="ai_general_context" name="ai_general_context" maxlength="500"><?php echo esc_textarea($view->data->ai_general_context); ?></textarea>
      </content>

    </setting>

  </settinglist>

  <settinglist class="generate_ai_items toggleTarget autoAiOptions">

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
          <textarea name="ai_alt_context" maxlength="500"><?php echo esc_textarea($view->data->ai_alt_context); ?></textarea>
        </content>

        <content class='toggleTarget ai_gen_alt is-advanced'>
          <i class='documentation right dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#4-toc-title"></i>
          <name> <?php _e('Always add before ALT tag:', 'shortpixel-image-optimiser'); ?></name>
          <input type="text" name="ai_alt_prefix" maxlength="50" value="<?php echo esc_attr($view->data->ai_alt_prefix); ?>" />
        </content>

        <content class='toggleTarget ai_gen_alt is-advanced'>
          <i class='documentation right dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#4-toc-title"></i>
          <name> <?php _e('Always add after ALT tag:', 'shortpixel-image-optimiser'); ?></name>
          <input type="text" name="ai_alt_postfix" maxlength="50" value="<?php echo esc_attr($view->data->ai_alt_postfix); ?>" />
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
          <textarea name='ai_description_context' maxlength="500"><?php echo esc_textarea($view->data->ai_description_context); ?></textarea>
        </content>

        <content class='toggleTarget ai_gen_description is-advanced'>
          <i class='documentation right dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#4-toc-title"></i>
          <name> <?php _e('Always add before description:', 'shortpixel-image-optimiser'); ?></name>
          <input type="text" name='ai_description_prefix' maxlength="50" value="<?php echo esc_attr($view->data->ai_description_prefix); ?>" />
        </content>

        <content class='toggleTarget ai_gen_description is-advanced'>
          <i class='documentation right dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#4-toc-title"></i>
          <name> <?php _e('Always add after description:', 'shortpixel-image-optimiser'); ?></name>
          <input type="text" name='ai_description_postfix' maxlength="50" value="<?php echo esc_attr($view->data->ai_description_postfix); ?>" />
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
          <textarea name='ai_caption_context' maxlength="500"><?php echo esc_textarea($view->data->ai_caption_context); ?></textarea>
        </content>

        <content class='toggleTarget ai_gen_caption is-advanced'>
          <i class='documentation right dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#4-toc-title"></i>
          <name> <?php _e('Always add before caption:', 'shortpixel-image-optimiser'); ?></name>
          <input type="text" name='ai_caption_prefix' maxlength="50" value="<?php echo esc_attr($view->data->ai_caption_prefix); ?>" />
        </content>

        <content class='toggleTarget ai_gen_caption is-advanced'>
          <i class='documentation right dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#4-toc-title"></i>
          <name> <?php _e('Always add after caption:', 'shortpixel-image-optimiser'); ?></name>
          <input type="text" name='ai_caption_postfix' maxlength="50" value="<?php echo esc_attr($view->data->ai_caption_postfix); ?>" />
        </content>

      </setting>

      <!--- ## Post Title -->
      <setting class="switch">
        <content>

          <?php $this->printSwitchButton(
            [
              'name' => 'ai_gen_post_title',
              'checked' => $view->data->ai_gen_post_title,
              'label' => esc_html__('Update image title with an SEO-friendly one', 'shortpixel-image-optimiser'),
              'data' => ['data-toggle="ai_gen_post_title"'],
              
            ]
          );
          ?>
        </content>

        <content class='toggleTarget ai_gen_post_title is-advanced'>
          <?php
          $input  = '<input type="number" name="ai_limit_post_title_chars" value="' . $view->data->ai_limit_post_title_chars . '" max="100" min="0">';
          ?>
          <name><?php printf(__('Limit image title to %s characters ', 'shortpixel-image-optimiser'), $input); ?></name>
        </content>

        <content class='toggleTarget ai_gen_post_title is-advanced'>
          <name><?php _e('Additional context for image title generation: ', 'shortpixel-image-optimiser'); ?></name>
          <textarea name="ai_post_title_context" maxlength="500"><?php echo esc_textarea($view->data->ai_post_title_context); ?></textarea>

        </content>

        <content class='toggleTarget ai_gen_post_title is-advanced'>
          <i class='documentation right dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#4-toc-title"></i>
          <name><?php _e('Always add before image title:', 'shortpixel-image-optimiser'); ?></name>
          <input type="text" name="ai_post_title_prefix" maxlength="50" value="<?php echo esc_attr($view->data->ai_post_title_prefix); ?>" />
        </content>

        <content class='toggleTarget ai_gen_post_title is-advanced'>
          <i class='documentation right dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#4-toc-title"></i>
          <name><?php _e('Always add after image title:', 'shortpixel-image-optimiser'); ?></name>
          <input type="text" name="ai_post_title_postfix" maxlength="50" value="<?php echo esc_attr($view->data->ai_post_title_postfix); ?>" />
        </content>

      </setting>


      <!-- ## Filename -->
      <setting class="ai_filename_setting full-width">
        <content>

          <?php $this->printSwitchButton(
            [
              'name' => 'ai_gen_filename',
              'checked' => $view->data->ai_gen_filename,
              'label' => esc_html__('Generate SEO-friendly filename', 'shortpixel-image-optimiser'),
              'data' => ['data-toggle="ai_gen_filename"'],
              'disabled' => false
            ]
          );
          ?>
          <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#5-toc-title"></i>
          <?php echo UiHelper::getIcon('res/images/icon/new.svg'); ?>
          <info>
            <?php esc_html_e('An SEO-friendly filename is generated only for newly uploaded images, or for images that are not used in any posts or pages, including content made with well-known page builders. If an image was added in other ways, its links will stop working after the rename, just like links to it from other websites.', 'shortpixel-image-optimiser'); ?>
          </info>
        </content>

        <content class='nextline ai_gen_filename is-advanced'>
          <?php
          $input  = '<input type="number" name="ai_limit_filename_chars" value="' . $view->data->ai_limit_filename_chars . '" max="200" min="0">';
          ?>
          <name><?php printf(__('Limit filename to %s characters ', 'shortpixel-image-optimiser'), $input); ?></name>
        </content>

        <content class='nextline ai_gen_filename is-advanced'>
          <name><?php _e('Additional context for filename generation: ', 'shortpixel-image-optimiser'); ?></name>
          <textarea name="ai_filename_context" maxlength="500"><?php echo esc_textarea($view->data->ai_filename_context); ?></textarea>

        </content>

        <content class='nextline ai_gen_filename is-advanced'>
          <i class='documentation right dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#4-toc-title"></i>
          <name><?php _e('Always add before filename:', 'shortpixel-image-optimiser'); ?></name>
          <input type="text" name="ai_filename_prefix" maxlength="50" value="<?php echo esc_attr($view->data->ai_filename_prefix); ?>" />
        </content>

        <content class='nextline ai_gen_filename is-advanced'>
          <i class='documentation right dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#4-toc-title"></i>
          <name><?php _e('Always add after filename:', 'shortpixel-image-optimiser'); ?></name>
          <input type="text" name="ai_filename_postfix" maxlength="50" value="<?php echo esc_attr($view->data->ai_filename_postfix); ?>" />
        </content>

        <content class='nextline ai_gen_filename is-advanced'>
          <?php $this->printSwitchButton(
            [
              'name' => 'ai_filename_prefercurrent',
              'checked' => $view->data->ai_filename_prefercurrent,
              'label' => esc_html__('Keep the current filename if it already describes the image', 'shortpixel-image-optimiser'),
            ]
          );
          ?>
        </content>
      </setting>


    </gridbox>
  </settinglist>

  <hr class='toggleTarget autoAiOptions'>

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
    <setting class='switch toggleTarget autoAiOptions'>
      <content>
        <?php $this->printSwitchButton(
          [
            'name' => 'ai_use_post',
            'checked' => $view->data->ai_use_post,
            'label' => esc_html__('Use the post/page title for image SEO','shortpixel-image-optimiser'),
            'tooltip_link' => 'https://shortpixel.com/knowledge-base/article/ai-image-seo-settings-explained/?target=iframe&utm_source=plugin&utm_medium=spio&utm_campaign=plugin_settings#6-toc-title',
          ]
        );
        ?>

        <info><?php _e('When this is enabled, the title of the image\'s parent post or page will be sent to the AI model for more accurate image SEO results.', 'shortpixel-image-optimiser'); ?></info>
      </content>
    </setting>

  <setting class='toggleTarget autoAiOptions'>
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

  <settingslist class='preview_wrapper toggleTarget autoAiOptions'>
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
          
          <li><label><?php _e('Image ALT tag', 'shortpixel-image-optimiser'); ?>:</label> <span class='alt'></span></li>
          <li><label><?php _e('Image caption', 'shortpixel-image-optimiser'); ?>:</label> <span class='caption'></span></li>
          <li><label><?php _e('Image description', 'shortpixel-image-optimiser'); ?>:</label> <span class='description'></span></li>
          <li><label><?php _e('Image Title', 'shortpixel-image-optimiser'); ?>:</label> <span class='post_title'></span></li>
          <li><label><?php _e('Image filename', 'shortpixel-image-optimiser'); ?>:</label> <span class='filebase'></span></li>
        </ul>
      </div>
      <div class='icon'><i class='shortpixel-icon chevron rotate_right'></i>&nbsp;</div>
      <div class='result result_info'>
        <h3><?php _e('Generated AI Image SEO data', 'shortpixel-image-optimiser'); ?></h3>
        <ul>
         
          <li><label><?php _e('Image ALT tag', 'shortpixel-image-optimiser'); ?>:</label> <span class='alt'></span></li>
          <li><label><?php _e('Image caption', 'shortpixel-image-optimiser'); ?>:</label> <span class='caption'></span></li>
          <li><label><?php _e('Image description', 'shortpixel-image-optimiser'); ?>:</label> <span class='description'></span></li>
          <li><label><?php _e('Image Title', 'shortpixel-image-optimiser'); ?>:</label> <span class='post_title'></span></li>
          <li><label><?php _e('Image filename', 'shortpixel-image-optimiser'); ?>:</label> <span class='filebase'></span></li>
        </ul>
      </div>

    </gridbox>

  </settingslist>

    <?php $this->loadView('settings/part-savebuttons', false); ?>

</section>
