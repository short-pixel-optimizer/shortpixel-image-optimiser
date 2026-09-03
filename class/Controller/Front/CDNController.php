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


/**
 * Front-end CDN URL rewriting controller.
 *
 * CDNController extends PageConverter to rewrite on-page image (and optionally
 * JS/CSS) URLs so they are served through a ShortPixel CDN domain.  It runs as
 * an output-buffer callback (processFront) that receives the fully-rendered HTML
 * of each front-end page and returns it with qualifying URLs prefixed by the CDN
 * domain and a comma-separated argument string (compression, WebP/AVIF format
 * tokens, scheme hints, etc.).
 *
 * Lifecycle:
 *  1. __construct() calls PageConverter::__construct(), listenFlush() (hooks
 *     flushItem to image optimise/restore actions), and loadCDNDomain().
 *  2. shouldConvert() (inherited) gates on environment context.
 *  3. init() registers optional script_loader_src / style_loader_src hooks for
 *     JS and CSS CDN delivery, then starts the output buffer with processFront
 *     as the callback.
 *
 * HTML transform pipeline (processFront):
 *  - Inline CSS backgrounds are extracted via fetchInlineBackground() and
 *    rewritten by pregReplaceContent().
 *  - <img> and <source srcset> blocks are extracted via fetchImageMatches() /
 *    extractImageMatches() and rewritten by pregReplaceByString() after being
 *    sorted and grouped by imageId.
 *  - JSON responses (detected via checkContent/checkJson) receive additional
 *    JSON-slash encoding so CDN URLs do not break serialised HTML payloads.
 *
 * CDN argument format: comma-separated tokens assembled by createArguments(),
 * e.g. "ret_img,q_cdnize,to_webp,s_webp" prefixed to the host-stripped URL.
 */
class CDNController extends \ShortPixel\Controller\Front\PageConverter
{

	/** @var string Trailing-slash CDN domain with /spio/ path appended (e.g. https://cdn.example.com/spio/). */
	protected $cdn_domain;

	/** @var array Reserved for future CDN argument defaults; currently unused. */
	protected $cdn_arguments = [];

	/** @var array Reserved for future per-URL skip rules; currently unused. */
	protected $skip_rules = [];

	/** @var string Replacement strategy: 'preg' (default) or 'string' (via Replacer). */
	protected $replace_method = 'preg';

	/** @var bool True when the current response body is detected as JSON; triggers JSON-slash encoding of replaced URLs. */
	private $content_is_json = false;


	/**
	 * Boots the CDN controller: loads the CDN domain, gates on shouldConvert(),
	 * and delegates further setup to init().
	 */
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

	/**
	 * Registers WP hooks and starts the output buffer.
	 *
	 * Calls addWPHooks() to register optional JS/CSS hooks, then opens an output
	 * buffer whose flush callback is processFront(). Also populates
	 * $this->regex_exclusions via the 'shortpixel/front/cdn/regex_exclude' filter
	 * and sets $this->replace_method via 'shortpixel/front/cdn/replace_method'.
	 *
	 * @return void
	 */
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


	/**
	 * Registers or deregisters the site domain with the ShortPixel CDN API.
	 *
	 * Builds a no-cdn.shortpixel.ai endpoint URL from the site's host and the
	 * account API key, then issues a wp_remote_post() request. The 'action'
	 * argument controls whether the path contains 'add-domain' (register) or
	 * 'revoke-domain' (deregister).
	 *
	 * Returns false if the site URL cannot be parsed (no 'host' component).
	 * On success the function returns no value (void); the API response is
	 * currently not checked or returned.
	 *
	 * @param array $args {
	 *     @type string $action 'register' (default) or 'deregister'.
	 * }
	 * @return false|void False when the site host cannot be determined, void otherwise.
	 */
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

