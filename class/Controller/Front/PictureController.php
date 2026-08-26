<?php
namespace ShortPixel\Controller\Front;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;
use ShortPixel\Helper\UtilHelper as UtilHelper;
use ShortPixel\Model\FrontImage as FrontImage;

use ShortPixel\ShortPixelImgToPictureWebp as ShortPixelImgToPictureWebp;

/**
 * Front-end WebP/AVIF <picture>-tag injection controller.
 *
 * PictureController extends PageConverter to convert <img> tags and inline CSS
 * url() backgrounds to use locally-hosted WebP and AVIF files.  It operates
 * in one of two delivery modes controlled by the deliverWebp plugin setting:
 *
 *  - WEBP_GLOBAL (1): Opens an output buffer (via PageConverter::startOutputBuffer)
 *    so that the entire rendered page passes through convertImgToPictureAddWebp()
 *    before being sent to the browser.
 *  - WEBP_WP (2): Hooks convertImgToPictureAddWebp() on a set of WordPress
 *    content filters (the_content, the_excerpt, post_thumbnail_html,
 *    wp_get_attachment_image) at high priority so only plugin-rendered HTML is
 *    transformed.  The filter set is extensible via the
 *    'shortpixel/front/picture_webp_filters' filter.
 *
 * HTML transform pipeline (convert()):
 *  1. testPictures(): finds <img> tags already inside <picture> elements and
 *     marks them with class="sp-no-webp" so they are skipped by convertImage().
 *  2. preg_replace_callback on /<img …>/: calls convertImage() for each tag.
 *  3. testInlineStyle(): finds CSS url() declarations and delegates to
 *     convertInlineStyle() which replaces them with WebP equivalents.
 *
 * File resolution: for each image, the controller looks for a .webp file at
 * the same path with either the base name (image.webp) or the full name
 * (image.jpg.webp) and for .avif similarly.  The 'shortpixel/front/webp_notfound'
 * filter allows third-party code to supply an alternative file location.
 *
 * AMP pages are excluded (returns early when amp_is_request() is true) because
 * the <picture> element is not permitted by AMP.
 */
class PictureController extends \ShortPixel\Controller\Front\PageConverter
{
  /** @var int deliverWebp mode: output-buffer the full page. */
  const WEBP_GLOBAL = 1;

  /** @var int deliverWebp mode: hook on standard WP content filters only. */
  const WEBP_WP = 2;

  /** @var int deliverWebp mode: no front-end conversion (pass-through). */
  const WEBP_NOCHANGE = 3;

  /**
   * Registers the init hook that sets up WebP delivery based on plugin settings.
   */
  public function __construct()
  {
			add_action('init', [$this, 'initWebpHooks']);
  }

