<?php
namespace ShortPixel\Controller\Front;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


class PageConverter extends \ShortPixel\Controller
{

	protected $site_url;
  protected $site_domain; // domain checks
	protected $status_header = -1;
  protected $regex_exclusions = [];


	public function __construct()
	{
      $this->site_url =  get_home_url();
      $this->site_domain = $this->getDomain($this->site_url);
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

    if (false === \wpSPIO()->env()->is_front) // if is front.
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

			 $replaceBlocks = array_filter($replaceBlocks, function ($replaceBlock) use ($allMatches) {
							if (in_array($replaceBlock->raw_url, $allMatches))
							{
								return false;
							}
							return true;
			 }); // Filter function

			return $replaceBlocks;
  }

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


	// For now, in future perhaps integrate somehow with frontIMage, although these functions are also useful for other URLs
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

// https://stackoverflow.com/questions/276516/parsing-domain-from-a-url
private function getDomain($url)
{
    preg_match("/[a-z0-9\-]{1,63}\.[a-z\.]{2,6}$/", parse_url($url, PHP_URL_HOST), $_domain_tld);
    return $_domain_tld[0];
}


} // class PageConverter
