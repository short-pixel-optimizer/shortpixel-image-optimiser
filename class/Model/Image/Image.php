<?php
namespace ShortPixel\Model\Image;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


/**
 * Loads an image file into memory as a resource for in-process conversion (e.g. PNG to JPG).
 *
 * Wraps either the GD or Imagick PHP extension, selecting whichever is available in the
 * current WordPress environment. Intended for file-format conversions that must be
 * performed locally rather than through the ShortPixel API.
 *
 * @package ShortPixel\Model\Image
 */

Class Image extends \ShortPixel\Model\File\FileModel
{

        /** @var resource|\Imagick|null The loaded image resource, or null if not yet loaded. */
        protected $image; // The image resource
        /** @var string Image library to use: 'gd' or 'imagick'. */
        protected $useLib = 'gd';
        /** @var string Absolute filesystem path where the converted output file will be written. */
        protected $replacementPath;
        /*protected $width;
        protected $height; */

        /** @var array Associative error array with optional 'message' and 'error_code' keys. */
        protected $error = [];


        /**
         * @param string $path            Absolute path of the source image file to load.
         * @param string $replacementPath Absolute path of the output file that will be created.
         */
        public function __construct($path, $replacementPath)
        {
             parent::__construct($path);

             $this->replacementPath = $replacementPath;
             $this->checkLibrary();
        }

        /**
         * Detect which image processing library is available and set $useLib accordingly.
         *
         * Prefers Imagick over GD when both are installed.
         *
         * @return void
         */
        protected function checkLibrary()
        {
            $env = \wpSPIO()->env(); 
            if ($env->is_gd_installed)
            {
                $this->useLib = 'gd'; 
            }
            elseif ($env->is_imagick_installed)
            {
                $this->useLib = 'imagick';
            }
        }

        /**
         * Check whether the image resource has been successfully loaded into memory.
         *
         * @return bool True if the image resource is ready, false otherwise.
         */
        public function checkImageLoaded()
        {
            if (! is_null($this->image) && false !== $this->image)
            {
                return true;
            }

            return false;
        }

        /**
         * Load the image file into memory as a library resource.
         *
         * Must be called explicitly because it is resource-intensive and fills memory.
         * Currently supports PNG source files only.
         *
         * @return void
         */
        // Must be declared explicit because it's resource-intensive, fills memory
        public function loadImageResource()
        {
            if ('png' == $this->getExtension())
            {
                if ('gd' == $this->useLib)
                {
                     $this->loadGdImage();
                }
                if ('imagick' == $this->useLib)
                {
                     $this->loadImagickImage();
                }

            }
        }

        /**
         * Return the width of the loaded image resource in pixels.
         *
         * @return int|null Width in pixels, or null if no image is loaded.
         */
        public function getWidth()
        {
            /* if (! is_null($this->width))
             {
                 return $this->width;
             } */
             if ('gd' == $this->useLib)
             {
                 return imagesx($this->image);
             }
             if ('imagick' == $this->useLib)
             {
                 return $this->image->getImageWidth();
             }
        }

        /**
         * Return the height of the loaded image resource in pixels.
         *
         * @return int|null Height in pixels, or null if no image is loaded.
         */
        public function getHeight()
        {
            /* if (! is_null($this->height))
             {
                 return $this->height;
             } */
             if ('gd' == $this->useLib)
             {
                 return imagesy($this->image);
             }
             if ('imagick' == $this->useLib)
             {
                 return $this->image->getImageHeight();
             }
        }

        /**
         * Convert the loaded PNG image to JPEG and write the result to $replacementPath.
         *
         * Dispatches to the GD or Imagick implementation depending on $useLib.
         *
         * @return bool True on success, false on failure.
         */
        public function convertPNG()
        {

            if ('gd' == $this->useLib)
            {
                return $this->convertGD();
            }
            if ('imagick' == $this->useLib)
            {
                return $this->convertImagick();
            }

        }

        /**
         * Determine whether the loaded image contains any transparent pixels.
         *
         * @param array $args Associative array with 'width' (int) and 'height' (int) keys.
         * @return bool True if transparency is detected, false otherwise.
         */
        public function isTransparent($args)
        {
            $width = $args['width'];
            $height = $args['height'];
            $isTransparent = false;

            if ('gd' == $this->useLib)
            {
                for ($i = 0; $i < $width; $i++) {
                    for ($j = 0; $j < $height; $j++) {
                            $rgba = imagecolorat($this->image, $i, $j);
                            if (($rgba & 0x7F000000) >> 24) {
                                    $isTransparent = true;
                                    break;
                            }
                    }
                }

            }

            if ('imagick' == $this->useLib)
            {
               $isTransparent = $this->image->getImageAlphaChannel();
            }

            return $isTransparent;
        }

        /**
         * Return the absolute path where the converted output file will be written.
         *
         * @return string
         */
        public function getReplacementPath()
        {
             return $this->replacementPath;
        }


        /**
         * Load the source PNG via the Imagick extension into $this->image.
         *
         * @return void
         */
        protected function loadImagickImage()
        {
             // Create a new Imagick object
            try {
                $this->image = new \Imagick($this->getFullPath());
            } catch (\ImagickException $e) {
                Log::addWarn("Imagick error: " . $e->getMessage());
            } catch (\Exception $e) {
                Log::addWarn("Imagick error: " . $e->getMessage());
            }

        }

        /**
         * Load the source PNG via the GD extension into $this->image.
         *
         * @return void
         */
        protected function loadGDImage()
        {

            $image = @imagecreatefrompng($this->getFullPath());

            if (false !== $image)
            {
                $this->image = $image;
            }
        }

        /**
         * Convert and write the image using Imagick, re-encoding to PNG format at $replacementPath.
         *
         * @return bool True on success, false on failure.
         */
        protected function convertImagick()
        {
            $image = $this->image;

            // Set the image format to PNG
            $image->setImageFormat('png');

            // Save the image as PNG
            $bool = $image->writeImage($this->replacementPath);

            return $bool;
        }

        /**
         * Convert and write the image using GD, compositing on a white background and
         * saving as JPEG at quality 90 to $replacementPath.
         *
         * @return bool True on success, false on failure.
         */
        protected function convertGD()
        {

            $width = $this->getWidth();
            $height = $this->getHeight();

            $bg = imagecreatetruecolor($width, $height);

            if (false === $bg)
            {
                Log::addError('ImageCreateTrueColor failed');
                $this->error['message'] = __('Creating an TrueColor Image failed - Possible library error', 'shortpixel-image-optimiser');
                $this->error['error_code'] = -10;
            }

            imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
		imagealphablending($bg, 1);
		imagecopy($bg, $this->image, 0, 0, 0, 0, $width, $height);

            $bool = imagejpeg($bg, $this->replacementPath, 90);

            return $bool;

        }


        /**
         * Release the in-memory image resource to free memory after conversion is complete.
         *
         * @return void
         */
        protected function finish()
        {
             if ('gd' == $this->useLib)
             {
                 $this->image = null;
             }
             if ('imagick' == $this->useLib)
             {
                 $this->image->clear();
                 $this->image = null;
             }
        }




}