	/**
	 * Purges CDN cache for JS/CSS assets or the entire site.
	 *
	 * Two purge modes are supported via $args['purge']:
	 *  - 'cssjs': Bumps the stored cdn_purge_version setting (a 4-digit Unix
	 *    timestamp suffix) so asset URLs acquire a new cache-busting version
	 *    query arg.  No remote call is made.
	 *  - 'all': Issues a wp_remote_post() to the no-cdn.shortpixel.ai
	 *    purge-cdn-cache-bulk endpoint.  Checks the JSON response for
	 *    Status == 2 to confirm success.
	 *
	 * @param array $args {
	 *     @type string $purge Purge mode: 'cssjs' or 'all'.
	 * }
	 * @return array {
	 *     @type bool   $is_error True when the remote call failed.
	 *     @type string $message  Human-readable result or error string.
	 * }
	 */
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

	/**
	 * Builds the no-cdn.shortpixel.ai endpoint URL for a given CDN action.
	 *
	 * The URL format varies by action:
	 *  - 'purge-cdn-cache': <base>/<action>/<apikey>/  (no site/CDN host suffix;
	 *    the caller appends the asset path).
	 *  - Any other action (e.g. 'purge-cdn-cache-bulk'):
	 *    <base>/<action>/<apikey>/<site-host>/<cdn-host>
	 *
	 * Falls back to 'spcdn.shortpixel.ai' when the CDNDomain setting cannot
	 * be parsed to a host component.
	 *
	 * @param array $args {
	 *     @type string $action API action path segment (e.g. 'purge-cdn-cache-bulk').
	 * }
	 * @return string Fully constructed endpoint URL (no trailing slash).
	 */
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

	/**
	 * Builds the CDN argument array for a URL replacement block.
	 *
	 * Assembles key=value tokens that are later joined with commas and inserted
	 * between the CDN domain and the asset URL, e.g.
	 * "https://cdn.example.com/spio/ret_img,q_cdnize,to_webp,s_webp/example.com/…".
	 *
	 * Compression is always 'q_cdnize'.  The 'return' key defaults to 'ret_img'
	 * but callers may override it (e.g. 'ret_auto' for scripts).  WebP/AVIF
	 * format and extension-doubling tokens are derived from plugin settings and
	 * environment capability flags.
	 *
	 * @param array $args Initial argument overrides; supports 'return' and 'version'.
	 * @return array Associative array of CDN argument tokens ready for implode(',').
	 */
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

	/**
	 * Registers WordPress hooks for JS and CSS CDN delivery.
	 *
	 * When the cdn_js setting is enabled, hooks processScript() on
	 * 'script_loader_src'.  When cdn_css is enabled, hooks processScript() on
	 * 'style_loader_src'.  Both hooks run at priority 10 and receive the src
	 * URL and handle as arguments.
	 *
	 * @return void
	 */
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

	/**
	 * Rewrites a single script or style src URL to use the CDN domain.
	 *
	 * Hooked on 'script_loader_src' and 'style_loader_src'.  Bails early if
	 * checkPreProcess() fails, $src is empty, the URL is excluded by regex
	 * rules, belongs to another domain, or does not end with a recognised
	 * extension (.js, .css, .ttf, .woff, .woff2, .otf depending on settings).
	 *
	 * Uses 'ret_auto' return mode and appends a cdn_purge_version token so that
	 * cached JS/CSS assets can be invalidated.
	 *
	 * @param string $src    The script/style source URL.
	 * @param string $handle The registered script/style handle (unused but required by WP filter signature).
	 * @return string CDN-rewritten URL, or the original $src if the URL was excluded or conversion is disabled.
	 */
	public function processScript($src, $handle)
	{
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

		return $src;
	}