	/**
	 * Wires up WebP/AVIF delivery hooks based on the deliverWebp plugin setting.
	 *
	 * Called on the 'init' action.  Bails early via shouldConvert() for admin,
	 * AJAX, cron, and page-builder contexts.  Warns (admin notice) if the
	 * ShortPixel Adaptive Images plugin is active alongside this feature, since
	 * the two conflict.
	 *
	 * For WEBP_GLOBAL: starts an output buffer whose callback is
	 * convertImgToPictureAddWebp(), so the full rendered page is transformed.
	 * For WEBP_WP: attaches convertImgToPictureAddWebp() to the filter list
	 * defined by 'shortpixel/front/picture_webp_filters'.
	 *
	 * @return false|void False when shouldConvert() returns false; void otherwise.
	 */
	public function initWebpHooks()
  {
    $webp_option = \wpSPIO()->settings()->deliverWebp;
    if (false === $this->shouldConvert())
    {
       return false;
    }


		if ($webp_option ) {  // @tood Replace this function with the one in ENV.
        if(UtilHelper::shortPixelIsPluginActive('shortpixel-adaptive-images/short-pixel-ai.php')) {
            Notices::addWarning(__('Please deactivate the ShortPixel Image Optimizer\'s
                <a href="options-general.php?page=wp-shortpixel-settings&part=webp">Serve WebP/AVIF images from locally hosted files (without using a CDN)</a>
                option when the ShortPixel Adaptive Images plugin is active.','shortpixel-image-optimiser'), true);
        }
        elseif( $webp_option == self::WEBP_GLOBAL ){
            //add_action( 'wp_head', array($this, 'addPictureJs') ); // adds polyfill JS to the header || Removed. Browsers without picture support?
					 // add_action( 'init',  array($this, 'startOutputBuffer'), 1 ); // start output buffer to capture content
						$this->startOutputBuffer('convertImgToPictureAddWebp');

        } else {

            $filters = [
                'the_content' => 10000, 
                'the_excerpt' => 10000, 
                'post_thumbnail_html' => 10, 
                'wp_get_attachment_image' => 10, 
            ];

            $hook = [$this, 'convertImgToPictureAddWebp'];

            $filters = apply_filters('shortpixel/front/picture_webp_filters', $filters);
            
            foreach($filters as $filter => $priority)
            {
                  add_filter($filter, $hook, $priority);
            }

            /*
            add_filter( 'the_content', array($this, 'convertImgToPictureAddWebp'), 10000 ); // priority big, so it will be executed last
            add_filter( 'the_excerpt', array($this, 'convertImgToPictureAddWebp'), 10000 );
            add_filter( 'post_thumbnail_html', array($this,'convertImgToPictureAddWebp') );
            add_filter( 'wp_get_attachment_image', array($this,'convertImgToPictureAddWebp'));
            */
        }
    }
  }


  /**
   * Entry-point filter/buffer callback: converts <img> and CSS backgrounds in HTML to WebP/AVIF.
   *
   * Used as both the output-buffer callback (WEBP_GLOBAL mode) and as a
   * WordPress content filter (WEBP_WP mode).  Bails early for 404 responses
   * (checkPreProcess) and for AMP pages (amp_is_request()), where the <picture>
   * element is not allowed.
   *
   * @param string $content HTML content to transform.
   * @return string Transformed content with <img> tags wrapped in <picture> elements
   *                and inline CSS url() values replaced with WebP equivalents.
   */
  public function convertImgToPictureAddWebp($content) {

			if (false === $this->checkPreProcess())
			{
				 return $content;
			}
      if(function_exists('amp_is_request') && \amp_is_request()) {
          //for AMP pages the <picture> tag is not allowed
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
          return $content . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG is AMP -->' : '');
      }

      $content = $this->convert($content);
      return $content;
  }



  /**
   * Runs the full WebP/AVIF conversion pipeline on an HTML string.
   *
   * Skips RSS feeds and the admin context.  Calls testPictures() first to mark
   * <img> tags already inside <picture> elements with sp-no-webp.  Then uses
   * preg_replace_callback on /<img …>/ to call convertImage() for every <img>
   * tag.  Finally calls testInlineStyle() to replace inline CSS url() values
   * with WebP equivalents.
   *
   * Returns the original $content on any early bail; otherwise returns the
   * transformed content (which may equal $content if no convertible images were
   * found).
   *
   * @param string $content HTML content to transform.
   * @return string Content with <img> tags and inline CSS backgrounds converted.
   */
  protected function convert($content)
  {
      // Don't do anything with the RSS feed.
      if (is_feed() || is_admin()) {
          //Log::addInfo('SPDBG convert is_feed or is_admin');
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

      if (! class_exists('DOMDocument'))
      {
        Log::addWarn('Webp Active, but DomDocument class not found ( missing xmldom library )');
        return $content;
      }


      $pattern = '/<img[^>]*>/i';
      $content = preg_replace_callback($pattern, array($this, 'convertImage'), $content);

      // [BS] No callback because we need preg_match_all
      $content = $this->testInlineStyle($content);

      return $content;
  }


  /**
   * Protects <img> tags already inside <picture> elements from double-conversion.
   *
   * Scans $content for <picture>…<img>…</picture> blocks using a dot-all regex.
   * For each <img> found inside a <picture>, inserts class="sp-no-webp" (or
   * prepends 'sp-no-webp ' to an existing class attribute) so that the
   * preg_replace_callback in convert() skips them when convertImage() is called.
   *
   * Returns false only when preg_match_all yields a false $matches result
   * (pattern error); otherwise always returns the (possibly unmodified) content.
   *
   * @param string $content HTML content to scan.
   * @return string|false Content with sp-no-webp markers added, or false on regex failure.
   */
  private function testPictures($content)
  {
    // [BS] Escape when DOM Module not installed
    //if (! class_exists('DOMDocument'))
    //  return false;
  //$pattern =''
  //$pattern ='/(?<=(<picture>))(.*)(?=(<\/picture>))/mi';
  $pattern = '/<picture.*?>.*?(<img.*?>).*?<\/picture>/is';
  $count = preg_match_all($pattern, $content, $matches);

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

  /**
   * preg_replace_callback handler: converts a single <img> tag to a <picture> element.
   *
   * Receives the regex match array from the /<img …>/i pattern in convert().
   * Parses the tag via FrontImage to obtain src and srcset definitions, then for
   * each definition attempts to locate .webp (base-name and full-name variants)
   * and .avif counterparts on the filesystem.  Falls back through the
   * 'shortpixel/front/webp_notfound' filter for each missing file.
   *
   * If neither webpCount nor avifCount is non-zero after processing all
   * definitions, the original <img> HTML is returned unchanged.  Otherwise
   * calls FrontImage::parseReplacement() with arrays of WebP and/or AVIF
   * srcset strings to produce a <picture> element with appropriate <source>
   * elements.
   *
   * @param array $match preg_replace_callback match array; $match[0] is the full <img> tag HTML.
   * @return string <picture>…</picture> HTML when WebP/AVIF variants exist, or the original <img> tag.
   */
  protected function convertImage($match)
  {
      $fs = \wpSPIO()->filesystem();

      $raw_image = $match[0];

      // Raw Image HTML
      $image = new FrontImage($raw_image);

      if (false === $image->isParseable())
      {
         return $raw_image;
      }

      $srcsetWebP = array();
      $srcsetAvif = array();
      // Count real instances of either of them, without fillers.
      $webpCount = $avifCount = 0;

      $imagePaths = array();

      $definitions = $image->getImageData();
      $imageBase = $image->getImageBase();

      foreach ($definitions as $definition) {

              // Split the URL from the size definition ( eg 800w )
              $parts = preg_split('/\s+/', trim($definition));
              $image_url = $parts[0];

              // The space if not set is required, otherwise it will not work.
              $image_condition = isset($parts[1]) ? ' ' . $parts[1] : ' ';

              // A source that starts with data:, will not need processing.
              if (strpos($image_url, 'data:') === 0)
              {
                continue;
              }

              $fsFile = $fs->getFile($image_url);
              $extension = $fsFile->getExtension(); // trigger setFileinfo, which will resolve URL -> Path
              $mime = $fsFile->getMime();

              // Can happen when file is virtual, or other cases. Just assume this type.
              if ($mime === false)
              {
                 $mime = 'image/' .  $extension;
              }

              $fileWebp = $fs->getFile($imageBase . $fsFile->getFileBase() . '.webp');
              $fileWebpCompat = $fs->getFile($imageBase . $fsFile->getFileName() . '.webp');

              // The URL of the image without the filename
              $image_url_base = str_replace($fsFile->getFileName(), '', $image_url);

              $files = array($fileWebp, $fileWebpCompat);

              $fileAvif = $fs->getFile($imageBase . $fsFile->getFileBase() . '.avif');

              $lastwebp = false;

              foreach($files as $index => $thisfile)
              {
                if (! $thisfile->exists())
                {
                  // FILTER: boolean, object, string, filedir
								 // Return fileObj if you want to live.
                  $thisfile = $fileWebp_exists = apply_filters('shortpixel/front/webp_notfound', false, $thisfile, $image_url, $imageBase);
                }

                if ($thisfile !== false)
                {
                    // base url + found filename + optional condition ( in case of sourceset, as in 1400w or similar)
                    $webpCount++;
                     $lastwebp = $image_url_base . $thisfile->getFileName() . $image_condition;
                     $srcsetWebP[] = $lastwebp;
                     break;
                }
                elseif ($index+1 !== count($files)) // Don't write the else on the first file, because then the srcset will be written twice ( if file exists on the first fails)
                {
                  continue;
                }
                else {
                    $lastwebp = $definition;
                    $srcsetWebP[] = $lastwebp;
                }
              }

              if (false === $fileAvif->exists())
              {
                $fileAvif = apply_filters('shortpixel/front/webp_notfound', false, $fileAvif, $image_url, $imageBase);
              }

              if ($fileAvif !== false)
              {
                 $srcsetAvif[] = $image_url_base . $fileAvif->getFileName() . $image_condition;
                 $avifCount++;
              }
              else { //fallback to jpg
                if (false !== $lastwebp) // fallback to webp if there is a variant in this run. or jpg if none
                {
                   $srcsetAvif[] = $lastwebp;
                }
                else {
                  $srcsetAvif[] = $definition;
                }
              }
      }

      if ($webpCount == 0 && $avifCount == 0) {
          return $raw_image;
      }

      $args = [];

      if ($webpCount > 0)
        $args['webp'] = $srcsetWebP;

      if ($avifCount > 0)
        $args['avif']  = $srcsetAvif;

      $output = $image->parseReplacement($args);

      return $output;

  }

  /**
   * Finds all CSS url() declarations in HTML content and delegates to convertInlineStyle().
   *
   * Uses the pattern '/(url\(.*?\))(.*?)(?:;|\"|\')/' (dot-all) to match url()
   * values plus any trailing background shorthand properties up to a ; or
   * quote delimiter.  Group 1 is the full url(…) token; group 2 is any trailing
   * image-data (position, repeat, etc.).  Results are passed as a structured
   * array to convertInlineStyle() for file-system resolution and replacement.
   *
   * Returns $content unchanged when no url() matches are found.
   *
   * @param string $content HTML content to scan.
   * @return string Content with inline CSS url() backgrounds replaced by WebP equivalents.
   */
  protected function testInlineStyle($content)
  {
    //preg_match_all('/background.*[^:](url\(.*\))[;]/isU', $content, $matches);
    // Pattern : Find the URL() from CSS, save any extra background information ( position, repeat etc ) in second group and terminate at ; or " (hopefully end of line)
    // Old pattern - /url\(.*\)(.*)(?:;|\"|\')/isU
    $pattern = '/(url\(.*?\))(.*?)(?:;|\"|\')/is';
    preg_match_all($pattern, $content, $matches);

    if (count($matches) == 0 || count($matches[1]) == 0)
    {
      return $content;
    }

    $filtered = []; 
    // Create a name array to make code clearer. First match (item) is url(xx).  Imagedata is shorthand properties that might follow but before end of background declararation.
    for ($i = 0; $i < count($matches[1]); $i++)
    {
      $filtered[] = ['item' => $matches[1][$i], 'imagedata' => $matches[2][$i]];
    }

    $content = $this->convertInlineStyle($filtered, $content);
    return $content;
  }


  /**
   * Replaces inline CSS url() declarations with WebP equivalents where available.
   *
   * For each entry in $matches (with 'item' = the url(…) string and 'imagedata'
   * = optional trailing shorthand), extracts the raw URL, resolves it to a
   * filesystem path via the VFS, then looks for a .webp counterpart using both
   * the base-name and full-name strategies.  Falls back through the
   * 'shortpixel/front/webp_notfound' filter when neither file exists on disk.
   *
   * Only processes extensions in [jpg, jpeg, gif, png].  Skips external URLs
   * (where getFileDir() returns false).  Each source url() token is replaced
   * once in $content via str_replace(); the converted array guards against
   * double-replacement when the same image appears on multiple elements.
   *
   * The replacement uses the base WebP name (no trailing image-data) as the
   * inline value: url('<webp-url>').
   *
   * @param array  $matches Structured match array from testInlineStyle(); each entry has 'item' and 'imagedata'.
   * @param string $content HTML content in which replacements are made.
   * @return string Content with qualifying inline CSS backgrounds replaced.
   */
  protected function convertInlineStyle($matches, $content)
  {
    $fs = \wpSPIO()->filesystem();
    $allowed_exts = array('jpg', 'jpeg', 'gif', 'png');
    $converted = array();

    foreach($matches as $match)
    {
       $item = $match['item'];
       $image_data = ''; 
       if (isset($match['imagedata']) && strlen(trim($match['imagedata'])) > 0)
       {
         $image_data = $match['imagedata'];
       }

      //preg_match('/url\(\'(.*)\'\)/imU', $item, $url_match);
      // Fix: backgrounds might not have ' ' in URL area. 
      preg_match('/url\((.*)\)/imU', $item, $url_match);
      if (! isset($url_match[1]))
      {
        continue;
      }
      // Remove any removing " ' to get the pure URL. 
      $url = str_replace(['\'', '"'],'', $url_match[1]);
      $filename = basename($url);
      $ext = pathinfo($url, PATHINFO_EXTENSION);

      if (! in_array($ext, $allowed_exts))
        continue;

      $image_base_url = str_replace($filename, '', $url);
      $fsFile = $fs->getFile($url);
      $dir = $fsFile->getFileDir();
      $imageBase = is_object($dir) ? $dir->getPath() : false;

      if (false === $imageBase) // returns false if URL is external, do nothing with that.
        continue;

      $checkedFile = false;
      $fileWebp = $fs->getFile($imageBase . $fsFile->getFileBase() . '.webp');
      $fileWebpCompat = $fs->getFile($imageBase . $fsFile->getFileName() . '.webp');

      if (true === $fileWebp->exists())
      {
        $checkedFile = $image_base_url . $fsFile->getFileBase()  . '.webp';
      }
      elseif (true === $fileWebpCompat->exists())
      {
        $checkedFile = $image_base_url . $fsFile->getFileName() . '.webp';
      }
      else
      {
        $fileWebp_exists = apply_filters('shortpixel/front/webp_notfound', false, $fileWebp, $url, $imageBase);
        if (false !== $fileWebp_exists)
        {
           $checkedFile = $image_base_url . $fsFile->getFileBase()  . '.webp';
        }
        else {
          $fileWebp_exists = apply_filters('shortpixel/front/webp_notfound', false, $fileWebpCompat, $url, $imageBase);
          if (false !== $fileWebp_exists)
          {
             $checkedFile = $image_base_url . $fsFile->getFileName()  . '.webp';
          }
        }
      }

      if ($checkedFile)
      {
          // if webp, then add another URL() def after the targeted one.  (str_replace old full URL def, with new one on main match?
          $target_urldef = $item; //$match[0][$i];
          
          // The target_urldef should remain original to be picked up by str_replace, but the original_definitions are what goes back of the original stuff and should be filtered. 
         // $original_definitions = $this->filterForbiddenInline($target_urldef);
          
          if (! isset($converted[$target_urldef])) // if the same image is on multiple elements, this replace might go double. prevent.
          {
            $converted[] = $target_urldef;
            // Fix: The originals are not being put anymore because this would lead to double images and that's not a good thing.
            //            $new_urldef = "url('" . $checkedFile . "') $image_data ;";
            // Fix2 :: The image_data should not be matched anymore via main match, perhaps it works in all cases to leave it alone? 
            // Fix3 :: Removed the ' ' around the newdef image since this can cause issues if the main image is wrapped in the same. 
            $new_urldef = "url(" . $checkedFile . ") ";
            $content = str_replace($target_urldef, $new_urldef, $content);
          }
      }

    } // FOREACH
 
    return $content;
  }


  /**
   * FilterForbiddenInline
   * 
   * Filter tags like !important for the targetstring that should not be duplicated or causes issues otherwise. 
   *
   * @param [String] $targetString
   * @return String
   */
  protected function filterForbiddenInline($targetString)
  {
        $search = ['!important'];
        $targetString = str_replace($search, '', $targetString); 
        
        return $targetString;
  }

} // class
