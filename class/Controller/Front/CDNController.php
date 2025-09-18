<?php

namespace ShortPixel\Controller\Front;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ApiKeyController;
use ShortPixel\Helper\UtilHelper;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\FrontImage as FrontImage;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Replacer\Replacer as Replacer;


class CDNController extends \ShortPixel\Controller\Front\PageConverter
{

	protected $cdn_domain;
	protected $cdn_arguments = [];

	protected $skip_rules = [];
	protected $replace_method = 'preg';

	private $content_is_json = false;


	public function __construct()
	{
		parent::__construct();

		$this->listenFlush();
		$this->loadCDNDomain();

		if (false === $this->shouldConvert()) {
			return false;
		}

		$this->init();

	}

	protected function init()
	{

		// Add hooks for easier conversion / checking
		
		$this->addWPHooks();

		// Starts buffer of whole page, with callback .
		$this->startOutputBuffer('processFront');


		$this->regex_exclusions = apply_filters('shortpixel/front/cdn/regex_exclude', [
			'*gravatar.com*',
			'/data:image\/.*/',
			'*' . $this->cdn_domain . '*', 
			'*/wp-admin/js*',
			'*/wp-admin/css*', 
			'*/wp-includes/js*', 
			'*/wp-includes/css*', 
			'*admin-ajax.php*',
			


		]);

		// string || preg
		$this->replace_method = apply_filters('shortpixel/front/cdn/replace_method', 'preg'); 
	}


	public function registerDomain($args = [])
	{
		$defaults = [
			'action' => 'register', // or deregister 
			
		]; 
		
		$args = wp_parse_args($args, $defaults);

		$register_domain = 'https://no-cdn.shortpixel.ai/'; 

		if ('register' === $args['action'])
		{
			 $register_domain .= 'add-domain/'; 
		}
		else
		{
			 $register_domain .= 'revoke-domain/'; 
		}

		$parsed_url = parse_url(get_site_url());

		if (isset($parsed_url['host']))
		{
			 $register_domain .= trim($parsed_url['host']) . '/';
		}
		else
		{
			 return false; 
			 // @todo Concur here some error message 
		}

		$keyControl = ApiKeyController::getInstance();
		$apiKey = $keyControl->forceGetApiKey();

		$register_domain .= $apiKey;
		$res = wp_remote_post($register_domain);	
		
		
	}

	public function purgeCDN($args = [])
	{
		$purge = $args['purge']; 
		$settings = \wpSPIO()->settings();
	//	$purge_domain = 'https://no-cdn.shortpixel.ai/purge-cdn-cache-bulk'; 

		$result = [
			'is_error' => false, 
			'message' => '', 
		]; 


		if ('cssjs' == $purge)
		{
			$settings->cdn_purge_version = substr(time(), -4, 4); 

			$result['message'] = __('CDN and JS cache purged', 'shortpixel-image-optimiser');
		}

		if ('all' == $purge)
		{
			$domain = $this->getPurgeURL(['action' => 'purge-cdn-cache-bulk']);

			$remote_post = wp_remote_post($domain);

			if (is_wp_error($remote_post))
			{
				$result['message'] = $remote_post->errors['http_request_failed'][0];
				$result['is_error'] = true;
			}
			else
			{
				$response = isset($remote_post['body']) ? json_decode($remote_post['body']) : []; 
				if (property_exists($response, 'Status') && $response->Status == 2 )
				{
					 $result['message'] = __('Cache purged', 'shortpixel-image-optimiser');
				}
	
			}
		}

		return $result;
		 
	}

