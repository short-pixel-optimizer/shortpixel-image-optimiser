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
				$this->setDefaultCDNArgs();
				$this->loadCDNDomain();


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
        $args[] = $compressionArg;

				$use_webp = $settings->createWebp;
				$use_avif =  $settings->createAvif;

				$webp_double = $env->useDoubleWebpExtension();
				$avif_double = $env->useDoubleAvifExtension();

				if ($use_webp && $use_avif)
				{
					 $args[] = 'to_auto';
				}
				elseif ($use_webp && ! $use_avif)
				{
					 $args[] = 'to_webp';
				}
				elseif ($use_avif && ! $use_webp) {
					 $args[] = 'to_avif';
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
           $args[] = $webpArg;
        }


				$this->cdn_arguments = $args;

        $this->regex_exclusions = apply_filters('shortpixel/front/cdn/regex_exclude',[
            '*gravatar.com*',
            '/data:image\/.*/',
        ]);


        // string || preg
        $this->replace_method = apply_filters('shortpixel/front/cdn/replace_method', 'preg');


		}

		protected function processFront($content)
		{
				if (false === $this->checkPreProcess())
				{
					 return $content;
				}

				$args = [];
				$matches = $this->fetchMatches($content, $args);

				$urls = $this->extractMatches($matches);
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

		protected function fetchMatches($content, $args = [])
		{
			$number = preg_match_all('/<img[^>]*>/i', $content, $matches);
			$matches = $matches[0];
			return $matches;
		}

    /** Extract matches from the document.  This are the source images and should not be altered, since the string replace would fail doing that */
		protected function extractMatches($matches)
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
            $site_url  = get_site_url();
            if (substr($parsedUrl['path'], 0, 1) !== '/')
            {
                $site_url .= '/';
            }

            $url = $site_url . $url;
            Log::addTemp("URL from $original_url changed to $url");
        }

        // @todo This CDN stuff should probably be wrapped in set / unset CDN Arguments.  Once for the whole batch, one per url ?
        $ph_key = array_search('p_h', $this->cdn_arguments);
        if (isset($parsedUrl['scheme']) && 'http' == $parsedUrl['scheme'])
        {
          if ($ph_key === false)
            $this->cdn_arguments[] = 'p_h';
        }
        elseif ($ph_key !== false) {
            unset($this->cdn_arguments[$ph_key]);
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

				$cdn_prefix = trailingslashit($domain) . trailingslashit($this->findCDNArguments($src));
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
		protected function findCDNArguments($src)
		{
				$arguments = $this->cdn_arguments;

				$string = implode(',', $arguments);

				return  $string;
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
