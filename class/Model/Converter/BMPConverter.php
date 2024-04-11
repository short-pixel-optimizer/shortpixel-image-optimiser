<?php
namespace ShortPixel\Model\Converter;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Helper\UtilHelper as UtilHelper;

class BMPConverter extends MediaLibraryConverter
{

  const CONVERTABLE_EXTENSIONS = array( 'bmp');


  public function isConvertable()
  {
    $extension = $this->imageModel->getExtension();

    // If extension is in list of allowed Api Converts.
    if (in_array($extension, static::CONVERTABLE_EXTENSIONS) && $extension !== 'png')
    {
       return true;
    }
    return false;
  }

  public function prepareQueue($args = array())
  {
    $conversion_args = array(
        'replacementPath' => $replacementPath,
        'backup_thumbnails' => false, // no need for this. either they should be optimized, or generated after the run
    );

    $prepared = $this->imageModel->conversionPrepare($conversion_args);
    if (false === $prepared)
    {
       return false;
    }

  }

  public function handleConvertedFilter($mediaObj)
  {
    $this->setupReplacer();
    $fs = \wpSPIO()->filesystem();

    $extension = $this->imageModel->getExtension();
    $replacementBase = $this->imageModel->getMeta()->convertMeta()->getReplacementImageBase();
    if (false === $replacementBase)
    {
      $replacementPath = $this->getReplacementPath();
      $replacementFile = $fs->getFile($replacementPath);
    }
    else {
      $replacementPath = $replacementBase . '.jpg';
      $replacementFile = $fs->getFile($this->imageModel->getFileDir() . $replacementPath);
    }

    if (isset($optimizeData['files']) && isset($optimizeData['data']))
    {
       $files = $optimizeData['files'];
       $data = $optimizeData['data'];
    }
    else {
      Log::addError('Something went wrong with handleOptimized', $optimizeData);
      return $optimizeData;
    }

    $mainImageKey = $this->imageModel->get('mainImageKey');
    $mainFile = (isset($files) && isset($files[$mainImageKey])) ? $files[$mainImageKey] : false;

    if (false === $mainFile)
    {
      // Error, but can also be multiple other thumbs returning.
       Log::addDebug('MainFile not set (so far?) ');
       return $successData;
    }

    if (! isset($mainFile['image']) || ! isset($mainFile['image']['file']))
    {
      Log::addDebug('MainFile not set (so far?) ');
      return $successData;
    }

    $res = $this->imageModel->conversionSuccess(['skip_thumbnails' => true]);

    return $succesData;


  } // handleConverterFilter


}