	private function getPurgeURL($args = [])
	{
		$action = isset($args['action']) ? $args['action'] : ''; 
		$purge_domain = 'https://no-cdn.shortpixel.ai'; 

		$settings = \wpSPIO()->settings();
		$apiKeyController = ApiKeyController::getInstance();

		$site_domain = parse_url(get_site_url());
		$cdnDomain = parse_url($settings->CDNDomain); 
		$key = $apiKeyController->forceGetApiKey();
		$cdnHost = (isset($cdnDomain['host'])) ? $cdnDomain['host'] : 'spcdn.shortpixel.ai';

		if ('purge-cdn-cache' == $action)
		{
			//http://no-cdn.shortpixel.ai/purge-cdn-cache/API_KEY_HERE/FULL_CDN_DOMAIN/costomer-domain.com/wp-content/uploads/2024/12/file-name-without-extension*
			$domain = $purge_domain . '/' . $action . '/' . $key  . '/';
		}
		else
		{
			$domain = $purge_domain . '/' . $action . '/' . $key  . '/' . trim($site_domain['host']) . '/' . trim($cdnHost);
		}
		

		return $domain; 

	}

	protected function createArguments($args = [])
	{
		$settings = \wpSPIO()->settings();
		$env = \wpSPIO()->env();


		$compressionType = $settings->compressionType;
		// Depend this on the SPIO setting
		if (! isset($args['return']))
		{
			$args['return'] = 'ret_img';
		}

		$compressionArg = 'q_cdnize';

		// Perhaps later if need to override in webp/avif check
		$args['compression'] = $compressionArg;

		$use_webp = $settings->createWebp;
		$use_avif =  $settings->createAvif;

		$webp_double = $env->useDoubleWebpExtension();
		$avif_double = $env->useDoubleAvifExtension();

		if ($use_webp && $use_avif) {
			$args['webp'] = 'to_auto';
		} elseif ($use_webp && ! $use_avif) {
			$args['webp'] = 'to_webp';
		} elseif ($use_avif && ! $use_webp) {
			$args['avif'] = 'to_avif';
		}

		$webpArg = '';

		if ($use_webp) {
			$webpArg = ($webp_double) ? 's_dwebp' : 's_webp';
			if ($use_avif) {
				$webpArg .= ($avif_double) ? ':davif' : ':avif';
			}
		} elseif (! $use_webp && $use_avif) {
			$webpArg = ($avif_double) ? 's_davif' : 's_avif';
		}

		if (strlen($webpArg) > 0) {
			$args['webarg'] = $webpArg;
		}

		return $args;

	}

	protected function addWPHooks()
	{
		$settings = \wpSPIO()->settings();

		if (true === $settings->cdn_js) {

			add_filter('script_loader_src', [$this, 'processScript'], 10, 2);
		}

		if (true === $settings->cdn_css) {
			add_filter('style_loader_src', [$this, 'processScript'], 10, 2);
		}

	}

	public function processScript($src, $handle)
	{
		// @todo check here if file is JS / CSS at all. 
		

		if (false === $this->checkPreProcess()) {
			return $src;
		}


		if (! is_string($src) || strlen($src) == 0) {
			return $src;
		}

		//Prefix the SRC with the API Loader info .
		// 1. Check if scheme is http and add
		// 2. Check if there domain and if not, prepend.
		// 3 Probably check if Src is from local domain, otherwise not replace (?)
		//$this->setCDNArgument('retauto', 'ret_auto'); // for each of this type.

		$version = \wpSPIO()->settings()->cdn_purge_version;

		$replaceBlocks = [];
		$block =  $this->getReplaceBlock($src);
		$block->args = $this->createArguments(['return' => 'ret_auto', 'version' => 'v_' . $version]);

		$replaceBlocks[] = $block;

		$replaceBlocks = $this->filterRegexExclusions($replaceBlocks);

		// When filtered out.
		if (count($replaceBlocks) == 0) {
			return $src;
		}

		//$this->setCDNArgument('version', 'v_' . $version);

		$replaceBlocks = $this->filterOtherDomains($replaceBlocks);

		if (count($replaceBlocks) == 0) {
			return $src;
		}

		$settings = \wpSPIO()->settings();
		$checkExtensions = []; 
		$fonts = ['.ttf', '.woff', '.woff2', '.otf']; 

		if (true === $settings->cdn_js) {
			$checkExtensions[] = '.js'; 
			
		}
		if (true === $settings->cdn_css)
		{	
			$checkExtensions[] = '.css'; 
			$checkExtensions = array_merge($checkExtensions, $fonts);
		}

		$checkExt = false; 
		foreach($checkExtensions as $extcheck)
		{
			 if (strpos($src, $extcheck) !== false)
			 {	
				$checkExt = true; 
				break; 
			 }
		}

		if (false === $checkExt)
		{
			 return $src;
		}

		$this->createReplacements($replaceBlocks);

		if (count($replaceBlocks) > 0) {
			$src = $replaceBlocks[0]->replace_url;
		}

	//	$this->setCDNArgument('retauto', null);
		return $src;
	}

