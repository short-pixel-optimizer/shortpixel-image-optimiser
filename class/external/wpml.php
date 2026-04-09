<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


class WPML
{

    public function __construct()
    {
        add_filter('shortpixel/aidatamodel/paramlist', [$this, 'checkParamlist'], 10, 2);    
        add_filter('shortpixel/ai/succes', [$this, 'successHandle'], 10, 2);
    }


    public function checkParamList($data, $item_id)
    {
        $data['languages'] = apply_filters('wpml_current_language', $data['languages']);
         
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