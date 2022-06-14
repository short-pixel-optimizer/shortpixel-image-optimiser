<?php
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


// @todo In time this should be moved to a helper class
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

    /** Find if a certain plugin is active
    * @param String $plugin The name of plugin being searched for
    * @return Boolean Active or not
    */
    public static function shortPixelIsPluginActive($plugin) {
        $activePlugins = apply_filters( 'active_plugins', get_option( 'active_plugins', array()));
        if ( is_multisite() ) {
            $activePlugins = array_merge($activePlugins, get_site_option( 'active_sitewide_plugins'));
        }
        return in_array( $plugin, $activePlugins);
    }

    public static function snakeToCamel($snake_case) {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $snake_case)));
    }

    public static function getPluginPath()
    {
       return plugin_dir_path(SHORTPIXEL_PLUGIN_FILE);
    }


    /** Function to convert dateTime object to a date output
    *
    * Function checks if the date is recent and then uploads are friendlier message. Taken from media library list table date function
    * @param DateTime $date DateTime object
    **/
    public static function format_nice_date( $date ) {

    if ( '0000-00-00 00:00:00' === $date->format('Y-m-d ') ) {
        $h_time = '';
    } else {
        $time   = $date->format('U'); //get_post_time( 'G', true, $post, false );
        if ( ( abs( $t_diff = time() - $time ) ) < DAY_IN_SECONDS ) {
            if ( $t_diff < 0 ) {
                $h_time = sprintf( __( '%s from now' ), human_time_diff( $time ) );
            } else {
                $h_time = sprintf( __( '%s ago' ), human_time_diff( $time ) );
            }
        } else {
            $h_time = $date->format( 'Y/m/d' );
        }
    }

    return $h_time;
}

static public function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

static public function timestampToDB($timestamp)
{
		return date("Y-m-d H:i:s", $timestamp);
}