	protected function processFront($content)
	{
		if (false === $this->checkPreProcess()) {
			return $content;
		}

		$original_content = $content;
		$content = $this->checkContent($content);

		$background_inline_found = false; 
		
		$args = [];

		// *** DO INLINE BACKGROUND FIRST *** 
		$replaceBlocks = $this->fetchInlineBackground($content, $args);

		$replaceBlocks = $this->filterEmptyURLS($replaceBlocks);
		$replaceBlocks = $this->filterRegexExclusions($replaceBlocks);
		$replaceBlocks = $this->filterOtherDomains($replaceBlocks);
		$replaceBlocks = $this->filterFonts($replaceBlocks);

		if (count($replaceBlocks) > 0) {
			$replaceBlocks = $this->createReplacements($replaceBlocks);
			$replaceBlocks = $this->filterDoubles($replaceBlocks);
			$content = $this->pregReplaceContent($content, $replaceBlocks);
			$background_inline_found = true; 
		}
	
		// ** DO IMAGE MATCHES **/
		$image_matches = $this->fetchImageMatches($content, $args);
		$replaceBlocks = $this->extractImageMatches($image_matches);

		$replaceBlocks = $this->filterEmptyURLS($replaceBlocks);
		$replaceBlocks = $this->filterRegexExclusions($replaceBlocks);
		$replaceBlocks = $this->filterOtherDomains($replaceBlocks);



		// If the items didn't survive the filters.
		if (count($replaceBlocks) == 0) {
			if (true === $background_inline_found)
			{
				 return $content; 
			}
			else
			{
				return $original_content;
			}
			
		}

		$replaceBlocks = $this->createReplacements($replaceBlocks);

		// FilterDoubles should prob. be off if we are doing a own htmlReplace only. 
	//	$replaceBlocks = $this->filterDoubles($replaceBlocks);

		//  $replace_function = ($this->replace_method == 'preg') ? 'pregReplaceContent' : 'stringReplaceContent';

		$replace_function = 'pregReplaceByString'; // undercooked, will defer to next version
	//	$replace_function = 'stringReplaceContent';
		$imageIndexes = array_column($replaceBlocks, 'imageId');

		array_multisort($imageIndexes, SORT_ASC, $replaceBlocks); 

		$sortedBlocks = []; 
		foreach($replaceBlocks as $replaceBlock)
		{
			 $sortedBlocks[$replaceBlock->imageId][] = $replaceBlock; 
		}

		foreach($sortedBlocks as $sortedBlock)
		{
			$urls = array_column($sortedBlock, 'raw_url');
			$replace_urls = array_column($sortedBlock, 'replace_url'); 
			$original_block_content = $sortedBlock[0]->htmlMatch;

			if ($this->content_is_json) // add slashes here to the replace URLS
			{
				 $urls = array_merge($urls, array_map([$this, 'encodeForJson'], $urls));
				 $replace_urls = array_merge($replace_urls, array_map([$this, 'encodeForJson'], $replace_urls));
			}			

			$replaced_block_content = $this->$replace_function($original_block_content, $urls, $replace_urls);
			
			$content = str_replace($original_block_content, $replaced_block_content, $content, $count); 
		}
		

		return $content;
	}

	private function encodeForJSON($url)
	{
		 $url = json_encode($url);
		 $url = str_replace('"', '', $url); 
		 return $url;
	}


