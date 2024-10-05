<?php
namespace ShortPixel\Controller\Front;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\FrontImage as FrontImage;


class CDNController extends \ShortPixel\Controller\Front\PageConverter
{

		protected $cdn_domain;

		public function __construct()
		{
				parent::__construct();

				if (false === $this->shouldConvert())
				{
					 return false;
				}

				$settings = wpSPIO()->settings();
				$this->loadCDNDomain();

				$this->startOutputBuffer('processFront');

		}

		protected function processFront($content)
		{
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
			//$cdn_domain = $settings->CDNDomain;
			$cdn_domain = 'https://cdn.shortpixel.ai/spio';

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

				 $imageData[] = $src;
				 // Additional sources.
				 $imageData = array_merge($imageData, $imageObj->getImageData());


			}

			return $imageData;
		}

		protected function getUpdatedUrls($urls)
		{
			for ($i = 0; $i < count($urls); $i++)
			{
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

				$cdn_prefix = trailingslashit($domain) . trailingslashit($this->findDomainArguments($src));

				$new_src = $cdn_prefix . $src;

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
			Log::addTemp('Urls' . count($urls), $urls);
			Log::addTEmp('new urls' . count($new_urls), $new_urls);


				$content = str_replace($urls, $new_urls, $content);

				return $content;
		}

		//maybe Shortpixel CDn specific?
		// @param src string for future (?)
		// @return Space separated list of settings for SPIO CDN.
		protected function findDomainArguments($src)
		{
				return 'to_auto,q_orig,ret_img';
		}




} // class
