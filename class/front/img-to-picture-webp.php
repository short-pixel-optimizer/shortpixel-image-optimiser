<?php
/**
 * Class ShortPixelImgToPictureWebp - convert an <img> tag to a <picture> tag and add the webp versions of the images
 * thanks to the Responsify WP plugin for some of the code
 */

//use ShortPixel\DebugItem as DebugItem;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

class ShortPixelImgToPictureWebp
{
    /** If lazy loading is happening, get source (src) from those values */
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
            Log::addInfo('SPDBG convert is_feed or is_admin');
            return $content; // . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!--  -->' : '');
        }

        $new_content = self::testPictures($content);
        if ($new_content !== false)
          $content = $new_content;

        $content = preg_replace_callback('/<img[^>]*>/i', array('self', 'convertImage'), $content);
        //$content = preg_replace_callback('/background.*[^:](url\(.*\)[,;])/im', array('self', 'convertInlineStyle'), $content);

        // [BS] No callback because we need preg_match_all
        $content = self::testInlineStyle($content);
      //  $content = preg_replace_callback('/background.*[^:]url\([\'|"](.*)[\'|"]\)[,;]/imU',array('self', 'convertInlineStyle'), $content);
        Log::addDebug('SPDBG WebP process done');

        return $content; // . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG WebP converted -->' : '');

    }

    public static function testPictures($content)
    {
      // [BS] Escape when DOM Module not installed
      //if (! class_exists('DOMDocument'))
      //  return false;

    //$pattern =''
    //$pattern ='/(?<=(<picture>))(.*)(?=(<\/picture>))/mi';
    $pattern = '/<picture.*?>.*?(<img.*?>).*?<\/picture>/is';
    preg_match_all($pattern, $content, $matches);

    if ($matches === false)
      return false;

    if ( is_array($matches) && count($matches) > 0)
    {
      foreach($matches[1] as $match)
      {
           $imgtag = $match;

           if (strpos($imgtag, 'class=') !== false) // test for class, if there, insert ours in there.
           {
            $pos = strpos($imgtag, 'class=');
            $pos = $pos + 7;

            $newimg = substr($imgtag, 0, $pos) . 'sp-no-webp ' . substr($imgtag, $pos);

           }
           else {
              $pos = 4;
              $newimg = substr($imgtag, 0, $pos) . ' class="sp-no-webp" ' . substr($imgtag, $pos);
           }

           $content = str_replace($imgtag, $newimg, $content);

      }
    }



    return $content;

    }

    /* This might be a future solution for regex callbacks.
    public static function processImageNode($node, $type)
    {
      $srcsets = $node->getElementsByTagName('srcset');
      $srcs = $node->getElementsByTagName('src');
      $imgs = $node->getElementsByTagName('img');
    } */

    public static function convertImage($match)
    {
        // Do nothing with images that have the 'sp-no-webp' class.
        if (strpos($match[0], 'sp-no-webp')) {
            Log::addInfo('SPDBG convertImage skipped, sp-no-webp found');
            return $match[0]; //. (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG convertImage sp-no-webp -->' : '');
        }

        $img = self::get_attributes($match[0]);
        // [BS] Can return false in case of Module fail. Escape in that case with unmodified image
        if ($img === false)
          return $match[0];

        $srcInfo = self::lazyGet($img, 'src');
        $src = $srcInfo['value'];
        $parsed_url = parse_url($src);
        $srcPrefix = $srcInfo['prefix'];

        $srcsetInfo = self::lazyGet($img, 'srcset');
        $srcset = $srcsetInfo['value'];
        $srcsetPrefix = $srcset ? $srcsetInfo['prefix'] : $srcInfo['prefix'];

        $sizesInfo = self::lazyGet($img, 'sizes');
        $sizes = $sizesInfo['value'];
        $sizesPrefix = $sizesInfo['prefix'];

        //check if there are webps
        /*$id = $thisClass::url_to_attachment_id( $src );
        if(!$id) {
            return $match[0];
        }
        $imageBase = dirname(get_attached_file($id)) . '/';
        */

        /* [BS] $updir = wp_upload_dir();
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
        } */


        /* [BS] $imageBase = str_replace($updir['baseurl'], SHORTPIXEL_UPLOADS_BASE, $src);
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
        */

        $imageBase = apply_filters( 'shortpixel_webp_image_base', static::getImageBase($src), $src);

        if($imageBase === false) {
            return $match[0]; // . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG baseurl doesn\'t match ' . $src . '  -->' : '');
            Log::addInfo('SPDBG baseurl doesn\'t match ' . $src);
        }

        //some attributes should not be moved from <img>
        $altAttr = isset($img['alt']) && strlen($img['alt']) ? ' alt="' . $img['alt'] . '"' : '';
        $idAttr = isset($img['id']) && strlen($img['id']) ? ' id="' . $img['id'] . '"' : '';
        $heightAttr = isset($img['height']) && strlen($img['height']) ? ' height="' . $img['height'] . '"' : '';
        $widthAttr = isset($img['width']) && strlen($img['width']) ? ' width="' . $img['width'] . '"' : '';

        // We don't wanna have src-ish attributes on the <picture>
        unset($img['src']);
        unset($img['data-src']);
        unset($img['data-lazy-src']);
        unset($img['srcset']);
        unset($img['sizes']);
        //nor the ones that belong to <img>
        unset($img['alt']);
        unset($img['id']);
        unset($img['width']);
        unset($img['height']);

        $srcsetWebP = '';

        if ($srcset) {
            $defs = explode(",", $srcset);
            foreach ($defs as $item) {
                $parts = preg_split('/\s+/', trim($item));

                //echo(" file: " . $parts[0] . " ext: " . pathinfo($parts[0], PATHINFO_EXTENSION) . " basename: " . wp_basename($parts[0], '.' . pathinfo($parts[0], PATHINFO_EXTENSION)));

                $fileWebPCompat = $imageBase . wp_basename($parts[0], '.' . pathinfo($parts[0], PATHINFO_EXTENSION)) . '.webp';
                $fileWebP = $imageBase . wp_basename($parts[0]) . '.webp';
                if (apply_filters( 'shortpixel_image_exists', file_exists($fileWebP), $fileWebP)) {
                    $srcsetWebP .= (strlen($srcsetWebP) ? ',': '')
                        . $parts[0].'.webp'
                     . (isset($parts[1]) ? ' ' . $parts[1] : '');
                }
                if (apply_filters( 'shortpixel_image_exists', file_exists($fileWebPCompat), $fileWebPCompat)) {
                    $srcsetWebP .= (strlen($srcsetWebP) ? ',': '')
                       .preg_replace('/\.[a-zA-Z0-9]+$/', '.webp', $parts[0])
                       .(isset($parts[1]) ? ' ' . $parts[1] : '');
                }
                else {
                    Log::addDebug('Image srcset for webp doesn\'t exist', array($fileWebP));
                }
            }
            //$srcsetWebP = preg_replace('/\.[a-zA-Z0-9]+\s+/', '.webp ', $srcset);
        } else {
            $srcset = trim($src);


            $fileWebPCompat = $imageBase . wp_basename($srcset, '.' . pathinfo($srcset, PATHINFO_EXTENSION)) . '.webp';
            $fileWebP = $imageBase . wp_basename($srcset) . '.webp';
            if (apply_filters( 'shortpixel_image_exists', file_exists($fileWebP), $fileWebP)) {
                $srcsetWebP = $srcset.".webp";
            } else {
                if (apply_filters( 'shortpixel_image_exists', file_exists($fileWebPCompat), $fileWebPCompat) ) {
                    $srcsetWebP = preg_replace('/\.[a-zA-Z0-9]+$/', '.webp', $srcset);
                }
                else {
                  Log::addDebug('Image file for webp doesn\'t exist', array($fileWebP));
                }
            }
        }
        //return($match[0]. "<!-- srcsetTZF:".$srcsetWebP." -->");
        if (!strlen($srcsetWebP)) {
            return $match[0]; //. (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG no srcsetWebP found (' . $srcsetWebP . ') -->' : '');
            Log::addInfo(' SPDBG no srcsetWebP found (' . $srcsetWebP . ')');
        }

        //add the exclude class so if this content is processed again in other filter, the img is not converted again in picture
        $img['class'] = (isset($img['class']) ? $img['class'] . " " : "") . "sp-no-webp";

        return '<picture ' . self::create_attributes($img) . '>'
        .'<source ' . $srcsetPrefix . 'srcset="' . $srcsetWebP . '"' . ($sizes ? ' ' . $sizesPrefix . 'sizes="' . $sizes . '"' : '') . ' type="image/webp">'
        .'<source ' . $srcsetPrefix . 'srcset="' . $srcset . '"' . ($sizes ? ' ' . $sizesPrefix . 'sizes="' . $sizes . '"' : '') . '>'
        .'<img ' . $srcPrefix . 'src="' . $src . '" ' . self::create_attributes($img) . $idAttr . $altAttr . $heightAttr . $widthAttr
            . (strlen($srcset) ? ' srcset="' . $srcset . '"': '') . (strlen($sizes) ? ' sizes="' . $sizes . '"': '') . '>'
        .'</picture>';
    }

    public static function testInlineStyle($content)
    {
      //preg_match_all('/background.*[^:](url\(.*\))[;]/isU', $content, $matches);
      preg_match_all('/url\(.*\)/isU', $content, $matches);

      if (count($matches) == 0)
        return $content;

      $content = self::convertInlineStyle($matches, $content);
      return $content;
    }

    /** Function to convert inline CSS backgrounds to webp
    * @param $match Regex match for inline style
    * @return String Replaced (or not) content for webp.
    * @author Bas Schuiling
    */
    public static function convertInlineStyle($matches, $content)
    {
      // ** matches[0] = url('xx') matches[1] the img URL.
//      preg_match_all('/url\(\'(.*)\'\)/imU', $match, $matches);

  //    if (count($matches)  == 0)
  //      return $match; // something wrong, escape.

      //$content = $match;
      $allowed_exts = array('jpg', 'jpeg', 'gif', 'png');
      $converted = array();

      for($i = 0; $i < count($matches[0]); $i++)
      {
        $item = $matches[0][$i];

        preg_match('/url\(\'(.*)\'\)/imU', $item, $match);
        if (! isset($match[1]))
          continue;

        $url = $match[1];
        $parsed_url = parse_url($url);
        $filename = basename($url);

        $fileonly = pathinfo($url, PATHINFO_FILENAME);
        $ext = pathinfo($url, PATHINFO_EXTENSION);

        if (! in_array($ext, $allowed_exts))
          continue;

        $imageBaseURL = str_replace($filename, '', $url);

        $imageBase = static::getImageBase($url);

        if (! $imageBase) // returns false if URL is external, do nothing with that.
          continue;

        $checkedFile = false;
        if (file_exists($imageBase . $fileonly . '.' . $ext . '.webp'))
        {
          $checkedFile = $imageBaseURL . $fileonly . '.' . $ext . '.webp';
        }
        elseif (file_exists($imageBase . $fileonly . '.webp'))
        {
          $checkedFile = $imageBaseURL . $fileonly . '.webp';
        }

        if ($checkedFile)
        {
            // if webp, then add another URL() def after the targeted one.  (str_replace old full URL def, with new one on main match?
            $target_urldef = $matches[0][$i];
            if (! isset($converted[$target_urldef])) // if the same image is on multiple elements, this replace might go double. prevent.
            {
              $converted[] = $target_urldef;
              $new_urldef = "url('" . $checkedFile . "'), " . $target_urldef;
              $content = str_replace($target_urldef, $new_urldef, $content);
            }
        }

      }

      return $content;
    }

    /* ** Utility function to get ImageBase.
    **  @param String $src Image Source
    **  @returns String The Image Base
    **/
    public static function getImageBase($src)
    {
        $urlParsed = parse_url($src);
        if(!isset($urlParsed['host'])) {
            if($src[0] == '/') { //absolute URL, current domain
                $src = get_site_url() . $src;
            } else {
                global $wp;
                $src = trailingslashit(home_url( $wp->request )) . $src;
            }
            $urlParsed = parse_url($src);
        }
      $updir = wp_upload_dir();
      if(substr($src, 0, 2) == '//') {
          $src = (stripos($_SERVER['SERVER_PROTOCOL'],'https') === false ? 'http:' : 'https:') . $src;
      }
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
      if ($imageBase == $src) { //for themes images or other non-uploads paths
          $imageBase = str_replace(content_url(), WP_CONTENT_DIR, $src);
      }

      if ($imageBase == $src) { //maybe the site uses a CDN or a subdomain? - Or relative link
          $baseParsed = parse_url($updir['baseurl']);

          $srcHost = array_reverse(explode('.', $urlParsed['host']));
          $baseurlHost = array_reverse(explode('.', $baseParsed['host']));

          if ($srcHost[0] == $baseurlHost[0] && $srcHost[1] == $baseurlHost[1]
              && (strlen($srcHost[1]) > 3 || isset($srcHost[2]) && isset($srcHost[2]) && $srcHost[2] == $baseurlHost[2])) {

              $baseurl = str_replace($baseParsed['scheme'] . '://' . $baseParsed['host'], $urlParsed['scheme'] . '://' . $urlParsed['host'], $updir['baseurl']);
              $imageBase = str_replace($baseurl, SHORTPIXEL_UPLOADS_BASE, $src);
          }
          if ($imageBase == $src) { //looks like it's an external URL though...
              return false;
          }
      }


        $imageBase = trailingslashit(dirname($imageBase));
        return $imageBase;
    }

    public static function get_attributes($image_node)
    {
        if (function_exists("mb_convert_encoding")) {
            $image_node = mb_convert_encoding($image_node, 'HTML-ENTITIES', 'UTF-8');
        }
        // [BS] Escape when DOM Module not installed
        if (! class_exists('DOMDocument'))
        {
          Log::addWarn('Webp Active, but DomDocument class not found ( missing xmldom library )');
          return false;
        }
        $dom = new DOMDocument();
        @$dom->loadHTML($image_node);
        $image = $dom->getElementsByTagName('img')->item(0);
        $attributes = array();

        /* This can happen with mismatches, or extremely malformed HTML.
        In customer case, a javascript that did  for (i<imgDefer) --- </script> */
        if (! is_object($image))
          return false;

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
