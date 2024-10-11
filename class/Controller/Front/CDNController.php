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


				/* Not used for now.
				switch($compressionType)
        {
           case ImageModel::COMPRESSION_LOSSY:
              $compressionArg = 'q_lossy';
           break;
           case ImageModel::COMPRESSION_GLOSSY:
              $compressionArg = 'q_glossy';
           break;
           case ImageModel::COMPRESSION_LOSSLESS:
           default:
              $compressionArg = 'q_lossless';
           break;
				} */
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


			/*	if (true === $settings->deliverWebp)
				{
						$webp  = ($env->useDoubleWebpExtension()) ? 's_webp' : 'd_webp';
						$args[] = $webp;
				}
				if (true === $settings->deliverAvif)
				{
						$avif  = ($env->useDoubleAvifExtension()) ? 's_avif' : 'd_avif';
						$args[] = $avif;
				} */

				$this->cdn_arguments = $args;

		}

		protected function processFront($content)
		{
				if (false === $this->checkPreProcess())
				{
					 return $content;
				}
				Log::addTemp('Processing Front', $_SERVER['REQUEST_URI']);

			//	Log::addTemp('Server URL', get_site_url());
				$args = [];
				$matches = $this->fetchMatches($content, $args);

				$urls = $this->extractMatches($matches);
				$new_urls = $this->getUpdatedUrls($urls);

				$content = $this->replaceContent($content, $urls, $new_urls);
				return $content;
		}

		protected function loadCDNDomain()
		{
			$settings = \wpSPIO()->settings();
			$cdn_domain = $settings->CDNDomain;

			$this->cdn_domain = $cdn_domain;
		}

		protected function fetchMatches($content, $args = [])
		{

			$number = preg_match_all('/<img[^>]*>/i', $content, $matches);
				Log::addTEmp('matches', $matches);

			$matches = $matches[0];

			return $matches;
		}

		protected function extractMatches($matches)
		{

			$imageData= [];
			foreach($matches as $match)
			{
				 $imageObj = new FrontImage($match);
				 $src = $imageObj->src;

				 if (! is_null($src))
				 {
					 $imageData[] = strtok($src, ' ');
				 }
				 // Additional sources.
				 $moreData = array_map([$this, 'trimURL'],$imageObj->getImageData());
				 // Merge and remove doubles.
				 $imageData = array_unique(array_merge($imageData, $moreData)); // pick out uniques.

				 $imageData = array_values($this->addEscapedUrls($imageData)); // reset indexes

			}
			return $imageData;
		}

		protected function getUpdatedUrls($urls)
		{
		//	Log::addTemp('URLS', $urls);
			for ($i = 0; $i < count($urls); $i++)
			{
				 $src = $urls[$i];
				 $urls[$i] = $this->replaceImage($urls[$i]);
			}

			return $urls;
		}

		protected function replaceImage($src)
		{
				$site_url = $this->site_url;
				$domain = $this->cdn_domain;
				$new_src = $src;

				$parsedUrl = parse_url($src);

				/*if (isset($parsedUrl['scheme']))
				{
					 $src = str_replace($parsedUrl['scheme'] . "://", '', $src);
				} */

				$cdn_prefix = trailingslashit($domain) . trailingslashit($this->findCDNArguments($src));

				$new_src = $cdn_prefix . trim($src);

				/* If need to replace.
				if (strpos($src, $site_url) !== false)
				{
					 Log::addTEmp('Replacing');
						$new_src = str_replace($site_url, $domain, $src);
				}
				*/

				return $new_src;
		}

		protected function replaceContent($content, $urls, $new_urls)
		{

	//		$urls = array_merge($urls, array_unique(array_map('esc_url', $urls)));
	//		$new_urls = array_merge($new_urls, array_unique(array_map('esc_url', $new_urls)));

			Log::addTemp('Urls' . count($urls), $urls);
			Log::addTEmp('new urls' . count($new_urls), $new_urls);

// @todo Seems URLS with url-encoding is not replaced properly, ie.
//  https://secure.gravatar.com/avatar/a29fae50419a25fb110f77af6487019b?s=26&#038;d=mm&#038;r=g
//  https://secure.gravatar.com/avatar/a29fae50419a25fb110f77af6487019b?s=26&d=mm&r=g ( source URL )

			$count = 0;
			$content = str_replace($urls, $new_urls, $content, $count);

Log::addTemp("Content replaced $count instances");
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