<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Class ShortPixelImgToPictureWebp - convert an <img> tag to a <picture> tag and add the webp versions of the images
 * thanks to the Responsify WP plugin for some of the code
 */
class ShortPixelImgToPictureWebp
{

    public function convert($content)
    {
        // Don't do anything with the RSS feed.
        if (is_feed() || is_admin()) {
            Log::addInfo('SPDBG convert is_feed or is_admin');
            return $content; // . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!--  -->' : '');
        }

        $new_content = $this->testPictures($content);

        if ($new_content !== false)
        {
          $content = $new_content;
        }
        else
        {
          Log::addDebug('Test Pictures returned empty.');
        }


			//	preg_match_all
        $content = preg_replace_callback('/<img[^>]*>/i', array($this, 'convertImage'), $content);
        //$content = preg_replace_callback('/background.*[^:](url\(.*\)[,;])/im', array('self', 'convertInlineStyle'), $content);

        // [BS] No callback because we need preg_match_all
        $content = $this->testInlineStyle($content);
      //  $content = preg_replace_callback('/background.*[^:]url\([\'|"](.*)[\'|"]\)[,;]/imU',array('self', 'convertInlineStyle'), $content);
      //  Log::addDebug('SPDBG WebP process done');

        return $content; // . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG WebP converted -->' : '');

    }

    /** If lazy loading is happening, get source (src) from those values
    * Otherwise pass back image data in a regular way.
    */
    private function lazyGet($img, $type)
    {

      $value = false;
      $prefix = false;

       if (isset($img['data-lazy-' . $type]) && strlen($img['data-lazy-' . $type]) > 0)
       {
           $value = $img['data-lazy-' . $type];
           $prefix = 'data-lazy-';
       }
       elseif( isset($img['data-' . $type]) && strlen($img['data-' . $type]) > 0)
       {
          $value = $img['data-' . $type];
          $prefix = 'data-';
       }
       elseif(isset($img[$type]) && strlen($img[$type]) > 0)
       {
          $value = $img[$type];
          $prefix = '';
       }

      return array(
        'value' => $value,
        'prefix' => $prefix,
       );
    }

    /* Find image tags within picture definitions and make sure they are converted only by block, */
    private function testPictures($content)
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