static public function DBtoTimestamp($date)
{
		return strtotime($date);
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
        //wp_send_json($response); // send json proper, dies.
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


    public static function getConflictingPlugins() {
        $settings = \wpSPIO()->settings();

        $conflictPlugins = array(
            'WP Smush - Image Optimization'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'wp-smushit/wp-smush.php',
                        'page'=>'wp-smush-bulk'
                ),
            'Imagify Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'imagify/imagify.php',
                        'page'=>'imagify'
                ),
            'Compress JPEG & PNG images (TinyPNG)'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'tiny-compress-images/tiny-compress-images.php',
                        'page'=>'tinify'
                ),
            'Kraken.io Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'kraken-image-optimizer/kraken.php',
                        'page'=>'wp-krakenio'
                ),
            'Optimus - WordPress Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'optimus/optimus.php',
                        'page'=>'optimus'
                ),
            'Phoenix Media Rename' => array(
                        'action' => 'Deactivate',
                        'data' => 'phoenix-media-rename/phoenix-media-rename.php',
            ),
            'EWWW Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'ewww-image-optimizer/ewww-image-optimizer.php',
                        'page'=>'ewww-image-optimizer%2F'
                ),
            'EWWW Image Optimizer Cloud'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'ewww-image-optimizer-cloud/ewww-image-optimizer-cloud.php',
                        'page'=>'ewww-image-optimizer-cloud%2F'
                ),
            'ImageRecycle pdf & image compression'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'imagerecycle-pdf-image-compression/wp-image-recycle.php',
                        'page'=>'option-image-recycle'
                ),
            'CheetahO Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'cheetaho-image-optimizer/cheetaho.php',
                        'page'=>'cheetaho'
                ),
            'Zara 4 Image Compression'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'zara-4/zara-4.php',
                        'page'=>'zara-4'
                ),
            'CW Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'cw-image-optimizer/cw-image-optimizer.php',
                        'page'=>'cw-image-optimizer'
                ),
            'Simple Image Sizes'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'simple-image-sizes/simple_image_sizes.php'
                ),
            'Regenerate Thumbnails and Delete Unused'
              => array(
                      'action' => 'Deactivate',
                      'data' => 'regenerate-thumbnails-and-delete-unused/regenerate_wpregenerate.php',
              ),
              'Swift Performance'
                => array(
                        'action' => 'Deactivate',
                        'data' => 'swift-performance/performance.php',
                ),
                'Swift Performance Lite'
                  => array(
                          'action' => 'Deactivate',
                          'data' => 'swift-performance-lite/performance.php',
                  ),
               //DEACTIVATED TEMPORARILY - it seems that the customers get scared.
            /* 'Jetpack by WordPress.com - The Speed up image load times Option'
                => array(
                        'action'=>'Change Setting',
                        'data'=>'jetpack/jetpack.php',
                        'href'=>'admin.php?page=jetpack#/settings'
                )
            */
        );
        if($settings->processThumbnails) {
            $details = __('Details: recreating image files may require re-optimization of the resulting thumbnails, even if they were previously optimized. Please use <a href="https://wordpress.org/plugins/regenerate-thumbnails-advanced/" target="_blank">reGenerate Thumbnails Advanced</a> instead.','shortpixel-image-optimiser');

            $conflictPlugins = array_merge($conflictPlugins, array(
                'Regenerate Thumbnails'
                    => array(
                            'action'=>'Deactivate',
                            'data'=>'regenerate-thumbnails/regenerate-thumbnails.php',
                            'page'=>'regenerate-thumbnails',
                            'details' => $details
                    ),
                'Force Regenerate Thumbnails'
                    => array(
                            'action'=>'Deactivate',
                            'data'=>'force-regenerate-thumbnails/force-regenerate-thumbnails.php',
                            'page'=>'force-regenerate-thumbnails',
                            'details' => $details
                    )
            ));
        }
        if(!$settings->frontBootstrap){
            $conflictPlugins['Bulk Images to Posts Frontend'] = array (
                'action'=>'Change Setting',
                'data'=>'bulk-images-to-posts-front/bulk-images-to-posts.php',
                'href'=>'options-general.php?page=wp-shortpixel-settings&part=adv-settings#siteAuthUser',
                'details' => __('This plugin is uploading images in front-end so please activate the "Process in front-end" advanced option in ShortPixel in order to have your images optimized.','shortpixel-image-optimiser')
            );
        }

        $found = array();
        foreach($conflictPlugins as $name => $path) {
            $action = ( isset($path['action']) ) ? $path['action'] : null;
            $data = ( isset($path['data']) ) ? $path['data'] : null;
            $href = ( isset($path['href']) ) ? $path['href'] : null;
            $page = ( isset($path['page']) ) ? $path['page'] : null;
            $details = ( isset($path['details']) ) ? $path['details'] : null;
            if(is_plugin_active($data)) {
                if( $data == 'jetpack/jetpack.php' ){
                    $jetPackPhoton = get_option('jetpack_active_modules') ? in_array('photon', get_option('jetpack_active_modules')) : false;
                    if( !$jetPackPhoton ){ continue; }
                }
                $found[] = array( 'name' => $name, 'action'=> $action, 'path' => $data, 'href' => $href , 'page' => $page, 'details' => $details);
            }
        }
        return $found;
    }

    public static function alterHtaccess($webp = false, $avif = false){
         // [BS] Backward compat. 11/03/2019 - remove possible settings from root .htaccess
         /* Plugin init is before loading these admin scripts. So it can happen misc.php is not yet loaded */
         if (! function_exists('insert_with_markers'))
         {
           Log::addWarn('AlterHtaccess Called before WP init');
           return;
           //require_once( ABSPATH . 'wp-admin/includes/misc.php' );
         }
           $upload_dir = wp_upload_dir();
           $upload_base = trailingslashit($upload_dir['basedir']);

           if ( ! $webp && ! $avif ) {
               insert_with_markers( get_home_path() . '.htaccess', 'ShortPixelWebp', '');
               insert_with_markers( $upload_base . '.htaccess', 'ShortPixelWebp', '');
               insert_with_markers( trailingslashit(WP_CONTENT_DIR) . '.htaccess', 'ShortPixelWebp', '');
           } else {

           $avif_rules = '
           <IfModule mod_rewrite.c>
           RewriteEngine On
           ##### Directives for delivering AVIF files, if they exist #####
           # Does the browser support avif?
           RewriteCond %{HTTP_ACCEPT} image/avif
           # AND is the request a jpg or png? (also grab the basepath %1 to match in the next rule)
           RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png|gif)$
           # AND does a .avif image exist?
           RewriteCond %{DOCUMENT_ROOT}/%1.avif -f
           # THEN send the avif image and set the env var avif
           RewriteRule (.+)\.(?:jpe?g|png)$ $1.avif [NC,T=image/avif,E=avif,L]

					 # Does the browser support avif?
					 RewriteCond %{HTTP_ACCEPT} image/avif
					 # AND is the request a jpg or png? (also grab the basepath %1 to match in the next rule)
					 RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png|gif)$
					 # AND does a .jpg.avif image exist?
					 RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.avif -f
					 # THEN send the avif image and set the env var avif
					 RewriteRule ^(.+)$ $1.avif [NC,T=image/avif,E=avif,L]

           </IfModule>
           <IfModule mod_headers.c>
           # If REDIRECT_avif env var exists, append Accept to the Vary header
           Header append Vary Accept env=REDIRECT_avif
           </IfModule>
           <IfModule mod_mime.c>
           AddType image/avif .avif
           </IfModule>
                 ';

               $webp_rules = '
           <IfModule mod_rewrite.c>
             RewriteEngine On
             ##### TRY FIRST the file appended with .webp (ex. test.jpg.webp) #####
             # Is the browser Chrome?
             RewriteCond %{HTTP_USER_AGENT} Chrome [OR]
             # OR Is request from Page Speed
             RewriteCond %{HTTP_USER_AGENT} "Google Page Speed Insights" [OR]
             # OR does this browser explicitly support webp
             RewriteCond %{HTTP_ACCEPT} image/webp
             # AND NOT MS EDGE 42/17 - doesnt work.
             RewriteCond %{HTTP_USER_AGENT} !Edge/17
             # AND is the request a jpg, png or gif?
             RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png|gif)$
             # AND does a .ext.webp image exist?
             RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.webp -f
             # THEN send the webp image and set the env var webp
             RewriteRule ^(.+)$ $1.webp [NC,T=image/webp,E=webp,L]
             ##### IF NOT, try the file with replaced extension (test.webp) #####
             RewriteCond %{HTTP_USER_AGENT} Chrome [OR]
             RewriteCond %{HTTP_USER_AGENT} "Google Page Speed Insights" [OR]
             RewriteCond %{HTTP_ACCEPT} image/webp
             RewriteCond %{HTTP_USER_AGENT} !Edge/17
             # AND is the request a jpg, png or gif? (also grab the basepath %1 to match in the next rule)
             RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png|gif)$
             # AND does a .webp image exist?
             RewriteCond %{DOCUMENT_ROOT}/%1.webp -f
             # THEN send the webp image and set the env var webp
             RewriteRule (.+)\.(?:jpe?g|png|gif)$ $1.webp [NC,T=image/webp,E=webp,L]
           </IfModule>
           <IfModule mod_headers.c>
             # If REDIRECT_webp env var exists, append Accept to the Vary header
             Header append Vary Accept env=REDIRECT_webp
           </IfModule>
           <IfModule mod_mime.c>
             AddType image/webp .webp
           </IfModule>
           ' ;

             $rules = '';
         //    if ($avif)
             $rules .= $avif_rules;
           //  if ($webp)
             $rules .= $webp_rules;

             insert_with_markers( get_home_path() . '.htaccess', 'ShortPixelWebp', $rules);

    /** In uploads and on, it needs Inherit. Otherwise things such as the 404 error page will not be loaded properly
   * since the WP rewrite will not be active at that point (overruled) **/
    $rules = str_replace('RewriteEngine On', 'RewriteEngine On' . PHP_EOL . 'RewriteOptions Inherit', $rules);

               insert_with_markers( $upload_base . '.htaccess', 'ShortPixelWebp', $rules);
               insert_with_markers( trailingslashit(WP_CONTENT_DIR) . '.htaccess', 'ShortPixelWebp', $rules);

           }
       }

} // class

function ShortPixelVDD($v){
    return highlight_string("<?php\n\$data =\n" . var_export($v, true) . ";\n?>");
}
