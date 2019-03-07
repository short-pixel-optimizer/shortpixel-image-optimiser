<?php

class ShortPixelTools {
/*    public static function parseJSON($data) {
        if ( function_exists('json_decode') ) {
            $data = json_decode( $data );
        } else {
            require_once( 'JSON/JSON.php' );
            $json = new Services_JSON( );
            $data = $json->decode( $data );
        }
        return $data;
    }*/
    
    public static function snakeToCamel($snake_case) {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $snake_case)));
    }

    public static function requestIsFrontendAjax()
    {
        $script_filename = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '';

        //Try to figure out if frontend AJAX request... If we are DOING_AJAX; let's look closer
        if((defined('DOING_AJAX') && DOING_AJAX))
        {
            //From wp-includes/functions.php, wp_get_referer() function.
            //Required to fix: https://core.trac.wordpress.org/ticket/25294
            $ref = '';
            if ( ! empty( $_REQUEST['_wp_http_referer'] ) ) {
                $ref = wp_unslash( $_REQUEST['_wp_http_referer'] );
            } elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
                $ref = wp_unslash( $_SERVER['HTTP_REFERER'] );
            }
          //If referer does not contain admin URL and we are using the admin-ajax.php endpoint, this is likely a frontend AJAX request
          if(((strpos($ref, admin_url()) === false) && (basename($script_filename) === 'admin-ajax.php')))
            return true;
        }

        //If no checks triggered, we end up here - not an AJAX request.
        return false;
    }
    
    public static function commonPrefix($str1, $str2) {
        $limit = min(strlen($str1), strlen($str2));
        for ($i = 0; $i < $limit && $str1[$i] === $str2[$i]; $i++);
        return substr($str1, 0, $i);
    }
    
    /**
     * This is a simplified wp_send_json made compatible with WP 3.2.x to 3.4.x
     * @param type $response
     */
    public static function sendJSON($response) {
        @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
        die(json_encode($response));
    }

    /**
     * finds if an array contains an item, comparing the property given as key
     * @param $item
     * @param $arr
     * @param $key
     * @return the position that was removed, false if not found
     */
    public static function findItem($item, $arr, $key) {
        foreach($arr as $elm) {
            if($elm[$key] == $item) {
                return $elm;
            }
        }
        return false;
    }    
}

function ShortPixelVDD($v){
    return highlight_string("<?php\n\$data =\n" . var_export($v, true) . ";\n?>");
}