	protected function loadCDNDomain($CDNDomain = false)
	{
		if ($CDNDomain === false)
		{
			$settings = \wpSPIO()->settings();
			$cdn_domain = $settings->CDNDomain;
		}
		else
		{
			$cdn_domain = $CDNDomain;
		}

		$parsed_domain = parse_url($cdn_domain);
		if (false === isset($parsed_domain['path']) || 
			strlen($parsed_domain['path']) === 0 ||
			'/' === $parsed_domain['path']
			 )
		{
			 $cdn_domain = trailingslashit($cdn_domain) . 'spio/'; 
		}
	/*	elseif ($parsed_domain['path'] !== '/spio')
		{
			 $cdn_domain = $parsed_domain['scheme'] . '://' . $parsed_domain['host'] . '/spio'; 
		} */

		if (false === $CDNDomain)
		{
			$this->cdn_domain = trailingslashit($cdn_domain);
		}
		else
		{
			return  $cdn_domain;
		}


	}

	/** The image check on inline CSS might also catch inline fonts.  Check against settings if they should be processed or not. 
	 * 
	 * @param mixed $replaceBlocks 
	 * @return mixed 
	 */
	protected function filterFonts($replaceBlocks)
	{
		$settings = \wpSPIO()->settings();

		if (true === $settings->cdn_css)
		{
			return $replaceBlocks; 
		}

		$replaceBlocks = array_filter($replaceBlocks, function ($replaceBlock)
		{
			 $fonts = ['.ttf', '.woff', '.woff2', '.otf']; 
			 foreach($fonts as $extcheck)
			 {
				  if (strpos($replaceBlock->url, $extcheck) !== false)
				  {	
						return false; 
				  }
			 }
			 return true; 

		});
   
		return $replaceBlocks;

	}

	public function validateCDNDomain($CDNDomain)
	{
		
		$resultDomain = $this->loadCDNDomain($CDNDomain);

		if ($resultDomain === $CDNDomain)
		{
			 return true; 
		}
		else
		{
			return $resultDomain;
		}

	}

	protected function fetchImageMatches($content, $args = [])
	{
		$number = preg_match_all('/<img[^>]*>|<source srcset="[^>]*">/i', $content, $matches);
		$matches = $matches[0];
		return $matches;
	}

	protected function fetchInlineBackground($content, $args = [])
	{
		$number = preg_match_all('/url(\(((?:[^()]+|(?1))+)\))/m', $content, $matches); 
		$matches = $matches[2]; 
		
		//$matches = str_replace('\'', '', $matches);
	//	Log::addTemp('Inline Matches', $matches);

		$replaceBlocks = []; 
		foreach($matches as $url)
		{
			$block = $this->getReplaceBlock($url);
			$block->args = $this->createArguments();
			$replaceBlocks[] = $block; 
		}

		return $replaceBlocks;
	}

	protected function fetchDocumentMatches($content, $args = [])
	{
		//		$number = preg_match_all('')
	}

	/** Extract matches from the document.  This are the source images and should not be altered, since the string replace would fail doing that */
	protected function extractImageMatches($matches)
	{

		$imageData = $blockData = [];
		
		foreach ($matches as $index => $match) {

			$raw_match = $match; 
			if ($this->content_is_json)
			{
				$match = stripslashes($match);
			}
			$imageObj = new FrontImage($match);
			$src = $imageObj->src;
			
			if (! is_null($src)) {
				$imageBlock = $this->getReplaceBlock($src);
				$imageBlock->htmlMatch = $raw_match; 
				$imageBlock->imageId = 'image' . $index; 
				$imageBlock->args = $this->createArguments();
				$blockData[] = $imageBlock;
				$imageData[] = $imageBlock->url;
			}

			// Additional sources.
			$images = $imageObj->getImageData();

			foreach ($images as $image) {
				$imageBlock = $this->getReplaceBlock($image);
				$imageBlock->htmlMatch = $match;
				$imageBlock->imageId = 'image' . $index; 
				$imageBlock->args = $this->createArguments();
				if ($src !== $imageBlock->url) {
					$blockData[] = $imageBlock;
					$imageData[] = $imageBlock->url;
				}
			}
		}

		return $blockData;
	}


