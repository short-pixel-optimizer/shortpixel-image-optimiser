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
  protected function applyRegexExclusions($imageData)
  {
       $patterns = $this->regex_exclusions;
       if (! is_array($patterns) || count($patterns) == 0 )
       {
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

       $imageData = array_diff($imageData, $allMatches);
       return array_values($imageData); // reset indexes
  }

}
