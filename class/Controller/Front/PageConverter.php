<?php
namespace ShortPixel\Controller\Front;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Base class for front-end page-level HTML transformation controllers.
 *
 * PageConverter provides the shared scaffold used by both PictureController
 * (WebP/AVIF <picture>-tag injection) and CDNController (CDN URL rewriting).
 * It handles:
 *  - Environment gate-keeping via shouldConvert(): returns false for admin,
 *    AJAX, cron, JSON, and known visual page-builders so those surfaces are
 *    never touched.
 *  - Output-buffer management: startOutputBuffer() wraps ob_start() with a
 *    named method of the subclass as the flush callback.
 *  - A status_header hook that captures the HTTP response code so processFront
 *    callbacks can bail on 404 responses (checkPreProcess).
 *  - A suite of URL normalisation helpers (getReplaceBlock, trimURL,
 *    addEscapedUrl, stripSlashesUrl, removeCharactersUrl) that produce a
 *    stdClass "replace block" with raw_url, url, parsed, and args fields.
 *  - Filter helpers (filterRegexExclusions, filterOtherDomains,
 *    filterEmptyURLS, filterDoubles) that reduce a replace-block array to only
 *    the blocks that should actually be rewritten.
 *
 * Subclasses must not call ob_start() directly; use startOutputBuffer() so
 * the callback is correctly bound to the instance.
 */
class PageConverter extends \ShortPixel\Controller
{

	/** @var string Full home URL of the site (e.g. https://example.com). */
	protected $site_url;

	/** @var string Registered-domain portion of site_url used for cross-domain filtering (e.g. example.com). */
	protected $site_domain;

	/** @var int HTTP status code captured from the status_header filter; -1 means not yet set. */
	protected $status_header = -1;

	/** @var array Glob/regex patterns; URLs matching any entry are excluded from rewriting. */
	protected $regex_exclusions = [];


	/**
	 * Initialises site URL and domain properties used throughout the pipeline.
	 */
	public function __construct()
	{
      $this->site_url =  get_home_url();
      $this->site_domain = $this->getDomain($this->site_url);
	}

	/**
	 * Determines whether front-end conversion should run for the current request.
	 *
	 * Returns false (suppressing conversion) for:
	 *  - WordPress admin, AJAX, JSON, and cron contexts.
	 *  - Known visual page-builders detected via GET parameters (Beaver Builder,
	 *    Divi, Bricks, Breakdance, Oxygen, Avada Live).
	 *  - The ?spio_no_cdn and ?PageSpeed=off escape hatches.
	 *  - Any non-frontend context (is_front === false).
	 *
	 * When conversion is allowed, registers the status_header_sent filter so
	 * that a subsequent 404 response can be detected by checkPreProcess().
	 * Returns the result of the 'shortpixel/front/convert_this_page' filter,
	 * allowing third-party code to suppress conversion.
	 *
	 * @return bool True when conversion should proceed, false otherwise.
	 */
	protected function shouldConvert()
	{
		$env = wpSPIO()->env();

    $checks = [ $env->is_admin,
       $env->is_ajaxcall,
       $env->is_jsoncall,
       $env->is_croncall,
    ];

		if ($env->is_admin || $env->is_ajaxcall || $env->is_jsoncall || $env->is_croncall )
		{
			return false;
		}

    // Beaver Builder
    if (isset($_GET['fl_builder']))
    {
       return false;
    }

    // Divi Builder
    if (isset($_GET['et_fb']))
    {
       return false;
    }

    // Bricks Builder
    if (isset($_GET['bricks']))
    {
       return false;
    }

    // Breakdance Builder
    if (isset($_GET['breakdance']) || isset($_GET['breakdance_browser']))
    {
       return false;
    }

    // Oxygen Builder
    if (isset($_GET['ct_builder']))
    {
      return false;
    }

    // Avada Live Builder
    if (isset($_GET['fb-edit']))
    {
      return false;
    }

    if (isset($_GET['spio_no_cdn']))
    {
       return false;
    }

    if (isset($_GET['PageSpeed']) && 'off' === $_GET['PageSpeed'])
    {
       return false;
    }

    if (isset($_GET['oxygen']) && 'builder' === $_GET['oxygen'])
    {
       return false;
    }

    if (false === \wpSPIO()->env()->is_front) // if is front.
    {
       return false; 
    }


	 add_filter('status_header', [$this, 'status_header_sent'], 10, 2);

   $bool = apply_filters('shortpixel/front/convert_this_page', true);
   return $bool ;
	}