	/**
	 * Output-buffer callback: rewrites all qualifying image and background URLs in a full HTML page.
	 *
	 * Receives the raw buffered HTML and returns it with CDN URLs substituted.
	 * Processing order:
	 *  1. checkPreProcess() bail (e.g. 404 response).
	 *  2. checkContent() to detect JSON payloads and set $content_is_json.
	 *  3. Inline CSS url() backgrounds via fetchInlineBackground() →
	 *     filterEmptyURLS → filterRegexExclusions → filterOtherDomains →
	 *     filterFonts → createReplacements → pregReplaceContent.
	 *  4. <img> / <source srcset> blocks via fetchImageMatches() →
	 *     extractImageMatches() → filter chain → createReplacements →
	 *     pregReplaceByString (per imageId group).
	 *
	 * When no replacements survive the filter chain for either pass, the method
	 * returns the original (pre-checkContent) content to avoid spurious changes.
	 * JSON payloads receive URL-encoded CDN URLs via encodeForJson().
	 *
	 * @param string $content Full HTML (or JSON) page content from the output buffer.
	 * @return string Content with qualifying image/background URLs rewritten to CDN.
	 */
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

	/**
	 * Encodes a URL for use inside a JSON string by applying JSON serialisation rules.
	 *
	 * Runs json_encode() on the URL to escape forward slashes and special
	 * characters, then strips the surrounding double-quote delimiters that
	 * json_encode() adds.  Used when $content_is_json is true so that CDN URLs
	 * in JSON-embedded HTML are correctly slash-escaped.
	 *
	 * @param string $url URL to encode.
	 * @return string JSON-safe URL without surrounding quotes.
	 */
	private function encodeForJSON($url)
	{
		 $url = json_encode($url);
		 $url = str_replace('"', '', $url);
		 return $url;
	}


	/**
	 * Loads and normalises the CDN domain for URL rewriting.
	 *
	 * When called with $CDNDomain === false (default), reads CDNDomain from
	 * plugin settings and stores the normalised result in $this->cdn_domain.
	 * When called with an explicit domain string, normalises and returns the
	 * result without updating the property (used by validateCDNDomain()).
	 *
	 * Normalisation: if the CDN URL has no path, or a bare '/' path, appends
	 * '/spio/' so the CDN argument string can be inserted cleanly between the
	 * domain and asset URL.
	 *
	 * @param string|false $CDNDomain CDN domain to normalise, or false to load from settings.
	 * @return string|void Normalised CDN domain when $CDNDomain is provided; void when updating the property.
	 */
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

	/**
	 * Validates a CDN domain string by normalising it and comparing the result.
	 *
	 * Passes $CDNDomain through loadCDNDomain() (return-value mode).  If the
	 * normalised result equals the input, the domain is already well-formed and
	 * true is returned.  Otherwise the normalised (corrected) domain is returned
	 * so the caller can show or store it.
	 *
	 * @param string $CDNDomain CDN domain string to validate.
	 * @return true|string True when the domain is valid as-is; normalised domain string otherwise.
	 */
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

	/**
	 * Extracts all <img> and <source srcset> tag strings from HTML content.
	 *
	 * Returns the outer HTML of each matched tag as a flat string array.  The
	 * regex matches <img …> tags and <source> tags that contain a srcset
	 * attribute (allowing arbitrary attributes between <source and srcset=).
	 *
	 * @param string $content HTML content to search.
	 * @param array  $args    Reserved for future use; currently unused.
	 * @return string[] Flat array of matched tag HTML strings.
	 */
	protected function fetchImageMatches($content, $args = [])
	{
		// Previous pattern
		//$number = preg_match_all('/<img[^>]*>|<source srcset="[^>]*">/i', $content, $matches);

		// Updated pattern via - https://github.com/short-pixel-optimizer/shortpixel-image-optimiser/issues/159
		//$number = preg_match_all('/<img[^>]*>|<source\s+srcset="[^"]*"[^>]*>/i', $content, $matches);


		// Updated pattern via - https://support.shortpixel.com/conversation/242094?folder_id=43  ( not only spaces / words in between)
		$number = preg_match_all('/<img[^>]*>|<source.*srcset="[^"]*"[^>]*>/i', $content, $matches);

		$matches = $matches[0];
		return $matches;
	}

