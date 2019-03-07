<?php
/**
 * Class ShortPixelImgToPictureWebp - convert an <img> tag to a <picture> tag and add the webp versions of the images
 * thanks to the Responsify WP plugin for some of the code
 */

class ShortPixelImgToPictureWebp
{
    public static function lazyGet($img, $type)
    {
        return array(
            'value' =>
                (isset($img['data-lazy-' . $type]) && strlen($img['data-lazy-' . $type])) ?
                    $img['data-lazy-' . $type]
                    : (isset($img['data-' . $type]) && strlen($img['data-' . $type]) ?
                        $img['data-' . $type]
                        : (isset($img[$type]) && strlen($img[$type]) ? $img[$type] : false)),
            'prefix' =>
                (isset($img['data-lazy-' . $type]) && strlen($img['data-lazy-' . $type])) ? 'data-lazy-'
                    : (isset($img['data-' . $type]) && strlen($img['data-' . $type]) ? 'data-'
                        : (isset($img[$type]) && strlen($img[$type]) ? '' : false))
        );
    }

    public static function convert($content)
    {
        // Don't do anything with the RSS feed.
        if (is_feed() || is_admin()) {
            return $content . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG convert is_feed or is_admin -->' : '');
        }

        return preg_replace_callback('/<img[^>]*>/', array('ShortPixelImgToPictureWebp', 'convertImage'), $content) . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG convert called -->' : '');
    }

    public static function convertImage($match)
    {
        // Do nothing with images that have the 'sp-no-webp' class.
        if (strpos($match[0], 'sp-no-webp')) {
            return $match[0] . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG convertImage sp-no-webp -->' : '');
        }

        $img = self::get_attributes($match[0]);

        $srcInfo = self::lazyGet($img, 'src');
        $src = $srcInfo['value'];
        $srcPrefix = $srcInfo['prefix'];

        $srcsetInfo = self::lazyGet($img, 'srcset');
        $srcset = $srcsetInfo['value'];
        $srcsetPrefix = $srcset ? $srcsetInfo['prefix'] : $srcInfo['prefix'];

        $sizesInfo = self::lazyGet($img, 'sizes');
        $sizes = $sizesInfo['value'];
        $sizesPrefix = $sizesInfo['prefix'];

        $altAttr = isset($img['alt']) && strlen($img['alt']) ? ' alt="' . $img['alt'] . '"' : '';

        //check if there are webps
        /*$id = $thisClass::url_to_attachment_id( $src );
        if(!$id) {
            return $match[0];
        }
        $imageBase = dirname(get_attached_file($id)) . '/';
        */
        $updir = wp_upload_dir();
        $proto = explode("://", $src);
        if (count($proto) > 1) {
            //check that baseurl uses the same http/https proto and if not, change
            $proto = $proto[0];
            if (strpos($updir['baseurl'], $proto."://") === false) {
                $base = explode("://", $updir['baseurl']);
                if (count($base) > 1) {
                    $updir['baseurl'] = $proto . "://" . $base[1];
                }
            }
        }
        $imageBase = str_replace($updir['baseurl'], SHORTPIXEL_UPLOADS_BASE, $src);
        if ($imageBase == $src) { //maybe the site uses a CDN or a subdomain?
            $urlParsed = parse_url($src);
            $srcHost = array_reverse(explode('.', $urlParsed['host']));
            $baseParsed = parse_url($updir['baseurl']);
            $baseurlHost = array_reverse(explode('.', $baseParsed['host']));
            if ($srcHost[0] == $baseurlHost[0] && $srcHost[1] == $baseurlHost[1]
                && (strlen($srcHost[1]) > 3 || isset($srcHost[2]) && isset($srcHost[2]) && $srcHost[2] == $baseurlHost[2])) {
                $baseurl = str_replace($baseParsed['scheme'] . '://' . $baseParsed['host'], $urlParsed['scheme'] . '://' . $urlParsed['host'], $updir['baseurl']);
                $imageBase = str_replace($baseurl, SHORTPIXEL_UPLOADS_BASE, $src);
            }
            if ($imageBase == $src) { //looks like it's an external URL though...
                if(isset($_GET['SHORTPIXEL_DEBUG'])) WPShortPixel::log('SPDBG baseurl ' . $updir['baseurl'] . ' doesn\'t match ' . $src, true);
                return $match[0] . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG baseurl ' . $updir['baseurl'] . ' doesn\'t match ' . $src . '  -->' : '');
            }
        }
        $imageBase = dirname($imageBase) . '/';