	/**
	 * Starts PHP output buffering with a bound instance method as the flush callback.
	 *
	 * The named $callback must be a public or protected method on the subclass.
	 * When the buffer is flushed (e.g. at shutdown), WordPress passes the
	 * accumulated HTML to that method for transformation before it is sent to
	 * the browser.
	 *
	 * @param string $callback Name of the instance method to use as the ob_start callback.
	 * @return void
	 */
	protected function startOutputBuffer($callback) {

			$call = array($this, $callback);
			ob_start( $call );

	}

	/**
	 * Final pre-transform gate checked immediately before HTML rewriting begins.
	 *
	 * Catches conditions that can only be known after shouldConvert() ran,
	 * particularly a 404 response code captured by status_header_sent(). If
	 * the response is a 404, rewriting is suppressed to avoid corrupting error
	 * pages.
	 *
	 * @return bool True when it is safe to proceed with conversion, false otherwise.
	 */
	protected function checkPreProcess() : bool
	{

		 if (404 == $this->status_header)
		 {
				return false;
		 }
		 return true;
	}

	/**
	 * Captures the HTTP status code from the 'status_header' filter.
	 *
	 * Hooked at priority 10 on 'status_header'. Stores the numeric code so
	 * checkPreProcess() can suppress conversion on non-200 responses.
	 *
	 * @param string $status The full status header string (e.g. "HTTP/1.1 404 Not Found").
	 * @param int    $code   The numeric HTTP status code.
	 * @return string Unmodified $status value (passthrough filter).
	 */
	public function status_header_sent($status, $code)
	{
		$this->status_header = $code;
		 return $status;
	}


	/**
	 * Removes replace blocks whose raw_url matches any configured exclusion pattern.
	 *
	 * Patterns in $this->regex_exclusions are passed to preg_grep(). Glob-style
	 * wildcard patterns (e.g. '*gravatar.com*') must already be translated to
	 * valid PCRE before being stored in the exclusions list. Any block whose
	 * raw_url appears in the matched set is removed from the returned array.
	 *
	 * @param array $replaceBlocks Array of replace-block stdClass objects.
	 * @return array Filtered array with excluded blocks removed; keys are not reset.
	 */
	protected function filterRegexExclusions($replaceBlocks)
  {
			 $patterns = $this->regex_exclusions;
			 $imageData = array_column($replaceBlocks, 'raw_url');

       if (! is_array($patterns) || count($patterns) == 0 )
       {
				 Log::addWarn('No Patterns for exclusions');
          return $imageData;
       }

       $allMatches = [];
       foreach($patterns as $pattern)
       {
         $matches = preg_grep($pattern, $imageData);
         if (false !== $matches)
         {
            $allMatches = array_merge($allMatches, $matches);
         }

       }

			 $replaceBlocks = array_filter($replaceBlocks, function ($replaceBlock) use ($allMatches) {
							if (in_array($replaceBlock->raw_url, $allMatches))
							{
								return false;
							}
							return true;
			 }); // Filter function

			return $replaceBlocks;
  }

