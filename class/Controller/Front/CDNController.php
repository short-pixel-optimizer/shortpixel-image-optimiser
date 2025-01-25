<?php
namespace ShortPixel\Controller\Front;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\FrontImage as FrontImage;
use ShortPixel\Model\Image\ImageModel as ImageModel;


class CDNController extends \ShortPixel\Controller\Front\PageConverter
{

		protected $cdn_domain;
		protected $cdn_arguments = [];

    protected $skip_rules = [];
    protected $replace_method = 'preg';


		public function __construct()
		{
				parent::__construct();

				if (false === $this->shouldConvert())
				{
					 return false;
				}

				$settings = wpSPIO()->settings();
		//		$this->setDefaultCDNArgs();
				$this->loadCDNDomain();

				// Add hooks for easier conversion / checking
				$this->addWPHooks();

				// Starts buffer of whole page, with callback .
				$this->startOutputBuffer('processFront');
		}

		protected function setDefaultCDNArgs()
		{
				$settings = \wpSPIO()->settings();
				$env = \wpSPIO()->env();


        $compressionType = $settings->compressionType;
        // Depend this on the SPIO setting
				$args = ['ret_img'];
				$compressionArg = 'q_orig';

        // Perhaps later if need to override in webp/avif check
        $args['compression'] = $compressionArg;

				$use_webp = $settings->createWebp;
				$use_avif =  $settings->createAvif;

				$webp_double = $env->useDoubleWebpExtension();
				$avif_double = $env->useDoubleAvifExtension();

				if ($use_webp && $use_avif)
				{
           $args['webp'] = 'to_auto';
				}
				elseif ($use_webp && ! $use_avif)
				{
           $args['webp'] = 'to_webp';
				}
				elseif ($use_avif && ! $use_webp) {
           $args['avif'] = 'to_avif';
				}

        $webpArg = '';

				if ($use_webp)
				{
					$webpArg = ($webp_double) ? 's_dwebp' : 's_webp';
          if ($use_avif)
          {
             $webpArg .= ($avif_double) ? ':davif' : ':avif';
          }
				}
				elseif (! $use_webp && $use_avif)
				{
					 $webpArg = ($avif_double) ? 's_davif' : 's_avif';
				}

        if (strlen($webpArg) > 0)
        {
           $args['webarg'] = $webpArg;
        }

				$this->cdn_arguments = $args;

        $this->regex_exclusions = apply_filters('shortpixel/front/cdn/regex_exclude',[
            '*gravatar.com*',
            '/data:image\/.*/',
        ]);


        // string || preg
        $this->replace_method = apply_filters('shortpixel/front/cdn/replace_method', 'preg');
		}

		protected function addWPHooks()
		{
				$settings = \wpSPIO()->settings;

				if (true === $settings->cdn_js)
				{
					add_filter('script_loader_src', [$this, 'processScripts'], 10, 2);
				}
				if (true === $settings->cdn_css)
				{
					add_filter('style_loader_src', [$this, 'processScripts'] , 10, 2);
				}
		}

		public function processScripts($src, $handle)
		{
			//	Log::addTemp('Script: ' . $src);

				//Prefix the SRC with the API Loader info .
					// 1. Check if scheme is http and add
					// 2. Check if there domain and if not, prepend.
					// 3 Probably check if Src is from local domain, otherwise not replace (?)
					$this->setCDNArgument('retauto', 'ret_auto'); // for each of this type.

					$src = $this->processUrl($src);
					$src = $this->replaceImage($src); // @todo function must be renamed if this works

					$this->setCDNArgument('retauto', null);
				return $src;
		}

		protected function processFront($content)
		{
				if (false === $this->checkPreProcess())
				{
					 return $content;
				}

				$args = [];
				$image_matches = $this->fetchImageMatches($content, $args);
				$urls = $this->extractImageMatches($image_matches);

			//	$document_matches = $this->fetchDocumentMatches($content, $args);
			//	$urls = array_merge($url, $this->extraDocumentMatches($document_matches));

				$new_urls = $this->getUpdatedUrls($urls);

        Log::addTemp('CDN Result ', [$urls, $new_urls]);
      //  $replace_function = ($this->replace_method == 'preg') ? 'pregReplaceContent' : 'stringReplaceContent';
        $replace_function = 'stringReplaceContent'; // undercooked, will defer to next version

        $content = $this->$replace_function($content, $urls, $new_urls);
        return $content;
		}

		protected function loadCDNDomain()
		{
			$settings = \wpSPIO()->settings();
			$cdn_domain = $settings->CDNDomain;

      $this->cdn_domain = trailingslashit($cdn_domain);
		}

		protected function fetchImageMatches($content, $args = [])
		{
			$number = preg_match_all('/<img[^>]*>/i', $content, $matches);
			$matches = $matches[0];
			return $matches;
		}

		protected function fetchDocumentMatches($content, $args = [])
		{
		//		$number = preg_match_all('')
		}

