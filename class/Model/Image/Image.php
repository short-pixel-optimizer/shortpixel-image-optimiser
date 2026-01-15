<?php 
namespace ShortPixel\Model\Image;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


/** Class for loading Image into memory as resource. Used for conversion like PNG . 
 * 
 * @package ShortPixel\Model\Image
 */
 
Class Image extends \ShortPixel\Model\File\FileModel
{

        protected $image; // The image resource
        protected $useLib = 'gd'; 
        protected $replacementPath; 
        /*protected $width;
        protected $height; */

        protected $error = []; 


        public function __construct($path, $replacementPath)
        {
             parent::__construct($path); 

             $this->replacementPath = $replacementPath;
             $this->checkLibrary(); 
        }

        protected function checkLibrary()
        {
            $env = \wpSPIO()->env(); 
            if ($env->is_imagick_installed)
            {
                $this->useLib = 'imagick'; 
            }
            elseif ($env->is_gd_installed)
            {
                $this->useLib = 'gd'; 
            }

            Log::addTemp('Replace PNG Library used - ' . $this->useLib);
             
        }

        public function checkImageLoaded()
        {
            if (! is_null($this->image) && false !== $this->image)
            {
                return true; 
            }

            return false; 
        }

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

        public function getReplacementPath()
        {
             return $this->replacementPath;
        }


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

        protected function loadGDImage()
        {
          
            $image = @imagecreatefrompng($this->getFullPath());

            if (false !== $image)
            {
                $this->image = $image; 
            }
        }

        protected function convertImagick()
        {
            $image = $this->image; 

            // Set the image format to PNG
            $image->setImageFormat('png');

            // Save the image as PNG
            $bool = $image->writeImage($this->replacementPath);

            return $bool; 
        }

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
