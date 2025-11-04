<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Helper\InstallHelper;
use ShortPixel\Helper\UtilHelper;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

// Class to handle the Database Table Data, store AI relevant data etc. 
class AiDataModel
{
    protected $id; 
    protected $attach_id; 
    protected $type;

    protected static $models = []; 

    protected $original = [
        'alt' => null, 
        'caption' => null, 
        'description' => null, 
        'filebase' => null, 
    ];

    protected $current = [
        'alt' => null,  
        'caption' => null, 
        'description' => null, 
    ]; 

    protected $generated = [
        'alt' => null,  
        'caption' => null, 
        'description' => null,
        'filebase' => null, 
    ]; 

    protected $status = 0;

    private $has_record = false; 
    private $has_generated = false; 
    private $current_is_set = false; 

    private $processable_status = 0;

    const TYPE_MEDIA = 1; 
    const TYPE_CUSTOM = 2; 

    // Status for the whole image, in the main table. 
    const AI_STATUS_NOTHING = 0;
    const AI_STATUS_GENERATED = 1; 

    // IsProcessable statii 
    const P_PROCESSABLE = 0; 
    const P_ALREADYDONE = 1;  // Data already there 
    const P_EXIFAI = 2;  // When Exif Flag forbids AI doing 
    const P_EXTENSION = 3; 
    const P_NOJOB = 4; 
    const P_NOFIELDS = 5; 

    // Descriptive status if certain field is not generated / left alone. 
    const F_STATUS_OK = 1; 
    const F_STATUS_EXCLUDESETTING = -3; 
    const F_STATUS_PREVENTOVERRIDE = -4;
    

    public function __construct($attach_id, $type = 'media')
    {
          $this->attach_id = $attach_id; 
          if ('media' == $type) // only this supported for now 
          {
             $this->type = self::TYPE_MEDIA; 
          }

          $this->fetchRecord($this->attach_id, $this->type);
    }

    protected function fetchRecord($attach_id, $type)
    {
           global $wpdb; 
           $tableName = self::getTableName();
           
           $sql = ' SELECT * FROM ' . $tableName . ' where attach_id = %d and post_type = %d';
           $sql = $wpdb->prepare($sql, $attach_id, $type); 

           $row = $wpdb->get_row($sql);  

        if (false === $row && strpos($wpdb->last_error, 'exist') !== false) {
			InstallHelper::checkTables();
            $this->has_record = false; 
			return false;
        }
        if (false == $row)
        {
            $this->has_record = false; 
            return; 
        }

        $originalData = $this->checkRowData($row->original_data); 
        $generatedData = $this->checkRowData($row->generated_data); 


        $this->id = $row->id; 
        $this->has_record = true; 
        $this->status = $row->status; 
        $this->original = array_merge($this->original, $originalData); 
        $this->generated = array_merge($this->generated, $generatedData); 

    }

    private function checkRowData($json)
    {
        $bool = UtilHelper::ValidateJSON($json);
        if (false === $bool)
        {
             return []; 
        }
        
        $data = json_decode($json); 

        return (array) $data;

    }

    /** Get all data needed to send API for generating AI texts, depending on settings. This includes all settings minus URL 
     * 
     * @return array{paramlist: array<string, array{context: mixed, chars: mixed}>, returndatalist: array<string, array<string, int>>} 
     */
    public function getOptimizeData($params = [])
    {
        $settings = (object) UtilHelper::getAiSettings($params); 

        $ignore_fields = []; 
        if (true === $settings->aiPreserve)
        {
            $currentData = $this->getCurrentData(); 
            $ignore_fields = array_keys(array_filter($currentData));
        }


       // $fields = ['ai_gen_alt', 'ai_gen_caption', 'ai_gen_description', 'ai_gen_filename']; 
        $fields = ['alt', 'caption', 'description', 'filename']; 

        $paramlist = [
            'languages' => $settings->ai_language, 
            'context' => $settings->ai_general_context, 
        ]; 
        $returnDataList = []; 
        $field_status = false; // check if there are any fields to process / not all excluded. 

        foreach($fields as $field_name)
        {
            $api_name = $field_name; 
            //$paramlist[$api_name] = [];

            switch($api_name)
            {
                case 'description': 
                    $api_name = 'image_description';
                break;
                case 'filename': 
                    $api_name = 'file';
                break; 
            }


            if (false === $settings->{'ai_gen_' . $field_name})
            {
                $returnDataList[$field_name]['status'] = self::F_STATUS_EXCLUDESETTING;
                continue; 
            }
            elseif (true === in_array($field_name, $ignore_fields))
            {
                $returnDataList[$field_name]['status'] = self::F_STATUS_PREVENTOVERRIDE;
            }
            else
            {
                $paramlist[$api_name] = [
                        'context' => $settings->{'ai_' . $field_name . '_context'},
                        'chars' => $settings->{'ai_limit_' . $field_name . '_chars'},
                ];
                $returnDataList[$field_name]['status']  = self::F_STATUS_OK; 
                $field_status = true;
            }

           
        }

        if (false === $field_status)
        {
            $this->processable_status = self::P_NOJOB; 
        }

        return ['paramlist' => $paramlist, 'returndatalist' => $returnDataList]; 

    }