    /** Extract matches from the document.  This are the source images and should not be altered, since the string replace would fail doing that */
		protected function extractImageMatches($matches)
		{

			$imageData= [];
			foreach($matches as $match)
			{
				 $imageObj = new FrontImage($match);
				 $src = $imageObj->src;

				 if (! is_null($src))
				 {
           $imageData[] = $this->trimURL($src);
				 }
				 // Additional sources.
         $moreData = array_map([$this, 'trimURL'],$imageObj->getImageData());
				 // Merge and remove doubles.

         $imageData = array_unique(array_merge($imageData, $moreData)); // pick out uniques.

         $imageData = array_filter(array_values($this->addEscapedUrls($imageData))); // reset indexes

			}

      // Apply exlusions on URL's here.
      $imageData = $this->applyRegexExclusions($imageData);

			return $imageData;
		}


    /** @param $urls Array Source URLS
    * @return Updated URLs - The string that the original values should be replaced with
    */
		protected function getUpdatedUrls($urls)
		{
			for ($i = 0; $i < count($urls); $i++)
			{
				 $src = $urls[$i];
         $src = $this->processUrl($src);
         $urls[$i] = $this->replaceImage($src);

			}

			return $urls;
		}


    // Special checks / operations because the URL is replaced. Data check.
    protected function processUrl($url)
    {
         $original_url = $url; // debug poruposes.
        $parsedUrl = parse_url($url);
        if (! isset($parsedUrl['host']))
        {
						$site_url  = $this->site_url;
            if (substr($parsedUrl['path'], 0, 1) !== '/')
            {
                $site_url .= '/';
            }

            $url = $site_url . $url;
            Log::addTemp("URL from $original_url changed to $url");
        }

				$this->setCDNArgument('scheme', null);
        if (isset($parsedUrl['scheme']) && 'http' == $parsedUrl['scheme'])
        {
						$this->setCDNArgument('scheme', 'p_h');
        }

        return $url;

    }

		protected function replaceImage($src)
		{
				$domain = $this->cdn_domain;

       // Check for slashes ( stored via js etc escaped slashed)
				if (strpos($src, '\/') !== false)
				{
					 $src = stripslashes($src);
				}

        // Remove " . Some themes put this for some reason.
				$remove = ['"'];
				$src = str_replace($remove, [], $src);

        // If there is a trailing-slash, remove it.
        $src = rtrim($src, '/');

        // Remove the protocol
      //  $src = str_replace(['http://', 'https://'], '', $src);

        $src = apply_filters('shortpixel/front/cdn/url', $src);

				$cdn_prefix = trailingslashit($domain) . trailingslashit($this->getCDNArguments($src));
				$new_src = $cdn_prefix . trim($src);

				return $new_src;
		}

    protected function stringReplaceContent($content, $urls, $new_urls)
		{

			$count = 0;
			$content = str_replace($urls, $new_urls, $content, $count);

			return $content;
		}

    protected function pregReplaceContent($content, $urls, $new_urls)
    {
       $count = 0;
       $patterns = [];

       // Create pattern for each URL to search.
       foreach($urls as $index => $url)
       {
          //$replacement = $new_urls[$index];
          $patterns[] = '/("|\'| )(' . preg_quote($url, '/') . ')("|\'| )/mi';

       }

       foreach($new_urls as $index => $url)
       {
          $new_urls[$index] = '$1' . $url . '$1';
       }

        $content = preg_replace($patterns, $new_urls, $content, -1, $count );
        return $content;

    }

		//maybe Shortpixel CDn specific?
		// @param src string for future (?)
		// @return Space separated list of settings for SPIO CDN.
		protected function getCDNArguments($src)
		{
				$arguments = $this->cdn_arguments;

				$string = implode(',', $arguments);

				return  $string;
		}

		/* Sets an CDN Argument in a controlled way.  Pass null as value to unset it
		*
		*	 @param $name Name of the argument (internal)
		*	 @param $value Value of the argument to be passed to API , null is unset.
		*/
		protected function setCDNArgument($name, $value)
		{
				if (is_null($value))
				{
						if (isset($this->cdn_arguments[$name]))
						{
							unset($this->cdn_arguments[$name]);
						}
				}
				else {
						$this->cdn_arguments[$name] = $value;
				}
		}

    // Function not only to trim, but also remove extra stuff like '200w' declarations in srcset.
    // This should not alter URL, because it's used as the search in search / replace, so should point to full original URL
    private function trimURL($url)
		{
				$url = trim(strtok($url, ' '));

				return $url;
		}

		// Something in source the Url's can escaped which is undone by domDocument. Still add them to the replacement array, otherwise they won't be replaced properly.
		private function addEscapedUrls($urls)
		{
				$new_urls = $urls;
				foreach($urls as $url)
				{
						$escaped = esc_url($url);
						if ($escaped !== $url)
						{
							 $new_urls[] = esc_url($url);
						}
				}
				return $new_urls;
		}




} // class
