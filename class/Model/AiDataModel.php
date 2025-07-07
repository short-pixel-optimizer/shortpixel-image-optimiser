<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

// Class to handle the Database Table Data, store AI relevant data etc. 
class AiDataModel
{

    protected $id; 
    protected $attach_id; 
    protected $type;

    protected $original = [
        'alt', 
        'caption', 
        'description', 
        'filebase'
    ];

    protected $current = [
        'alt',  
        'caption', 
        'description', 
    ]; 

    protected $generated = [
        'alt',  
        'caption', 
        'description',
        'filebase' 
    ]; 

    protected $status = 0;

    private $has_record = false; 
    private $has_generated = false; 


    const TYPE_MEDIA = 1; 
    const TYPE_CUSTOM = 2; 

    const AI_STATUS_NOTHING = 0;
    const AI_STATUS_GENERATED = 1; 

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
           $tableName = $this->getTableName();
           
           $sql = ' SELECT * FROM ' . $tableName . ' where attach_id = %d and type = %d';
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

        $this->id = $row->id; 
        $this->has_record = true; 
        $this->status = $row->status; 
        $this->original = $row->original; 
        $this->generated = $row->generated; 


    }

    public function handleNewData($data)
    {   
        foreach($data as $name => $value)
        {
             if (isset($this->generated[$name]))
             {
                 $this->generated[$name] = $value; 
             }
             else
             {
                 Log::addTemp('Still to handle new data in AiDataMOdeL : ' . $name, $value);
             }
        }

        $this->setCurrentData();

        // New Data. 
        if (false === $this->has_record)
        {
            $this->original = $this->current; 
            $this->updateRecord();
        }
        else
        {
            // Not sure if  just categorically deny this, or some smart updater ( with more risks ) 
            Log::addError('New AI DATA wil record available');
        }


        if (isset($this->generated['alt']) && false !== $this->generated['alt'])
        {
            $bool = update_post_meta($this->attach_id, '_wp_attachment_image_alt', $this->generated['alt']);
        }

        $post = get_post($this->attach_id); 
        $post_updated = false; 

        if (isset($this->generated['caption']) && false !== $this->generated['caption'])
        {
            $post->post_excerpt = $this->generated['caption'];
            $post_updated = true; 
        }

        if (isset($this->generated['description']) && false !== $this->generated['description'])
        {
            $post->post_content = $this->generated['description'];
            $post_updated = true; 
        }

        if (true === $post_updated)
        {
            wp_update_post($post);
        }
    }

    // Should return our results, from the AI only
    public function getGeneratedData()
    {
        return $this->generated;
    }

    // Should return the current situation. If not stored in the database - or different from meta - uh something should be returned. 
    protected function setCurrentData()
    {
        $attach_id = $this->attach_id;      

        $current_alt = get_post_meta($attach_id, '_wp_attachment_image_alt', true);
        
        $post = get_post($attach_id); 

        $current_description = $post->post_content; 
        $current_caption = $post->post_except; 

        $this->current = [
             'alt' => $current_alt, 
             'description' => $current_description, 
             'caption' => $current_caption, 
        ];
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
        
        if ($this)
          
    }

    private function getTableName()
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
            'generated' => $this->generated, 
            'original' => $this->original, 
            'tsUpdated' => time(), 
        ];

        $format = ['%d', '%d', '%s', '%s', '%d'];

        if (false === $this->has_record)
        {
            $this->id = $wpdb->insert($this->getTableName(), $fields, $format); 
            $this->has_record = true; 
        }
        else
        {
            $wpdb->update($this->getTableName(),$fields, ['id' => $this->id],$format);
        }

    }

    protected function removeRecord()
    {   
        if (true === $this->has_record)
        {
            global $wpdb; 
            return $wpdb->delete($this->getTableName()(), ['id' => $this->id], ['%s']);

        }
    
    }

    
    /*public static function getAiDataByAttachment($attach_id)
    {

    } */




} // class 