    public function handleNewData($data)
    {   
        // Save to Database
        foreach($data as $name => $value)
        {
             if ('original_filebase' == $name)
             {
                 $this->current['filebase'] = $value; 
             }
             else
             {
                $this->generated[$name] = $value;                 
             }

        }

        $this->setCurrentData();

        // New Data. 
        if (false === $this->has_record)
        {
            $this->original = $this->current; 
            
            $this->status = self::AI_STATUS_GENERATED;
            $this->updateRecord();
        }
        else
        {
            // Not sure if  just categorically deny this, or some smart updater ( with more risks ) 
            Log::addError('New AI Data already has an entry');
        }

        // Save to WordPress
        $this->updateWPPost($this->generated);
        $this->updateWpMeta($this->generated); 

    }

    protected function updateWPPost($data)
    {
        $post = get_post($this->attach_id); 
        $post_updated = false; 

        if (isset($data['caption']) && false !== $data['caption'] && false === is_int($data['caption']))
        {
            $post->post_excerpt = $data['caption'];
            $post_updated = true; 
        }

        if (isset($data['description']) && false !== $data['description'] && false === is_int($data['description']))
        {
            $post->post_content = $data['description'];
            $post_updated = true; 
        }

        if (true === $post_updated)
        {
            wp_update_post($post);
        }
    }

    protected function updateWpMeta($data)
    {
        Log::addTemp('Update WpMeta', $data);
        if (isset($data['alt']) && false !== $data['alt'] && false === is_int($data['alt']))
        {
            $bool = update_post_meta($this->attach_id, '_wp_attachment_image_alt', $data['alt']);
        }
    }

    // Should return our results, from the AI only
    public function getGeneratedData()
    {
        return $this->generated;
    }

    public function getStatus()
    {
         return $this->status;
    }

    public function getAttachId()
    {
         return $this->attach_id;
    }

    public function currentIsDifferent()
    {
         $this->setCurrentData(); 

         $generated = array_filter($this->generated, [$this, 'mapWPVars'], ARRAY_FILTER_USE_KEY); 
         $current = array_filter($this->current, [$this, 'mapWPVars'], ARRAY_FILTER_USE_KEY);
         
         $diff = array_diff($generated, $current); 

         if (count($diff) > 0)
         {
             return true; 
         }
         return false; 
    }

    private function mapWPVars($key)
    {
         $fields = ['alt', 'caption', 'description']; 

         if (false === in_array($key, $fields))
         {
            return false; 
         }
         return true;
        
    }

    // Should return the current situation. If not stored in the database - or different from meta - uh something should be returned. 
    protected function setCurrentData()
    {
        $attach_id = $this->attach_id;      
        $current_alt = get_post_meta($attach_id, '_wp_attachment_image_alt', true);
        $post = get_post($attach_id); 

        $current_description = $post->post_content; 
        $current_caption = $post->post_excerpt; 

        /*$this->current = [
             'alt' => $current_alt, 
             'description' => $current_description, 
             'caption' => $current_caption, 

        ]; */

        $this->current['alt'] = $current_alt; 
        $this->current['description'] = $current_description; 
        $this->current['caption'] = $current_caption; 

        $this->current_is_set = true; 

    }

    public function getCurrentData()
    {
          if (false === $this->current_is_set)
          {
             $this->setCurrentData(); 
          }

          return $this->current;
    }

    // This should return originals, or what the system thinks is the last user-generated content here. 
    public function getOriginalData()
    {
        return $this->original; 
    }

    // Check if the stored data still correlates to reality
    public function checkStoredData()
    {
        if (false === $this->has_record)
        {
            return true; 
        }

        $this->setCurrentData();
                 
    }

    /** Function to check if on this item there is something to AI 
     * 
     * @return boolean 
     */
    public function isProcessable()
    {
        if (true === $this->has_record)
        {
             $this->processable_status = SELF::P_ALREADYDONE;
             return false; 
        }

        // Stash here other conditions on top with && to build a big processable function 
        $processable = ( $this->isExifProcesssable() && $this->isExtensionIncluded() && $this->hasSomethingGeneratable() ) ? true : false; 
        return $processable; 
    }