	/**
	 * Extracts CSS url() values from HTML content and builds replace blocks for each.
	 *
	 * Uses a recursive PCRE pattern to match balanced parentheses inside url(…),
	 * capturing the inner content (group 2) which may contain quoted or unquoted
	 * URLs.  For each match a replace block is created via getReplaceBlock() and
	 * CDN arguments are attached via createArguments().
	 *
	 * @param string $content HTML content to search (may include inline <style> blocks or style= attributes).
	 * @param array  $args    Reserved for future use; currently unused.
	 * @return \stdClass[] Array of replace-block stdClass objects, one per url() match.
	 */
	protected function fetchInlineBackground($content, $args = [])
	{
		$number = preg_match_all('/url(\(((?:[^()]+|(?1))+)\))/m', $content, $matches);
		$matches = $matches[2];
		
		$replaceBlocks = []; 
		foreach($matches as $url)
		{
			$block = $this->getReplaceBlock($url);
			$block->args = $this->createArguments();
			$replaceBlocks[] = $block; 
		}

		return $replaceBlocks;
	}

	/**
	 * Stub for future document-level URL extraction (e.g. <link>, <script src>).
	 *
	 * Not yet implemented.
	 *
	 * @param string $content HTML content.
	 * @param array  $args    Reserved for future use.
	 * @return void
	 */
	protected function fetchDocumentMatches($content, $args = [])
	{
		//		$number = preg_match_all('')
	}

	/**
	 * Converts raw <img>/<source> HTML strings into replace-block objects.
	 *
	 * For each tag string from fetchImageMatches(), parses it via FrontImage to
	 * obtain the primary src URL and any additional srcset image data.  Creates
	 * one replace block per distinct URL, assigning a shared imageId (e.g.
	 * 'image0') so that processFront() can group all blocks from the same tag
	 * and perform a single str_replace on the tag's outer HTML.
	 *
	 * When $content_is_json is true, the raw tag string is unslashed before
	 * parsing so that JSON escape sequences do not confuse FrontImage.
	 *
	 * @param string[] $matches Flat array of raw <img>/<source> HTML strings.
	 * @return \stdClass[] Array of replace-block objects with htmlMatch and imageId set.
	 */
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


	/**
	 * Populates replace_url on each block by prepending the CDN domain and argument string.
	 *
	 * For every block, strips the scheme from the URL (CDN expects a schemeless
	 * path) and assembles: <cdn_domain><args,joined>/<scheme-stripped-url>.  Any
	 * HTTP URLs add a 'p_h' scheme argument via checkScheme().  Blocks whose URL
	 * has no host (relative) are made absolute via checkDomain(); those blocks
	 * are moved to the end of the returned array so the shorter, absolute-URL
	 * variants are replaced first.
	 *
	 * BUG #55 (deferred fix — see tests/Controller/test-CDNController.php pin tests
	 * `test_pin55_*`): the assembled replace_url uses raw commas to join CDN
	 * arguments (implode(',', $replaceBlock->args) below), producing URLs like
	 * `https://cdn.example.com/spio/ret_img,q_cdnize,to_webp,s_webp/host/img.jpg`.
	 * When these URLs are written into srcset attributes (via processFront ->
	 * pregReplaceByString), WHATWG-conformant browser parsers handle them fine
	 * (URL token = run of non-whitespace; commas mid-token do not split), but
	 * naive comma-splitting crawlers (SEO tools, indexers, some link checkers)
	 * shatter each URL into fragments like `s_webp/host/img.jpg 1031w`, which
	 * resolve to broken relative URLs and generate 404 floods (one customer
	 * report: 62k logged 404s). src attributes and inline background url()
	 * contexts are comma-safe (no splitting is defined there), so the fix only
	 * NEEDS to touch srcset — but a global switch from ',' to '+' or '%2C'
	 * is simpler and safe everywhere. Both delimiters have been verified against
	 * the live spcdn.shortpixel.ai CDN (2026-09-03) as byte-identical to the
	 * comma form including correct WebP content negotiation. '+' is preferred:
	 * legal in URL path per RFC 3986, no per-attribute divergence, and NOT
	 * decoded to space in URL paths (only in query strings). See the pin tests
	 * for a full WHATWG srcset-parser demonstration.
	 *
	 * @param \stdClass[] $replaceBlocks Replace-block objects with url, parsed, and args set.
	 * @return \stdClass[] Same blocks with replace_url populated; relative-URL blocks appended last.
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


	/**
	 * Resolves a relative or protocol-relative URL to an absolute URL using the site URL.
	 *
	 * When a replace block has no 'host' in its parsed URL (e.g. a srcset entry
	 * like "/wp-content/uploads/foo.jpg"), the site URL is prepended.  If the
	 * path does not begin with '/', a trailing slash is added to the site URL
	 * before concatenation.  The block's url and parsed properties are updated
	 * in place.
	 *
	 * @param \stdClass $replaceBlock Replace block to inspect and potentially update (mutated in place).
	 * @return bool True when the URL was changed (was relative/protocol-relative), false otherwise.
	 */
	protected function checkDomain($replaceBlock) : bool
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