	/** @param $urls Array Source URLS
	 * @return Array URLs - The string that the original values should be replaced with
	 */
	protected function createReplacements($replaceBlocks)
	{
		$cdn_domain = $this->cdn_domain;
		$moveItems = [];

		foreach ($replaceBlocks as $index => $replaceBlock) {
			$bool = $this->checkDomain($replaceBlock);
			if (true === $bool) {
				$moveItems[] = $index;
			}
			$this->checkScheme($replaceBlock);

			// Take Parsed URL and add CDN info to add
			$url = $replaceBlock->url;
			$url = str_replace(['http://', 'https://'], '', $url); // always remove scheme
			$url = apply_filters('shortpixel/front/cdn/url', $url);

			$cdnArgs = implode(',', $replaceBlock->args);

			$cdn_prefix = trailingslashit($cdn_domain) . trailingslashit($cdnArgs);
			$replaceBlock->replace_url = $cdn_prefix . trim($url);
		}

		for ($i = 0; $i < count($moveItems); $i++) {
			$moveIndex = $moveItems[$i];
			$block = $replaceBlocks[$moveIndex];
			unset($replaceBlocks[$moveIndex]);
			array_push($replaceBlocks, $block);
		}

		return $replaceBlocks;
	}


	// Special checks / operations because the URL is replaced. Data check.

	// @todo Transform these functions to 1 check each, so each combination can use it's own mix/match of checks / transforms ( image, css, javascript  ) . Possibly with URL as argument and parsed_url as non-optional second param.
	// @return True of URL was changed, false if not.
	protected function checkDomain($replaceBlock)
	{
		if (! isset($replaceBlock->parsed['host'])) {
			$original_url = $replaceBlock->url;
			$site_url  = $this->site_url;
			// This can happen when srcset or so is relative starting with // 

			if (substr($replaceBlock->parsed['path'], 0, 1) !== '/') {
				$site_url .= '/';
			}

			$url = $site_url . $original_url;
			$replaceBlock->parsed = parse_url($url); // parse the new URL
			$replaceBlock->url = $url;

			return true;
		}
		return false;
	}

	private function checkScheme($replaceBlock)
	{
		if (isset($replaceBlock->parsed['scheme']) && 'http' == $replaceBlock->parsed['scheme']) {
			$replaceBlock->args['scheme'] = 'p_h'; 
		}

		if (substr($replaceBlock->url, 0, 2) === '//')
		{
			$replaceBlock->url = substr($replaceBlock->url, 2); 
		}
	}

	/** Simple string replace using the replacer ( current unused ) 
	 * 
	 * @param mixed $content 
	 * @param array $urls 
	 * @param array $new_urls 
	 * @return mixed 
	 */
	protected function stringReplaceContent($content, $urls, $new_urls)
	{
		$replacer = new Replacer();
		$content = $replacer->replaceContent($content, $urls, $new_urls);

		return $content;
	}

	/** Do a regex replace on the found strings. Try to prevent it picking up relative paths / doubling the CDN path. 
	 * 
	 * @param mixed $content 
	 * @param array $urls 
	 * @param array $new_urls 
	 * @return string|string[]|null 
	 */
	protected function pregReplaceByString($content, $urls, $new_urls)
	{
		/* 
		Pattern:  Negative lookback to / a-z and 0-9 ( URL components / not image closers ) - URL Match - Negative lookforward (same pattern)
		*/
		$count = 0;
		$patterns = array_map(function ($url) {
			return '/(?<!(\/|[a-z]|[0-9]))' . preg_quote($url, '/') . '(?!(\/|[a-z]|[0-9]))/mi'; 
		}, $urls);

		//Log::addTemp('Patterns X replacecount ', $patterns );
		$content = preg_replace($patterns, $new_urls, $content);

		return $content;
	}

