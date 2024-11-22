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


    <div class="wp-shortpixel-options wp-shortpixel-tab-content" style='visibility: hidden'>


    <settinglist>


     <!-- Exclude thumbnails -->
     <setting>
        <name>
            <?php esc_html_e('Exclude thumbnail sizes','shortpixel-image-optimiser');?>
        </name>
        <content>

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

          <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/113-how-can-i-optimize-only-certain-thumbnail-sizes?target=iframe"></i>
        </content>
     </setting>
    <!-- // Exclude thumbnails -->

    <!-- Exclude patterns -->
    <setting>
        <name>
          <?php esc_html_e('Exclude patterns','shortpixel-image-optimiser');?>
        </name>
        <content>
          <button class='button button-primary new-exclusion-button' type='button' name="addNewExclusion">
            <?php _e('Add new Exclusion', 'shortpixel-image-optimiser'); ?>
          </button>
            <info>
              <?php
              printf(esc_html__('Use this section to exclude images based on patterns. There are three types of exclusions: based on the file name, on the file path or on the file size. Each exclusion type can be applied to: all images and thumbnails of that image (including the scaled or original image), only thumbnails (in this case the original and scaled images are not excluded), only Custom Media images (in this case the items from the Media Library are not excluded) or only for a selection of thumbnails of your choice. Examples can be found in the fold-out area below.','shortpixel-image-optimiser'),
                '<b>','</b>',
                '<b>','</b>'
              );
              ?>
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
              </div> <!-- foldout -->
            </info>

            <?php
             $exclusions = UtilHelper::getExclusions();
                $excludeArray = $exclusions;

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


                <info class='exclusion-save-reminder hidden'><?php _e('Reminder: Save the settings for the   exclusion changes to take effect!', 'shortpixel-image-optimiser'); ?></info>
        </content>
    </setting>
    <!-- // Exclude patterns -->



    </settinglist>

    <table class="form-table">
        <tbody>


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
