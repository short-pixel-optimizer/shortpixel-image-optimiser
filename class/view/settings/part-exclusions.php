<?php
namespace ShortPixel;
use \ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


?>

<section id="tab-exclusions" class="<?php echo ($this->display_part == 'exclusions') ? 'active setting-tab' :'setting-tab'; ?>" data-part="exclusions" >

<settinglist>

  <h2><?php esc_html_e('Exclusions','shortpixel-image-optimiser');?></h2>

  <!-- Exclude thumbnails -->
  <setting class='exclude-thumbnail-setting'>
     <name>
         <?php esc_html_e('Exclude thumbnail sizes','shortpixel-image-optimiser');?>
                <i class='documentation up dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/113-how-can-i-optimize-only-certain-thumbnail-sizes?target=iframe"></i>
     </name>
     <div class="grid-thumbnails">

       <?php
       foreach($view->allThumbSizes as $sizeKey => $sizeVal) {
       ?>
           <span>
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
  </setting>
 <!-- // Exclude thumbnails -->

 <!-- Exclude patterns -->
 <setting class='exclude-patterns-setting'>
     <name>
       <?php esc_html_e('Exclude patterns','shortpixel-image-optimiser');?>
       <label><input type='checkbox' class='shortpixel-hide' data-toggle='exclude-settings-expanded'> >> <?php		printf(esc_html__('See examples')); ?></label>

     </name>
     <info>
       <div class='exclude-settings-expanded toggleTarget ' id="exclude-settings-expanded">
         <p  class="settings-info">
         <?php
             printf(esc_html__('%s"Name type:"%s Matches based on the file name only. For example, if you enter %s"flower.jpg"%s in the "Value" field, ShortPixel will exclude all JPEG images ending in "flower" (case-sensitive). Alternatively, you enter %s"logo"%s, all files (PNG/JPEG/GIF/PDF) containing "logo" in the file name will be excluded, such as: "nicelogo.jpg", "alllogos.png" or "logo.gif".', 'shortpixel-image-optimiser'),
             '<b>','</b>',
             '<b>','</b>',
             '<b>','</b>'
             );
         ?>

       </p>
       <br />
       <p  class="settings-info">
         <?php
             printf(esc_html__('%s"Path type:"%s Matches based on the entire file path, which is useful for excluding specific directories or subdirectories. For instance, entering %s"2022"%s in the "Value" field will exclude all images uploaded in 2022, as well as any images with "2022" in the file name (since this is part of the path). To exclude only images uploaded in 2022, use %s"/2022/"%s instead.','shortpixel-image-optimiser'),
             '<b>','</b>',
             '<b>','</b>',
             '<b>','</b>'
             );
             ?>
           </p>
           <br />
           <p  class="settings-info">
         <?php
             printf(esc_html__('For both %s"Name"%s and %s"Path"%s types you can enable the %s"Check as regular expression"%s option. This works similarly but requires a valid regular expression between slashes in the "Value" field. Special characters should be escaped with a backslash (\). For instance, using %s/[0-9]+[^\/]*\.(PNG|png)/%s in the "Value" field for the "Name" type will exclude all PNG images with a numeric prefix.','shortpixel-image-optimiser'),
             '<b>','</b>',
             '<b>','</b>',
             '<b>','</b>',
             '<b>','</b>'
           );
           ?>
         </p>
         <br />
         <p  class="settings-info">
           <?php
             printf(esc_html__('%s"Size type:"%s Applies to all images and thumbnails within the specified size range. You can set intervals or specify an exact size if the %s"Exact sizes"%s option is enabled.','shortpixel-image-optimiser'),
             '<b>','</b>',
             '<b>','</b>'
           );
           ?>
         </p>
      </div> <!-- foldout -->
     </info>
     <content>
         <info>
           <?php
           printf(esc_html__('Use this section to exclude images based on specific patterns. There are three exclusion types: by file name, file path or file size. Each exclusion type can be applied to: all images and their thumbnails (including scaled or original images), only thumbnails (in which case the original and scaled images are not excluded), only Custom Media images (Media Library items are not affected by this exclusion) or a specific selection of thumbnails. Examples can be found in the fold-out section below.','shortpixel-image-optimiser'),
             '<b>','</b>',
             '<b>','</b>'
           );
           ?>
         </info>



         <?php
         $exclusion_format = "
            <li %s %s %s >
              <input type='hidden' name='exclusions[]' value='%s' />
							<span><b>%s </b><br> %s </span>
							<span><b>" . esc_html__('Apply to:', 'shortpixel-image-optimiser') .  "</b><br> %s </span>
              <span class='regular_expression'><span class='regular-container %s'>" . esc_html__('Regular expression', 'shortpixel-image-optimiser') . " %s</span>&nbsp;</span>
              <span> <i class='shortpixel-icon edit'></i>
              <i class='shortpixel-icon remove trash'></i> </span>
            </li>
         ";
         ?>

         <div id='exclusion-format' class='hidden'>

            <?php echo htmlspecialchars( $exclusion_format); ?>

         </div>

         <?php
          $exclusions = UtilHelper::getExclusions();
             $excludeArray = $exclusions;
						 $newIndex = (is_array($excludeArray) && count($excludeArray) > 0) ? (count($excludeArray) -1) : 0;

                 echo "<ul class='exclude-list'>";
								 echo '<input type="hidden" id="new-exclusion-index" name="new-index" value="' . $newIndex . '">';
                 $i = 0;

                 foreach($excludeArray as $index => $option)
                 {
                     $exclude_id  = 'id="exclude-' . $i . '"';
                     $type = (isset($option['type'])) ? $option['type'] : '';
										 $value = isset($option['value']) ? $option['value'] : '';
										 $apply = isset($option['apply']) ? $option['apply'] : '';
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

                     printf($exclusion_format, $class, $title, $exclude_id, $option_code, $field_name, $value, $apply_name, '', '' );

                     $i++;
                 }
                 echo "</ul>";


         ?>
                     <div class='new-exclusion not-visible'>
                         <!-- HEADER -->
                         <input type="hidden" name="edit-exclusion" value="">
                         <h3 class='new-title not-visible'><?php _e('Add New Exclusion' ,'shortpixel-image-optimiser'); ?></h3>
                         <h3 class='edit-title not-visible'><?php _e('Edit Exclusion' ,'shortpixel-image-optimiser'); ?></h3>


                         <div>
                           <label><?php _e('Type:', 'shortpixel-image-optimiser'); ?></label>
                            <select name="exclusion-type" class='new-exclusion-type'>
                               <option value='name'><?php _e('Image Name', 'shortpixel-image-optimiser'); ?></option>
                               <option value='path' data-example="/path/"><?php _e('Image Path', 'shortpixel-image-optimiser'); ?></option>
                               <option value='size' data-example="widthXheight-widthXheight"><?php _e('Image Size', 'shortpixel-image-optimiser'); ?></option>

                           </select>
                         </div>

                         <div class='value-and-size'>

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
                                       <input type="number" class='small' name="exclusion-minwidth" value="" min="0">px -
                                       <input type="number" class='small' name="exclusion-maxwidth" value="" min="0">px
                                   </div>
                                   <div class='height'>
                                       <label><?php _e('Height between:', 'shortpixel-image-optimiser'); ?></label>
                                       <input type="number" class='small' name="exclusion-minheight" value="" min="0">px -
                                       <input type="number" class='small' name="exclusion-maxheight" value="" min="0">px
                                   </div>
                                 </div>

                                 <div class='size-option-exact not-visible'>
                                   <div class='exact'>
                                     <label>
                                       <?php _e('Exact size:', 'shortpixel-image-optimiser'); ?></label>
                                       <input type="number" class='small' name="exclusion-width" value="" min="0">px -
                                       <input type="number" class='small' name="exclusion-height" value="" min="0">px
                                    </div>
                                 </div>
                             </div>
                        </div> <!-- value / size container -->

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


                         <div class='thumbnail-select'>
                           <h4><?php _e('Selected Thumbnails', 'shortpixel-image-optimiser'); ?><hr></h4>
                           <div class='grid-thumbnails'>
                               <?php foreach($view->allThumbSizes as $key => $data)
                               {
                                  // $nice_name = isset($data['nice-name']) ? $data['nice-name'] : $name;
                                   $width = isset($data['width']) ? $data['width'] : '*';
                                   $height = isset($data['height']) ? $data['height'] : '*';

                                   $name = isset($data['nice-name']) ? $data['nice-name'] : ucfirst($key);
                                   $label = $name . " ( $width &times $height )";

                                printf('<span><label><input type="checkbox" name="thumbnail-select[]" value="%s" > %s </label></span>', $key, $label);
                               } ?>
                          </div>
                         </div>
                         <div class='validation-message not-visible'>
                            <?php _e('Fields with a red border are required', 'shortpixel-image-optimiser'); ?>
                         </div>

                         <div class='button-actions'>

                           <button type="button" class="button button-primary not-visible" name="addExclusion">
                             <i class="shortpixel-icon save"> </i>
                             <?php _e('Save', 'shortpixel-image-optimiser'); ?>
			   </button>

                           <button type="button" class="button button-primary not-visible" name="updateExclusion">
			     <i class="shortpixel-icon save"> </i>
                             <?php _e("Update", 'shortpixel-image-optimiser');  ?>
                           </button>

                           <button type="button" class="button" name='cancelEditExclusion'>
			     <i class="shortpixel-icon close"> </i>
			     <?php _e('Close', 'shortpixel-image-optimiser'); ?>
			   </button>

                           <button type="button" class="button button-primary not-visible" name="removeExclusion">
			     <i class="shortpixel-icon close"> </i>
                             <?php _e("Remove", 'shortpixel-image-optimiser');  ?>
                           </button>

                         </div>
                       </div> <!-- new exclusion -->

                       <button class='button button-primary new-exclusion-button' type='button' name="addNewExclusion">
                         <?php _e('Add new Exclusion', 'shortpixel-image-optimiser'); ?>
                       </button>

             <info class='exclusion-save-reminder hidden'><?php _e('Reminder: Save the settings for the   exclusion changes to take effect!', 'shortpixel-image-optimiser'); ?></info>
     </content>
 </setting>
 <!-- // Exclude patterns -->

</settinglist>


  <?php $this->loadView('settings/part-savebuttons', false); ?>
</section>
