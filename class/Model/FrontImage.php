<?php
namespace ShortPixel\Model;

use ShortPixel\Model\Image\ImageModel as ImageModel;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


class FrontImage
{
		protected $raw;
		protected $image_loaded = false;
		protected $is_parsable = false;
		protected $imageBase; // directory path of this image.

		protected $id; // HTML ID of image
		protected $alt;
		protected $src;  // original src of image
		protected $srcset; // orginal srcset of image
		protected $class;
		protected $width;
		protected $height;
		protected $style;
		protected $sizes;

		// Array of all other attributes.
		protected $attributes;

		// Parsed items of src /srcset / sizes
		protected $dataTags = array();

		public function __construct($raw_html)
		{
				$this->raw = $raw_html;
				$this->loadImageDom();
		}

		public function loadImageDom()
    {
        if (function_exists("mb_convert_encoding")) {
            $this->raw = mb_encode_numericentity($this->raw, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true); // disable error emit from libxml

        $result = $dom->loadHTML($this->raw, LIBXML_NOWARNING);

        // HTML failed loading
        if (false === $result)
        {
           return false;
        }

        $image = $dom->getElementsByTagName('img')->item(0);
        $attributes = array();

        /* This can happen with mismatches, or extremely malformed HTML.
        In customer case, a javascript that did  for (i<imgDefer) --- </script> */
        if (! is_object($image))
				{
					$this->is_parsable = false;
          return false;
				}

        foreach ($image->attributes as $attr) {
						// Skip is no value
					 if (strlen($attr->nodeValue) == 0)
					 	continue;

					 if (property_exists($this, $attr->nodeName))
					 {
						  $this->{$attr->nodeName} = $attr->nodeValue;
					 }

					 $this->attributes[$attr->nodeName] = $attr->nodeValue;
        }


        // Parse the directory path and other sources
				$result = $this->setupSources();


				if (true === $result)
					$this->image_loaded = true;
    }

		public function hasBackground()
		{
				if (! is_null($this->style) && strpos($this->style, 'background') !== false)
				{
					 return true;
				}
				return false;
		}

		public function hasPreventClasses()
		{
			// no class, no prevent.
			if (is_null($this->class))
			{
				 return false;
			}

			$preventArray = apply_filters('shortpixel/front/preventclasses', array('sp-no-webp', 'rev-sildebg') );

			foreach($preventArray as $classname)
			{
				if (false !== strpos($this->class, $classname) )
				{
					 return true;
				}
			}

			return false;
		}

		public function hasSource()
		{
			  if (is_null($this->src) && is_null($this->srcset))
				{
					 return false;
				}
				return true;
		}

		public function isParseable()
		{
			 if (
				 false === $this->hasPreventClasses() &&
				 false === $this->hasBackground()  &&
				 true === $this->hasSource() &&
				 true === $this->image_loaded
				 )
			{
					return true;
			}

			return false;
		}

		public function getImageData()
		{
			 if (! is_null($this->srcset))
			 {
 			 	 	$data = $this->getLazyData('srcset');
					$data = explode(',', $data); // srcset is multiple images, split.
			 }
			 else {
				 	$data = $this->getLazyData('src');
					$data = array($data);  // single item, wrap in array
			 }

			 $this->getLazyData('sizes'); // sets the sizes.

			 return $data;
		}

		public function getImageBase()
		{
				 if (! is_null($this->imageBase))
			 		return $this->imageBase->getPath();

        return null;
		}

		public function parseReplacement($args)
		{
				if (is_null($this->class))
				{
					 $this->class = '';
				}

				$this->class .= ' sp-no-webp';

				$output = "<picture>";

				if (isset($args['avif']) && count($args['avif']) > 0)
				{
						$output .= $this->buildSource($args['avif'], 'avif');
				}

				if (isset($args['webp']) && count($args['webp']) > 0)
				{
						$output .= $this->buildSource($args['webp'], 'webp');
				}

				$output .= $this->buildImage();

				$output .= "</picture>";

				return $output;
		}


		protected function setupSources()
		{
			$src = null;

			if (! is_null($this->src))
			{
				$src = $this->src;
			}
			elseif (! is_null($this->srcset))
			{
				$parts = preg_split('/\s+/', trim($this->srcset));
				$image_url = $parts[0];
				$src = $image_url;
			}

			if (is_null($src))
			{
				 return false;
			}

      // Filter out extension that are not for us.
      if (false === $this->checkExtensionConvertable($src))
      {
          return false;
      }



			$fs = \wpSPIO()->filesystem();
			$fileObj = $fs->getFile($src);
			$fileDir = $fileObj->getFileDir();
			$this->imageBase = $fileObj->getFileDir();

			return true;
			// If (! is_hnull $srcset)
			// Get first item from srcset ( remove the size ? , then feed it to FS, get directory from it.
		}

    /*** Check if the extension is something we want to check
    * @param String The URL source of the image.
    **/
    private function checkExtensionConvertable($source)
    {
       $extension = substr($source, strrpos($source, '.') + 1);
       if (in_array($extension, ImageModel::PROCESSABLE_EXTENSIONS))
       {
          return true;
       }
       return false;

    }

		protected function buildSource($sources, $fileFormat)
		{

				$prefix = (isset($this->dataTags['srcset'])) ? $this->dataTags['srcset'] : $this->dataTags['src'];
				$srcset = implode(',', $sources);

				$sizeOutput = '';
				if (! is_null($this->sizes))
				{
						$sizeOutput = $this->dataTags['sizes'] . 'sizes="' . $this->sizes . '"';
				}

			  $output = '<source ' . $prefix . 'srcset="' . $srcset . '" ' . $sizeOutput . ' type="image/' . $fileFormat . '">';

				return $output;
		}

		protected function buildImage()
		{
			$src = $this->src;
			$output = '<img src="' . $src . '" ';

			// Get this from set attributes on class.
			$attrs = array('id', 'height', 'width', 'srcset', 'sizes', 'class');
			foreach($attrs as $attr)
			{
				if (! is_null($this->{$attr}))
        {
					$output .= $attr . '="' . $this->{$attr} . '" ';
        }
			}

      // Always output alt tag, because it's important to screen readers and otherwise.
      $output .= 'alt="' . $this->alt . '" ';

			// Left over attributes that should be harmless, ie extra image data or other custom tags.
			$leftAttrs = $this->getImageAttributes();
			foreach($leftAttrs as $name => $value)
			{
	 				$output .= $name . '="' . $value . '" ';
			}

			$output .= ' > '; // ending image.

			return $output;

		}

		protected function getImageAttributes()
		{

			$dontuse = array(
					'src', 'data-src', 'data-lazy-src', 'srcset', 'sizes'

			);
			$dontuse = array_merge($dontuse, array('id', 'alt', 'height', 'width', 'srcset', 'sizes', 'class'));

			$attributes = $this->attributes;

			$leftAttrs = array();
			foreach($attributes as $name => $value)
			{
				 if (! in_array($name, $dontuse ))
				 {
					  $leftAttrs[$name] = $value;
				 }
			}

			return $leftAttrs;
		}

		protected function getLazyData($type)
		{
				$attributes = $this->attributes;
				$value = $prefix = false;

				if (isset($attributes['data-lazy-' . $type]) && strlen($attributes['data-lazy-' . $type]) > 0)
				{
						$value = $attributes['data-lazy-' . $type];
						$prefix = 'data-lazy-';
				}
				elseif( isset($attributes['data-' . $type]) && strlen($attributes['data-' . $type]) > 0)
				{
					 $value = $attributes['data-' . $type];
					 $prefix = 'data-';
				}
				elseif(isset($attributes[$type]) && strlen($attributes[$type]) > 0)
				{
					 $value = $attributes[$type];
					 $prefix = '';
				}

				$this->dataTags[$type] = $prefix;

				return $value;
		}
} // class FrontImage