    /** Callback function with received an <img> tag match
    * @param $match Image declaration block
    * @return String Replacement image declaration block
    */
    protected function convertImage($match)
    {
        $fs = \wpSPIO()->filesystem();
        // Do nothing with images that have the 'sp-no-webp' class.
        if (strpos($match[0], 'sp-no-webp') || strpos($match[0], 'rev-sildebg')) {
            Log::addInfo('SPDBG convertImage skipped, sp-no-webp found');
            return $match[0]; //. (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG convertImage sp-no-webp -->' : '');
        }

        $img = $this->get_attributes($match[0]);
        if(isset($img['style']) && strpos($img['style'], 'background') !== false) {
            //don't replace for <img>'s that have background
            return $match[0];
        }

        // [BS] Can return false in case of Module fail. Escape in that case with unmodified image
        if ($img === false)
        {
          Log::addDebug('Webp convert failed, no image found in convertImage');
          return $match[0];
        }

        if (! isset($img['src']) && ! isset($img['srcset']))
        {
           return $match[0];
        }

        $srcInfo = $this->lazyGet($img, 'src');
        $srcsetInfo = $this->lazyGet($img, 'srcset');
        $sizesInfo = $this->lazyGet($img, 'sizes');


				// FILTERS : FileDir (OBJECT) - URL
        $imageBase = apply_filters( 'shortpixel_webp_image_base', $this->getImageBase($srcInfo['value']), $srcInfo['value']);

        if($imageBase === false) {
            Log::addDebug('SPDBG baseurl doesn\'t match ' . $srcInfo['value'], array($imageBase) );
            return $match[0]; // . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG baseurl doesn\'t match ' . $src . '  -->' : '');
        }

        //some attributes should not be moved from <img>
        // @todo Move these to unset on (imgpicture) and put via create_attributes back
        $altAttr = isset($img['alt'])  ? ' alt="' . $img['alt'] . '"' : '';
        $idAttr = isset($img['id']) && strlen($img['id']) ? ' id="' . $img['id'] . '"' : '';
        $heightAttr = isset($img['height']) && strlen($img['height']) ? ' height="' . $img['height'] . '"' : '';
        $widthAttr = isset($img['width']) && strlen($img['width']) ? ' width="' . $img['width'] . '"' : '';


        // We don't wanna have src-ish attributes on the <picture>
        unset($img['src']);
        unset($img['data-src']);
        unset($img['data-lazy-src']);
        unset($img['srcset']);
        //unset($img['loading']);
      //  unset($img['data-srcset']); // lazyload - don't know if this solves anything.
        unset($img['sizes']);


        //nor the ones that belong to <img>
        unset($img['alt']);
        unset($img['id']);
        unset($img['width']);
        unset($img['height']);

        $srcsetWebP = array();
        $srcsetAvif = array();
				// Count real instances of either of them, without fillers.
				$webpCount = $avifCount = 0;


        $imagePaths = array();

        if ($srcsetInfo['value']) {
            $definitions = explode(',', $srcsetInfo['value']);
        }
        else
        {
            $definitions = array($srcInfo['value']);
        }

          //  $defs = explode(",", $srcset);
          $mime = ''; // is_infinite

        foreach ($definitions as $item) {

								// Split the URL from the size definition ( eg 800w )
                $parts = preg_split('/\s+/', trim($item));

                $fileurl = $parts[0];
                // A source that starts with data:, will not need processing.
                if (strpos($fileurl, 'data:') === 0)
                  continue;

								// The space if not set is required, otherwise it will not work.
                $condition = isset($parts[1]) ? ' ' . $parts[1] : ' ';

                $fsFile = $fs->getFile($fileurl);
                $extension = $fsFile->getExtension(); // trigger setFileinfo, which will resolve URL -> Path

                $mime = $fsFile->getMime();
								// Can happen when file is virtual, or other cases. Just assume this type.
								if ($mime === false)
								{
									 $mime = 'image/' .  $extension;
								}

                $fileWebp = $fs->getFile($imageBase . $fsFile->getFileBase() . '.webp');
                $fileWebpCompat = $fs->getFile($imageBase . $fsFile->getFileName() . '.webp');

                $fileurl_base = str_replace($fsFile->getFileName(), '', $fileurl);
                $files = array($fileWebp, $fileWebpCompat);

                $fileAvif = $fs->getFile($imageBase . $fsFile->getFileBase() . '.avif');

                foreach($files as $thisfile)
                {
                  if (! $thisfile->exists())
                  {
										// FILTER: boolean, object, string, filedir
                    $thisfile = $fileWebp_exists = apply_filters('shortpixel/front/webp_notfound', false, $thisfile, $fileurl, $imageBase);
                  }

                  if ($thisfile !== false)
                  {
                      // base url + found filename + optional condition ( in case of sourceset, as in 1400w or similar)
                      Log::addDebug('Adding new URL', $fileurl_base . $thisfile->getFileName() . $condition);
											$webpCount++;

                       $srcsetWebP[] = $fileurl_base . $thisfile->getFileName() . $condition;
                       break;
                  }
									else {
											$srcsetWebP[] = $fileurl . $condition;
									}
                }

								//@todo This will not work with offloaded avifs.
                if ($fileAvif->exists())
                {
                   $fileurl_base = str_replace($fsFile->getFileName(), '', $fileurl);
                   $srcsetAvif[] = $fileurl_base . $fileAvif->getFileName() . $condition;
									 $avifCount++;
                }
								else { //fallback to jpg
										$srcsetAvif[] = $fileurl . $condition;
								}
        }

        if ($webpCount == 0 && $avifCount == 0) {

            return $match[0]; //. (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG no srcsetWebP found (' . $srcsetWebP . ') -->' : '');
        }

        //add the exclude class so if this content is processed again in other filter, the img is not converted again in picture
        $img['class'] = (isset($img['class']) ? $img['class'] . " " : "") . "sp-no-webp";

        $imgpicture = $img;
        // remove certain elements for the main picture element.
        $imgpicture = $this->filterForPicture($imgpicture);

        $sizes = $sizesInfo['value'];
        $sizesPrefix = $sizesInfo['prefix'];

        $srcsetPrefix = $srcsetInfo['value'] ? $srcsetInfo['prefix'] : $srcInfo['prefix'];
        $srcset = $srcsetInfo['value'];

        $src = trim($srcInfo['value']);
        if (! $srcset)
          $srcset = $src; // if not srcset ( it's a src ), replace those.
        $srcPrefix = $srcInfo['prefix'];

      //  $srcsetWebP = implode(',', $srcsetWebP);

        $output = '<picture ' . $this->create_attributes($imgpicture) . '>';

        if (is_array($srcsetAvif) && $avifCount > 0)
        {
            $srcsetAvif = implode(',', array_unique($srcsetAvif));
            $output .= '<source ' . $srcsetPrefix . 'srcset="' . $srcsetAvif . '"' . ($sizes ? ' ' . $sizesPrefix . 'sizes="' . $sizes . '"' : '') . ' type="image/avif">';
        }
        if (is_array($srcsetWebP) && $webpCount > 0)
        {
          $srcsetWebP = implode(',', array_unique($srcsetWebP));
          $output .= '<source ' . $srcsetPrefix . 'srcset="' . $srcsetWebP . '"' . ($sizes ? ' ' . $sizesPrefix .  'sizes="' . $sizes . '"' : '') . ' type="image/webp">';
        }
        $output .= '<source ' . $srcsetPrefix . 'srcset="' . $srcset . '"' . ($sizes ? ' ' . $sizesPrefix . 'sizes="' . $sizes . '"' : '') . ' type="' . $mime  . '">'
        .'<img ' . $srcPrefix . 'src="' . $src . '" ' . $this->create_attributes($img) . $idAttr . $altAttr . $heightAttr . $widthAttr
            . (strlen($srcset) ? ' srcset="' . $srcset . '"': '') . (strlen($sizes) ? ' sizes="' . $sizes . '"': '') . '>'
        .'</picture>';

        return $output;
    }