    private function isExifProcesssable()
    {
        $fs = \wpSPIO()->filesystem(); 
        $imageModel = $fs->getMediaImage($this->attach_id); 

        if (false === $imageModel->isSomethingOptimized())
        {
            return true; 
        }

        $imageObj = $imageModel->getSomethingOptimized(); 
        

        $keepExif = $imageObj->getMeta('did_keepExif');

        // 2-3 are exif_ai combined settings with keep-exif. 0-1 are when default settings are used and unset / unused 
        if (in_array($keepExif, [0,1,2,3]))
        {
            return true; 
        }

        $this->processable_status = self::P_EXIFAI;
        return false; 

    }

    public function getProcessableReason($returnStatus = false )
    {
        $message = false; 
        
        if (true === $returnStatus)
        {
            return $this->processable_status;
        }

        switch($this->processable_status)
        {
            case self::P_PROCESSABLE:
                $message = __('AI is processable', 'shortpixel-image-optimiser');
            break; 
            case self::P_ALREADYDONE:
                $message = __('This image already has generated data', 'shortpixel-image-optimiser');
            break; 
            case self::P_EXIFAI:
                $message = __('Image Exif settings restrict AI usage', 'shortpixel-image-optimiser');
            break; 
            case self::P_EXTENSION:
                 $message = __('File Extension not supported', 'shortpixel-image-optimiser');
            break; 
            case self::P_NOJOB:
                $message = __('No fields to generate', 'shortpixel-image-optimiser'); 
            break; 
            default:
                 $message = sprintf(__('Status %s unknown', 'shortpixel-image-optimiser'), $this->processable_status);
            break; 
        }

        return $message;
    }

    protected function isExtensionIncluded()
    {
        $fs = \wpSPIO()->filesystem(); 
        $imageModel = $fs->getMediaImage($this->attach_id); 
        
        // Gif removed here, since we (temporarily don't support it)
        $extensions = ['png', 'jpeg', 'webp', 'jpg'];

        if (in_array($imageModel->getExtension(), $extensions))
        {
            return true; 
        }

        $this->processable_status = self::P_EXTENSION;
        return false; 
    }

    protected function hasSomethingGeneratable()
    {
        $optimizeData = $this->getOptimizeData(); 

        if (self::P_NOJOB === $this->processable_status)
        {
             return false; 

        }
        return true; 
    }

    public function isSomeThingGenerated()
    {
        if (false === $this->has_record)
        {
             return false; 
        }

        if (count(array_keys(array_filter($this->generated))) > 0)
        {
             return true; 
        }
        return false;
    }

    private static function getTableName()
    {
         global $wpdb; 
         return $wpdb->prefix . 'shortpixel_aipostmeta';
    }

    protected function updateRecord($data = [])
    {
        global $wpdb; 
        $is_new = is_null($this->id) ? true : false; 

        $fields = [
            'attach_id' => $this->attach_id, 
            'status' => $this->status, 
            'generated_data' => json_encode($this->generated), 
            'original_data' => json_encode($this->original), 
            'tsUpdated' => UtilHelper::timestampToDB(time()), 
        ];

        $format = ['%d', '%d', '%s', '%s', '%s'];

        if (false === $this->has_record)
        {
            $this->id = $wpdb->insert(self::getTableName(), $fields, $format); 
            $this->has_record = true; 
        }
        else
        {
            $wpdb->update(self::getTableName(),$fields, ['id' => $this->id],$format);
        }

    }

    public function migrate($data)
    {
        $updated = false; 
        if (false === is_array($data))
        {
            return false;
        }
        
        if (is_null($this->original['alt']))
        {
            $this->original['alt'] = $data['original_alt']; 
            $updated = true; 
        }
        if (is_null($this->generated['alt']))
        {
            $this->generated['alt'] = $data['result_alt'];
            $updated = true; 
        }
        
        if (true === $updated)
        {
            $this->status = self::AI_STATUS_GENERATED;
            $this->updateRecord();
            
        }

        return true;
    }

    public function revert()
    {   
        if (true === $this->has_record)
        {
            global $wpdb; 
            $wpdb->delete(self::getTableName(), ['id' => $this->id], ['%s']);

        }

        $this->updateWPPost($this->original);
        $this->updateWpMeta($this->original);
   
    }

    public static function getMostRecent()
    {
        global $wpdb; 
         $sql = 'SELECT attach_id FROM ' . self::getTableName() . ' order by tsUpdated desc limit 1'; 
         $attach_id = $wpdb->get_var($sql);         

        if (false === $attach_id)
        {
             return false; 
        }

        return new AiDataModel($attach_id);
    }

    
    public static function getModelByAttachment($attach_id, $type = 'media')
    {
        if (false === isset(self::$models[$attach_id]))
        {
             self::$models[$attach_id]  = new AiDataModel($attach_id, $type);
        }

        return self::$models[$attach_id];

    } 

    public static function flushModelCache($attach_id, $type = 'media')
    {
        if (isset(self::$models[$attach_id]))
        {
             unset(self::$models[$attach_id]);
        }
        else
        {
             Log::addTemp('Ai MODEL not found in cache!', $attach_id);
        }

    }




} // class