	/** Preg replace the background URL on content.
	 * 
	 * @param mixed $content 
	 * @param array $replaceBlocks 
	 * @return string|string[]|null 
	 */
	protected function pregReplaceContent($content, $replaceBlocks)
	{

		$pattern = '/url(\(%%replace%%\))/m';
		$raw_urls = $replace_urls = $patterns = []; 

		foreach($replaceBlocks as $replaceBlock)
		{
			 $raw_url = $replaceBlock->raw_url; 
			 // Rebuild the matches url: pattern ( easier than $1 getting it back )
			 $replace_urls[] = 'url(\'' . $replaceBlock->replace_url . '\')'; 
			 $patterns[] = str_replace('%%replace%%', "" . preg_quote($raw_url, '/') . "", $pattern); 

		}

		$content =preg_replace($patterns, $replace_urls, $content);
		return $content;

	}

	protected function checkContent($content)
	{
		if (true === $this->checkJson($content)) {
			// Slashes in json content can interfere with detection of images and formats. Set flag to re-add slashes on the result so it hopefully doesn't break.
		
			$this->content_is_json = true;
		}
		else
		{
			$this->content_is_json = false;
		}
		return $content;
	}

	//https://www.php.net/manual/en/function.json-validate.php ( comments )
	// Could in time be replaced by json_validate proper. (PHP 8.3)
	protected function checkJson($json, $depth = 512, $flags = 0)
	{
		$bool = UtilHelper::validateJSON($json); 
		return $bool;

	}

	protected function listenFlush()
	{
		add_action('shortpixel/image/after_restore',  [$this, 'flushItem'], 10, 2); // hit this when restoring.
		add_action('shortpixel/image/optimised', [$this, 'flushItem'], 10, 2);
	}


	/**
	 * Flush an Item from the CDN to reqacquire 
	 * 
	 * This should happen when the image has been optimiser / restored or altered in similar ways. 
	 * 
	 *
	 * @param ImageModel $imageModel
	 * @return void
	 */
	public function flushItem(ImageModel $imageModel)
	{

		// Find URL. Non-scaled.
		$url = $imageModel->getURL();

		if ('media' == $imageModel->get('type'))
		{
			if ($imageModel->hasOriginal())
			{
				$url = $imageModel->getOriginalFile()->getURL();
			}
		}

		// Get the nocdn URL as start. 
		$domain = $this->getPurgeURL(['action' => 'purge-cdn-cache']);

		//http://no-cdn.shortpixel.ai/purge-cdn-cache/API_KEY_HERE/FULL_CDN_DOMAIN/costomer-domain.com/wp-content/uploads/2024/12/file-name-without-extension*

		
		// ReplaceBlock should find and replace the URL with all arguments, as in regular operation.
		$replaceBlock = $this->getReplaceBlock($url);
		$replaceBlock->args = $this->createArguments();

		$blocks = $this->createReplacements([$replaceBlock]);
		

		$replaceBlocks = $blocks[0];

		// Find the base (without extension) of the main image. 
		$full_cdn_url = $this->getURLBase($replaceBlocks->replace_url);

		$flush_url = $domain . $full_cdn_url; 
		//Log::addDebug('Flush URL : ' . $flush_url);

		$getArgs = [
			'timeout'=> 8,
			'sslverify' => apply_filters('shortpixel/system/sslverify', true),
			'blocking' => false, 
		];

		$result = wp_remote_get($flush_url, $getArgs);

	}

	/**
	 * Hack and Slash until we have the base image URL without other definitions. 
	 *
	 * @param string $url
	 * @return string result URL
	 */
	private function getURLBase($url)
	{
		$url = substr($url,0, strrpos($url, '.')  );

		//$url = str_replace(['http://', 'https://'], '', $url);

		/*if (strpos($url, '-scaled') !== false)
		{
			$url = str_replace('-scaled', '', $url);
		} */

		$url = $url . '*';
		return $url;
	}
} // class