    /** Check and remove elements that should not be in the picture tag. Especially items within attributes. */
    private function filterForPicture($img)
    {

      if (isset($img['style']))
      {
         $bordercount = substr_count($img['style'], 'border');
         for ($i = 0; $i <= $bordercount; $i++)
         {
           $offset = strpos($img['style'], 'border');
           $end = strpos($img['style'], ';', $offset);

           $nstyle = substr($img['style'], 0, $offset);

           // if end is false, ; terminator does not exist, assume full string is border.
           if ($end !== false)
              $nstyle .= substr($img['style'], ($end+1) ); // do not include ;

              $img['style'] = $nstyle;
         }
      }

      return $img;
    }

    public function testInlineStyle($content)
    {
      //preg_match_all('/background.*[^:](url\(.*\))[;]/isU', $content, $matches);
      preg_match_all('/url\(.*\)/isU', $content, $matches);

      if (count($matches) == 0)
        return $content;

      $content = $this->convertInlineStyle($matches, $content);
      return $content;
    }

    /** Function to convert inline CSS backgrounds to webp
    * @param $match Regex match for inline style
    * @return String Replaced (or not) content for webp.
    * @author Bas Schuiling
    */
    public function convertInlineStyle($matches, $content)
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
        //$parsed_url = parse_url($url);
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
        else
        {
          Log::addDebug('convertInlineStyle, no webp existing', $checkedFile);
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
    public function getImageBase($src)
    {

      $fs = \wpSPIO()->filesystem();
      $fileObj = $fs->getFile($src);
      $fileDir = $fileObj->getFileDir();

      return $fileObj->getFileDir();  // Testing, the rest might be unneeded.

    }

    public function get_attributes($image_node)
    {
        if (function_exists("mb_convert_encoding")) {
            $image_node = mb_encode_numericentity($image_node, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
						//mb_convert_encoding($image_node, 'HTML-ENTITIES', 'UTF-8');
        }
        // [BS] Escape when DOM Module not installed
        if (! class_exists('DOMDocument'))
        {
          Log::addWarn('Webp Active, but DomDocument class not found ( missing xmldom library )');
          return false;
        }
        $dom = new \DOMDocument();
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
    public function create_attributes($attribute_array)
    {
        $attributes = '';
        foreach ($attribute_array as $attribute => $value) {
            $attributes .= $attribute . '="' . $value . '" ';
        }

        // Removes the extra space after the last attribute
        return substr($attributes, 0, -1);
    }

}
