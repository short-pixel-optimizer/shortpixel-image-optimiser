<?php
namespace ShortPixel\Controller\Front;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


class PageConverter extends \ShortPixel\Controller
{

	protected $site_url;
	protected $status_header = -1;
  protected $regex_exclusions = [];


	public function __construct()
	{
			$this->site_url =  get_site_url();
	}

  /** Check if the converters should run on this request.  This is mainly used to filter out frontend pagebuilder where changing images could result in crashing builders and such cases */
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


	 add_filter('status_header', [$this, 'status_header_sent'], 10, 2);

   $bool = apply_filters('shortpixel/front/convert_this_page', true);
   return $bool ;
	}

	protected function startOutputBuffer($callback) {

			$call = array($this, $callback);
			ob_start( $call );

	}

	// Function to check just before doing the conversion if anything popped up that should prevent it.
	protected function checkPreProcess()
	{
		 if (404 == $this->status_header)
		 {
				return false;
		 }
		 return true;
	}

	public function status_header_sent($status, $code)
	{
		$this->status_header = $code;
		 return $status;
	}


  // @param imageData Array with URLS
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
			 Log::addTEmp('RegexExclusions: ', $allMatches);

//       $imageData = array_diff($imageData, $allMatches);
			 $replaceBlocks = array_filter($replaceBlocks, function ($replaceBlock) use ($allMatches) {
							if (in_array($replaceBlock->raw_url, $allMatches))
							{
								return false;
							}
							return true;
			 }); // Filter function

			return $replaceBlocks;
			// return array_values($imageData); // reset indexes
  }


	// For now, in future perhaps integrate somehow with frontIMage, although these functions are also useful for other URLs
	protected function getReplaceBlock($url)
	{
			$block = new \stdClass;
			// Trim to limit area of search / replace, but URL should NOT be alterated here!
			$raw_url = $this->trimURL($url);
			$raw_url = $this->addEscapedUrl($url);
			$block->raw_url = $this->trimURL($url);  // raw URL is the base for replacement and should match what's in document.

			// Pre-parse checks
//			$url = $this->addEscapedUrls($url); // @todo Find out if these options (escape and stripslashes) are mutually exclusive
			$url = $this->stripSlashesUrl($url);
			$url = $this->removeCharactersUrl($url);

			if (filter_var($url, FILTER_VALIDATE_URL) === false)
			{
				 Log::addWarn('Replacement String still not URL - ', $url);
			}

			$block->url = $url;
			//$block->parsed = parse_url($url);

			return $block;
	}

	// Function not only to trim, but also remove extra stuff like '200w' declarations in srcset.
	// This should not alter URL, because it's used as the search in search / replace, so should point to full original URL
	// This is a PRE-RAW FUNCTION on the harvested URL
	private function trimURL($url)
	{
			$url = trim(strtok($url, ' '));
			return $url;
	}

	protected function stripSlashesUrl($url)
	{
			return wp_unslash($url);
	}

	protected function removeCharactersUrl($url)
	{
		 $url = str_replace(['"', "'"],'', $url);
		 return $url;
	}

	// Something in source the Url's can escaped which is undone by domDocument. Still add them to the replacement array, otherwise they won't be replaced properly.
	// This is a PRE-RAW FUNCTION on the harvested URL
	private function addEscapedUrl($url)
	{
			$escaped = esc_url($url);

			if ($escaped !== $url)
			{
				 $url = esc_url($url);
			}

			return $url;
	}


} // class PageConverter
