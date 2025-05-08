<?php
namespace ShortPixel\Replacer\Modules;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


class SmartSlider
{

    private static $instance; 

    public static function getInstance()
    {
        if (is_null(self::$instance))
          self::$instance = new SmartSlider();

        return self::$instance;
    }

    public function __construct()
    {
        // Check if plugin is there. 
        if (false === defined('NEXTEND_SMARTSLIDER_3'))
        {
             return; 
        }

        add_action('shortpixel/replacer/replace_urls', [$this, 'doReplaceQueries']);
    }


    public function doReplaceQueries($search_urls, $replace_urls)
    {
        global $wpdb; 

        $table = $wpdb->prefix . 'nextend2_image_storage'; 
        
        $search_url = $search_urls['base']; 
        $replace_url = $replace_urls['base']; 

        


        $sql = ''; 



    }
}