  /**
   * Removes replace blocks whose URL belongs to a domain other than the current site.
   *
   * A block is excluded when its parsed['host'] is set (i.e. the URL is
   * absolute) and the URL does not contain $this->site_domain. Relative URLs
   * (no host component) are always kept so that they can be made absolute by
   * checkDomain() in CDNController.
   *
   * @param array $replaceBlocks Array of replace-block stdClass objects.
   * @return array Filtered array; keys are not reset.
   */
  protected function filterOtherDomains($replaceBlocks)
  {
     $replaceBlocks = array_filter($replaceBlocks, function ($replaceBlock)
     {
          // Check if block if from different domain (skip) but only if host set ( not relative )
          if (strpos($replaceBlock->url, $this->site_domain) === false && isset($replaceBlock->parsed['host']))
          {
             return false;
          }
          return true;
     });

     return $replaceBlocks;
  }

  /**
   * Removes replace blocks with an empty or unparseable URL.
   *
   * Discards blocks where url trims to an empty string, or where the parsed
   * URL contains neither a 'path' nor a 'host' component (most likely a
   * non-URL value such as a bare colour or keyword that leaked through the
   * regex).
   *
   * @param array $replaceBlocks Array of replace-block stdClass objects.
   * @return array Filtered array; keys are not reset.
   */
  protected function filterEmptyURLS($replaceBlocks)
  {
    //  $imageData = array_column($replaceBlocks, 'url');

      $replaceBlocks = array_filter($replaceBlocks, function ($replaceBlock)
      {
          if (strlen(trim($replaceBlock->url)) == 0)
          {
             return false;
          }

          $parsed = $replaceBlock->parsed; 
          // Most likely a non-url.
          if (! isset($parsed['path']) && ! isset($parsed['host']))
          {
             return false; 
          }

          return true;
      });

      return $replaceBlocks;
  }

  /**
   * Removes replace blocks that are exact duplicates of an already-seen source/replacement pair.
   *
   * Iterates the block list tracking seen (raw_url, replace_url) pairs.  When a
   * block's raw_url was seen before and its replace_url maps to the same
   * position in the found-replaced list, the block is marked for removal.
   * Non-duplicate blocks are kept as-is. The returned array is re-indexed with
   * array_values().
   *
   * @param array $replaceBlocks Array of replace-block stdClass objects (must have replace_url set).
   * @return array De-duplicated, re-indexed array of replace-block stdClass objects.
   */
  protected function filterDoubles($replaceBlocks)
  {
   $foundSources = $foundReplaced = $removeIndex = [];

   foreach($replaceBlocks as $index => $replaceBlock)
   {
      $url = $replaceBlock->raw_url; 
      $replace_url = $replaceBlock->replace_url; 

      if (in_array($url, $foundSources))  
      {
          $found_index = array_search($url, $foundSources);
          if (in_array($replace_url, $foundReplaced) && $found_index == array_search($replace_url, $foundReplaced))
          {
             $removeIndex[] = $index; 
          }
      }
      else
      {
         $foundSources[] = $url; 
         $foundReplaced[] = $replace_url; 
      }

   }

   foreach($removeIndex as $counter => $remove)
   {
       unset($replaceBlocks[$remove]);
   }

   // Reset Index.
   $replaceBlocks = array_values($replaceBlocks); 

   return $replaceBlocks;
      
  }


	/**
	 * Builds a replace-block stdClass from a raw URL string harvested from HTML.
	 *
	 * The returned object has the following properties:
	 *  - raw_url  (string) — trimmed original URL as it appears in the document;
	 *                        used as the search string in all str_replace / preg calls.
	 *  - url      (string) — sanitised URL ready for parse_url(); quotes and
	 *                        backslash escapes removed, esc_url() normalisation applied.
	 *  - parsed   (array)  — result of parse_url($url); may be missing 'host' for
	 *                        relative or protocol-relative URLs.
	 *  - args     (array)  — empty by default; populated by the subclass before
	 *                        passing the block to createReplacements().
	 *
	 * @param string $url Raw URL string as extracted from HTML (may include srcset size descriptors, quotes, etc.).
	 * @return \stdClass Replace-block object.
	 */
	protected function getReplaceBlock($url)
	{
		$block = new \stdClass;
      $block->args = [];
			// Trim to limit area of search / replace, but URL should NOT be alterated here!

      $block->raw_url = $this->trimURL($url);  // raw URL is the base for replacement and should match what's in document.

      // From Url('') formats, the regex is selected often with single quotes. Filter them out for parsing, but they should be in raw_url for replacing
      $url = $block->raw_url; 

      if (strpos($url, '"') !== false || strpos($url, "'") !== false || strpos($url, '&quot;') !== false)
      {
         $url = str_replace(['"', "'", '&quot;'], '', $url);
      }
			// Pre-parse checks

      $url = $this->addEscapedUrl($url);
      $url = $this->stripSlashesUrl($url);
      $url = $this->removeCharactersUrl($url);

			if (filter_var($url, FILTER_VALIDATE_URL) === false)
			{
      //   Log::addInfo('Replacement String still not URL - ' . $url);
			}

			$block->url = $url;
			$block->parsed = parse_url($url);

			return $block;
	}

