<?php
namespace ShortPixel\Controller\Front;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

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

				if (false === $this->shouldConvert())
				{
					 return false;
				}

				$settings = wpSPIO()->settings();
				$this->setDefaultCDNArgs();
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

				$settings = \wpSPIO()->settings();

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
					Log::addTemp('Return Script: ', $src);
				return $src;
		}

		protected function processFront($content)
		{
				if (false === $this->checkPreProcess())
				{
					 return $content;
				}


        /*** @TODO TEST DATAAAA! **/
    /*    $content = '{"fragments":{"div.widget_shopping_cart_content":"<div class=\"widget_shopping_cart_content\">\n<div class=\"shopping-cart-widget-body wd-scroll\">\n\t<div class=\"wd-scroll-content\">\n\n\t\t\t\t\t\n\t\t\t<ul class=\"cart_list product_list_widget woocommerce-mini-cart \">\n\n\t\t\t\t\t\t\t\t\t\t\t<li class=\"woocommerce-mini-cart-item mini_cart_item\" data-key=\"5dd9db5e033da9c6fb5ba83c7a7ebea9\">\n\t\t\t\t\t\t\t\t<a href=\"https:\/\/catalin.shortpixel.com\/product\/test-product-1\/\" class=\"cart-item-link wd-fill\">Show<\/a>\n\t\t\t\t\t\t\t\t<a href=\"https:\/\/catalin.shortpixel.com\/cart\/?remove_item=5dd9db5e033da9c6fb5ba83c7a7ebea9&#038;_wpnonce=d7b14c4c6b\" class=\"remove remove_from_cart_button\" aria-label=\"Remove Test product 1 from cart\" data-product_id=\"671\" data-cart_item_key=\"5dd9db5e033da9c6fb5ba83c7a7ebea9\" data-product_sku=\"\" data-success_message=\"&ldquo;Test product 1&rdquo; has been removed from your cart\">&times;<\/a>\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t<a href=\"https:\/\/catalin.shortpixel.com\/product\/test-product-1\/\" class=\"cart-item-image\">\n\t\t\t\t\t\t\t\t\t\t<img width=\"300\" height=\"300\" src=\"https:\/\/catalin.shortpixel.com\/wp-content\/uploads\/2024\/11\/file_example_PNG_500kB-324x324.jpg\" class=\"attachment-woocommerce_thumbnail size-woocommerce_thumbnail\" alt=\"\" decoding=\"async\" loading=\"lazy\" srcset=\"https:\/\/catalin.shortpixel.com\/wp-content\/uploads\/2024\/11\/file_example_PNG_500kB-324x324.jpg 324w, https:\/\/catalin.shortpixel.com\/wp-content\/uploads\/2024\/11\/file_example_PNG_500kB-150x150.jpg 150w\" sizes=\"auto, (max-width: 300px) 100vw, 300px\" \/>\t\t\t\t\t\t\t\t\t<\/a>\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\n\t\t\t\t\t\t\t\t<div class=\"cart-info\">\n\t\t\t\t\t\t\t\t\t<span class=\"wd-entities-title\">\n\t\t\t\t\t\t\t\t\t\tTest product 1\t\t\t\t\t\t\t\t\t<\/span>\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\n\t\t\t\t\t\t\t\t\t\n\t\t\t\t\t\t\t\t\t<span class=\"quantity\">1 &times; <span class=\"woocommerce-Price-amount amount\"><bdi>99,00&nbsp;<span class=\"woocommerce-Price-currencySymbol\">lei<\/span><\/bdi><\/span><\/span>\t\t\t\t\t\t\t\t<\/div>\n\n\t\t\t\t\t\t\t<\/li>\n\t\t\t\t\t\t\t\t\t\t<\/ul>\n\t\t\n\t<\/div>\n<\/div>\n\n<div class=\"shopping-cart-widget-footer\">\n\t\n\t\t\t\t\t<p class=\"woocommerce-mini-cart__total total\">\n\t\t\t\t<strong>Subtotal:<\/strong> <span class=\"woocommerce-Price-amount amount\"><bdi>99,00&nbsp;<span class=\"woocommerce-Price-currencySymbol\">lei<\/span><\/bdi><\/span>\t\t\t<\/p>\n\t\t\n\t\t\n\t\t<p class=\"woocommerce-mini-cart__buttons buttons\"><a href=\"https:\/\/catalin.shortpixel.com\/cart\/\" class=\"button btn-cart wc-forward\">View cart<\/a><a href=\"https:\/\/catalin.shortpixel.com\/checkout\/\" class=\"button checkout wc-forward\">Checkout<\/a><\/p>\n\n\t\t\n\t\n\t<\/div>\n<\/div>","span.wd-cart-number_wd":"\t\t<span class=\"wd-cart-number wd-tools-count\">1 <span>item<\/span><\/span>\n\t\t","span.wd-cart-subtotal_wd":"\t\t<span class=\"wd-cart-subtotal\"><span class=\"woocommerce-Price-amount amount\"><bdi>99,00&nbsp;<span class=\"woocommerce-Price-currencySymbol\">lei<\/span><\/bdi><\/span><\/span>\n\t\t"},"cart_hash":"aa33c6348ef950ec8b922ebe9a3705a5"}'; */

        $original_content = $content;
        $content = $this->checkContent($content);

				$args = [];
				$image_matches = $this->fetchImageMatches($content, $args);
				$replaceBlocks = $this->extractImageMatches($image_matches);

			//	$document_matches = $this->fetchDocumentMatches($content, $args);
			//	$urls = array_merge($url, $this->extraDocumentMatches($document_matches));

				$replaceBlocks = $this->filterRegexExclusions($replaceBlocks);

				$this->createReplacements($replaceBlocks);

      //  $replace_function = ($this->replace_method == 'preg') ? 'pregReplaceContent' : 'stringReplaceContent';
        $replace_function = 'stringReplaceContent'; // undercooked, will defer to next version

        $urls = array_column($replaceBlocks, 'raw_url');
				$replace_urls = array_column($replaceBlocks, 'replace_url');

Log::addTemp('Array result', [$urls, $replace_urls]);

        $content = $this->$replace_function($original_content, $urls, $replace_urls);

      /*  if (true === $this->content_is_json)
        {
           $content = addslashes($content);
        } */
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

			$imageData = $blockData = [];
			foreach($matches as $match)
			{
				 $imageObj = new FrontImage($match);
				 $src = $imageObj->src;

				 if (! is_null($src))
				 {
					 $imageBlock = $this->getReplaceBlock($src);
					 $blockData[] = $imageBlock;
					 $imageData[] = $imageBlock->url;
				 }

				 // Additional sources.
				 $images = $imageObj->getImageData();
         echo "IMAGEDATA"; print_r($images);
				 foreach($images as $image)
				 {
						$imageBlock = $this->getReplaceBlock($image);
						if (! in_array($image, $imageData))
						{
							$blockData[] = $imageBlock;
							$imageData[] = $imageBlock->url;
						}
				 }
			}


			return $blockData;
		}


    /** @param $urls Array Source URLS
    * @return Updated URLs - The string that the original values should be replaced with
    */
		protected function createReplacements($replaceBlocks)
		{
				$cdn_domain = $this->cdn_domain;

				foreach($replaceBlocks as $replaceBlock)
				{
						$parsed = parse_url($replaceBlock->url);
						$replaceBlock->parsed = $parsed;

						$this->checkDomain($replaceBlock);
						$this->checkScheme($replaceBlock);

						// Take Parsed URL and add CDN info to add.
						$url = $replaceBlock->url;
						$url = str_replace(['http://', 'https://'], '', $url); // always remove scheme
						$url = apply_filters('shortpixel/front/cdn/url', $url);

						$cdn_prefix = trailingslashit($cdn_domain) . trailingslashit($this->getCDNArguments($url));
						$replaceBlock->replace_url = $cdn_prefix . trim($url);

				}

		}


    // Special checks / operations because the URL is replaced. Data check.

		// @todo Transform these functions to 1 check each, so each combination can use it's own mix/match of checks / transforms ( image, css, javascript  ) . Possibly with URL as argument and parsed_url as non-optional second param.
		protected function checkDomain($replaceBlock)
    {
				$original_url = $replaceBlock->url; // debug poruposes.

//Log::addTemp('Check Domain - Parsed', $replaceBlock->parsed);
				//$parsedUrl = parse_url($url);
				if (! isset($replaceBlock->parsed['host']))
        {
						$site_url  = $this->site_url;
						if (substr($replaceBlock->parsed['path'], 0, 1) !== '/')
            {
                $site_url .= '/';
            }

						$url = $site_url . $original_url;
						$replaceBlock->parsed = parse_url($url); // parse the new URL
            $replaceBlock->url = $url;
            Log::addTemp("URL from $original_url changed to $url");
        }

    }

		private function checkScheme($replaceBlock)
		{
				$this->setCDNArgument('scheme', null);
        if (isset($replaceBlock->parsed['scheme']) && 'http' == $replaceBlock->parsed['scheme'])
				{
						$this->setCDNArgument('scheme', 'p_h');
				}
		}

    protected function stringReplaceContent($content, $urls, $new_urls)
		{

    //	$count = 0;
    //	$content = str_replace($urls, $new_urls, $content, $count);

      $replacer = new Replacer();
      $content = $replacer->replaceContent($content, $urls, $new_urls);


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

    protected function checkContent($content)
    {

       if (true === $this->checkJson($content))
       {
          // Slashes in json content can interfere with detection of images and formats. Set flag to re-add slashes on the result so it hopefully doesn't break.
           $content = stripslashes($content);
           $this->content_is_json = true;
       }
       return $content;
    }

//https://www.php.net/manual/en/function.json-validate.php ( comments )
// Could in time be replaced by json_validate proper. (PHP 8.3)
    protected function checkJson($json, $depth = 512, $flags = 0)
    {
          if (!is_string($json)) {
            return false;
        }

        try {
            json_decode($json, false, $depth, $flags | JSON_THROW_ON_ERROR);
            return true;
        } catch (\JsonException $e) {
            return false;
        }
    }








} // class
