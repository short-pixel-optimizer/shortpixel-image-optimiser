<?php
namespace ShortPixel\Replacer\Modules;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


class SmartSlider
{

    private static $instance; 

    private $replacer; 

    protected $base_url;
    protected $subdir;

    public static function getInstance($replacer)
    {
        if (is_null(self::$instance))
          self::$instance = new SmartSlider($replacer);

        return self::$instance;
    }

    public function __construct($replacer)
    {
        // Check if plugin is there. 
        if (false === defined('NEXTEND_SMARTSLIDER_3'))
        {
             return; 
        }

        $this->replacer = $replacer; 

        $uploads_dir = \wp_upload_dir(); 

        $this->base_url = str_replace($uploads_dir['subdir'], '', $uploads_dir['baseurl']); 
        $this->subdir = $uploads_dir['subdir'];

        add_action('shortpixel/replacer/replace_urls', [$this, 'doReplaceQueries'], 10, 3);
    }


    public function doReplaceQueries($search_urls, $replace_urls, $base_url)
    {
        global $wpdb; 
        $table = $wpdb->prefix . 'nextend2_smartslider3_slides'; 

        $base_url = $this->fixUploadString($base_url);
        $search_urls = array_map([$this, 'fixUploadString'], $search_urls); 
        $replace_urls = array_map([$this, 'fixUploadString'], $replace_urls); 

        $select_sql = 'SELECT * FROM %i where %i like %s OR %i like %s'; 


            $prepared_select = $wpdb->prepare($select_sql, [
                $table, 
                'thumbnail', 
                '%' . $base_url . '%', 
                'params', 
                '%' . $base_url . '%',
            ]);              
                        
            $results = $wpdb->get_results($prepared_select, ARRAY_A);

            foreach($results as $index => $data)
            {

                $row_id = $data['id']; 
                $thumbnail = $this->replacer->replaceContent($data['thumbnail'], $search_urls, $replace_urls); 
                $params = $this->replacer->replaceContent($data['params'], $search_urls, $replace_urls); 

                $update = []; 
                if ($thumbnail != $data['thumbnail'])
                {
                    $update['thumbnail'] = $thumbnail; 
                }
                if ($params !== $data['params'])
                {
                    $update['params'] = $params; 
                }
                
                Log::addTemp('Update Ar', $update);
                if (count($update) > 0)
                {
                    $res = $wpdb->update($table, $update, ['id' => $row_id], '%s', '%d' );
                    Log::addInfo('Smartslider Updated records: ' . $res);   
                }
            }
    
    }

    protected function fixUploadString($value)
    {
        $subpos = strstr($value, $this->subdir); 
        $value = '$upload$' . $subpos;
      //  $value = str_replace($this->base_url, '$uploads$', $value); 
        return $value; 
    }

    
}
