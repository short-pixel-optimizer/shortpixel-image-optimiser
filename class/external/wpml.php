<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class WPML
{

    public function __construct()
    {
        if (false === \wpSPIO()->env()->plugin_active('wpml'))
        {
            return false; 
        }
        add_filter('shortpixel/aidatamodel/paramlist', [$this, 'checkParamlist'], 10, 2);    
        add_filter('shortpixel/ai/succes', [$this, 'successHandle'], 10, 2);
    }


    public function checkParamList($data, $item_id)
    {
        $languages = apply_filters('wpml_post_language_details', null, $item_id); 

        if (is_array($languages) && isset($languages['locale']))
		{
            // This can happen if WPML is not fully configured. 
            if (false === is_null($languages['locale']) && false !== $languages['locale'])
            {
			    $data['languages'] = $languages['locale'];
            }
		}
                
        $data = apply_filters('shortpixel/wpml/paramlist', $data); 
        return $data; 

    }

    public function successHandle($data, $qItem)
    {   
        $data = apply_filters('shortpixel/wpml/airesult', $data, $qItem);
        return $data; 
    }
    

}


new WPML();