	/**
	 * Adds scheme-related CDN arguments and strips protocol-relative prefixes from the URL.
	 *
	 * If the URL's scheme is 'http', adds the 'p_h' arg so the CDN knows to
	 * serve the asset over HTTP rather than HTTPS.  If the URL starts with '//',
	 * strips those two characters so the CDN receives a bare host-prefixed path.
	 * Mutates the replace block in place; returns no value.
	 *
	 * @param \stdClass $replaceBlock Replace block to inspect and potentially update (mutated in place).
	 * @return void
	 */
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
		$content = $replacer->replaceContent($content, $urls, $new_urls, false, true);

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
			
			 // @TODO . Check on Raw_URL if there is " or '  and add that, if none, add none. 
			 if (true === str_contains($raw_url, '"'))
			 {
				$delim = '"'; 
			 }
			 elseif (true === str_contains($raw_url, "'"))
			 {
				 $delim = "'";
			 }
			 else 
			 	$delim = '';
			 // Rebuild the matches url: pattern ( easier than $1 getting it back )
			 $replace_urls[] = 'url(' . $delim . $replaceBlock->replace_url . $delim . ')'; 
			 $patterns[] = str_replace('%%replace%%', "" . preg_quote($raw_url, '/') . "", $pattern); 

		}

		$content =preg_replace($patterns, $replace_urls, $content);
		return $content;

	}

	/**
	 * Inspects content to determine whether it is a JSON payload and sets $content_is_json.
	 *
	 * Passes content through checkJson().  When JSON is detected, sets the
	 * $content_is_json flag so that processFront() applies JSON-slash encoding
	 * to replaced URLs.  Returns the content unchanged.
	 *
	 * @param string $content Page content from the output buffer.
	 * @return string Unmodified $content.
	 */
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

	/**
	 * Checks whether a string is valid JSON.
	 *
	 * Delegates to UtilHelper::validateJSON().  May be replaced by the native
	 * json_validate() once PHP 8.3 is the minimum requirement.
	 *
	 * @param string $json  String to validate.
	 * @param int    $depth Maximum nesting depth (reserved, passed to helper).
	 * @param int    $flags JSON decode flags (reserved, passed to helper).
	 * @return bool True when $json is valid JSON, false otherwise.
	 */
	//https://www.php.net/manual/en/function.json-validate.php ( comments )
	// Could in time be replaced by json_validate proper. (PHP 8.3)
	protected function checkJson($json, $depth = 512, $flags = 0)
	{
		$bool = UtilHelper::validateJSON($json); 
		return $bool;

	}

	/**
	 * Registers WordPress action hooks that trigger CDN cache invalidation.
	 *
	 * Hooks flushItem() on 'shortpixel/image/after_restore' and
	 * 'shortpixel/image/optimised' (both priority 10, 2 args) so that the CDN
	 * cache entry for an image is purged whenever the image is optimised or
	 * restored to its original.
	 *
	 * @return void
	 */
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