	/**
	 * Trims whitespace and strips srcset size descriptors (e.g. " 200w") from a URL string.
	 *
	 * Uses strtok to split on the first space, retaining only the URL token.
	 * This is a pre-raw-url step: the result is stored in raw_url and used
	 * verbatim as the search string, so the URL must not be structurally altered.
	 *
	 * @param string $url Raw URL possibly containing srcset descriptors.
	 * @return string Trimmed URL without size descriptors.
	 */
	private function trimURL($url)
	{
			$url = trim(strtok($url, ' '));
			return $url;
	}

	/**
	 * Removes WordPress-added backslash escapes from a URL.
	 *
	 * Wraps wp_unslash() to undo escaping that WordPress may have introduced
	 * (e.g. \' or \") before the URL is parsed or compared.
	 *
	 * @param string $url URL potentially containing backslash escapes.
	 * @return string Unslashed URL.
	 */
	protected function stripSlashesUrl($url)
	{
			return wp_unslash($url);
	}

	/**
	 * Strips double-quote and single-quote characters from a URL.
	 *
	 * Applied after stripSlashesUrl() to ensure quote delimiters from CSS
	 * url('…') or srcset="…" contexts do not contaminate parse_url() results.
	 *
	 * @param string $url URL possibly containing quote characters.
	 * @return string URL with quotes removed.
	 */
	protected function removeCharactersUrl($url)
	{
		 $url = str_replace(['"', "'"],'', $url);
		 return $url;
	}

	/**
	 * Normalises a URL through esc_url() if it differs from the raw value.
	 *
	 * Some URLs extracted from HTML have been esc_url()-encoded by WordPress or
	 * DOMDocument; others have not.  Applying esc_url() only when the output
	 * differs ensures the stored URL matches what will appear in the document
	 * for search-and-replace to succeed.
	 *
	 * @param string $url URL to normalise.
	 * @return string esc_url()-normalised URL.
	 */
	private function addEscapedUrl($url)
	{
			$escaped = esc_url($url);

			if ($escaped !== $url)
			{
				 $url = esc_url($url);
			}

			return $url;
	}

	/**
	 * Extracts the registered domain (eTLD+1) from a full URL.
	 *
	 * Uses a regex to match the last two DNS labels (e.g. "example.com") from
	 * the host component of $url. Falls back to the full PHP_URL_HOST value if
	 * the pattern does not match (e.g. for localhost or bare IP addresses).
	 *
	 * @param string $url Full URL to extract the domain from.
	 * @return string|null Registered domain string, or null if parse_url fails.
	 */
// https://stackoverflow.com/questions/276516/parsing-domain-from-a-url
private function getDomain($url)
{
    $matches = preg_match("/[a-z0-9\-]{1,63}\.[a-z\.]{2,63}$/", parse_url($url, PHP_URL_HOST), $_domain_tld);
    if (false === $matches || 0 === $matches) // If pattern fails.
    {
       return parse_url($url, PHP_URL_HOST);
    }

    return $_domain_tld[0];
}


} // class PageConverter