        // We don't wanna have src-ish attributes on the <picture>
        unset($img['src']);
        unset($img['data-src']);
        unset($img['data-lazy-src']);
        unset($img['srcset']);
        unset($img['sizes']);
        unset($img['alt']);
        $srcsetWebP = '';
        if ($srcset) {
            $defs = explode(",", $srcset);
            foreach ($defs as $item) {
                $parts = preg_split('/\s+/', trim($item));

                //echo(" file: " . $parts[0] . " ext: " . pathinfo($parts[0], PATHINFO_EXTENSION) . " basename: " . wp_basename($parts[0], '.' . pathinfo($parts[0], PATHINFO_EXTENSION)));

                $fileWebPCompat = $imageBase . wp_basename($parts[0], '.' . pathinfo($parts[0], PATHINFO_EXTENSION)) . '.webp';
                $fileWebP = $imageBase . wp_basename($parts[0]) . '.webp';
                if (file_exists($fileWebP)) {
                    $srcsetWebP .= (strlen($srcsetWebP) ? ',': '')
                        . $parts[0].'.webp'
                     . (isset($parts[1]) ? ' ' . $parts[1] : '');
                }
                if (file_exists($fileWebPCompat)) {
                    $srcsetWebP .= (strlen($srcsetWebP) ? ',': '')
                       .preg_replace('/\.[a-zA-Z0-9]+$/', '.webp', $parts[0])
                       .(isset($parts[1]) ? ' ' . $parts[1] : '');
                }
            }
            //$srcsetWebP = preg_replace('/\.[a-zA-Z0-9]+\s+/', '.webp ', $srcset);
        } else {
            $srcset = trim($src);

//                die(var_dump($match));

            $fileWebPCompat = $imageBase . wp_basename($srcset, '.' . pathinfo($srcset, PATHINFO_EXTENSION)) . '.webp';
            $fileWebP = $imageBase . wp_basename($srcset) . '.webp';
            if (file_exists($fileWebP)) {
                $srcsetWebP = $srcset.".webp";
            } else {
                if (file_exists($fileWebPCompat)) {
                    $srcsetWebP = preg_replace('/\.[a-zA-Z0-9]+$/', '.webp', $srcset);
                }
            }
        }
        //return($match[0]. "<!-- srcsetTZF:".$srcsetWebP." -->");
        if (!strlen($srcsetWebP)) {
            if(isset($_GET['SHORTPIXEL_DEBUG'])) WPShortPixel::log('SPDBG no srcsetWebP found (' . $srcsetWebP . ')', true);
            return $match[0] . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG no srcsetWebP found (' . $srcsetWebP . ') -->' : '');
        }

        //add the exclude class so if this content is processed again in other filter, the img is not converted again in picture
        $img['class'] = (isset($img['class']) ? $img['class'] . " " : "") . "sp-no-webp";

        if(isset($_GET['SHORTPIXEL_DEBUG'])) WPShortPixel::log('SPDBG returning picture tag for ' . $src, true);

        return '<picture ' . self::create_attributes($img) . '>'
        .'<source ' . $srcsetPrefix . 'srcset="' . $srcsetWebP . '"' . ($sizes ? ' ' . $sizesPrefix . 'sizes="' . $sizes . '"' : '') . ' type="image/webp">'
        .'<source ' . $srcsetPrefix . 'srcset="' . $srcset . '"' . ($sizes ? ' ' . $sizesPrefix . 'sizes="' . $sizes . '"' : '') . '>'
        .'<img ' . $srcPrefix . 'src="' . $src . '" ' . self::create_attributes($img) . $altAttr
            . (strlen($srcset) ? ' srcset="' . $srcset . '"': '') . (strlen($sizes) ? ' sizes="' . $sizes . '"': '') . '>'
        .'</picture>';
    }

    public static function get_attributes($image_node)
    {
        if (function_exists("mb_convert_encoding")) {
            $image_node = mb_convert_encoding($image_node, 'HTML-ENTITIES', 'UTF-8');
        }
        $dom = new DOMDocument();
        @$dom->loadHTML($image_node);
        $image = $dom->getElementsByTagName('img')->item(0);
        $attributes = array();
        foreach ($image->attributes as $attr) {
            $attributes[$attr->nodeName] = $attr->nodeValue;
        }
        return $attributes;
    }

    /**
     * Makes a string with all attributes.
     *
     * @param $attribute_array
     * @return string
     */
    public static function create_attributes($attribute_array)
    {
        $attributes = '';
        foreach ($attribute_array as $attribute => $value) {
            $attributes .= $attribute . '="' . $value . '" ';
        }
        // Removes the extra space after the last attribute
        return substr($attributes, 0, -1);
    }

    /**
     * @param $image_url
     * @return array
     */
    public static function url_to_attachment_id($image_url)
    {
        // Thx to https://github.com/kylereicks/picturefill.js.wp/blob/master/inc/class-model-picturefill-wp.php
        global $wpdb;
        $original_image_url = $image_url;
        $image_url = preg_replace('/^(.+?)(-\d+x\d+)?\.(jpg|jpeg|png|gif)((?:\?|#).+)?$/i', '$1.$3', $image_url);
        $prefix = $wpdb->prefix;
        $attachment_id = $wpdb->get_col($wpdb->prepare("SELECT ID FROM " . $prefix . "posts" . " WHERE guid='%s';", $image_url));

        //try the other proto (https - http) if full urls are used
        if (empty($attachment_id) && strpos($image_url, 'http://') === 0) {
            $image_url_other_proto =  strpos($image_url, 'https') === 0 ?
                str_replace('https://', 'http://', $image_url) :
                str_replace('http://', 'https://', $image_url);
            $attachment_id = $wpdb->get_col($wpdb->prepare("SELECT ID FROM " . $prefix . "posts" . " WHERE guid='%s';", $image_url_other_proto));
        }

        //try using only path
        if (empty($attachment_id)) {
            $image_path = parse_url($image_url, PHP_URL_PATH); //some sites have different domains in posts guid (site changes, etc.)
            $attachment_id = $wpdb->get_col($wpdb->prepare("SELECT ID FROM " . $prefix . "posts" . " WHERE guid like'%%%s';", $image_path));
        }

        //try using the initial URL
        if (empty($attachment_id)) {
            $attachment_id = $wpdb->get_col($wpdb->prepare("SELECT ID FROM " . $prefix . "posts" . " WHERE guid='%s';", $original_image_url));
        }
        return !empty($attachment_id) ? $attachment_id[0] : false;
    }
}
