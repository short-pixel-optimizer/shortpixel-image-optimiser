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

  public function filterQueue($item, $args = array())
  {
    // Create backup and such.
    $conversion_args = array(
        'backup_thumbnails' => false, // no need for this. either they should be optimized, or generated after the run
    );

    if (false === $args['debug_active'])
    {
        $this->imageModel->conversionPrepare($conversion_args);
    }
    return $item;
  }

  public function handleConvertedFilter($optimizeData)
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

    $tempFile = $fs->getFile($mainFile['image']['file']);
    $res = $tempFile->copy($replacementFile);


    $this->setTarget($replacementFile);
    $this->updateMetaData([
        'generate_metadata' => false,
        'success' => true
    ]);

    $result = $this->replacer->replace();

    $res = $this->imageModel->conversionSuccess(['skip_thumbnails' => true, 'omit_backup' => true]);


    return $optimizeData;


  } // handleConverterFilter


  public function getCheckSum()
  {
     return 1; // done or not.
  }

  public function convert($args = [])
  {

  }

  public function restore()
  {
    $params = array(
      'restore' => true,
    );
    $fs = \wpSPIO()->filesystem();

Log::addTemp("BmPConverter REstore");
    $this->setupReplacer();

    $oldFileName = $this->imageModel->getFileName(); // Old File Name, Still .jpg
    $newFileName =  $this->imageModel->getFileBase() . '.bmp';

    if ($this->imageModel->isScaled())
    {
       $oldFileName = $this->imageModel->getOriginalFile()->getFileName();
       $newFileName = $this->imageModel->getOriginalFile()->getFileBase() . '.bmp';
    }

    $fsNewFile = $fs->getFile($this->imageModel->getFileDir() . $newFileName);

    $this->newFile = $fsNewFile;
    $this->setTarget($fsNewFile);

    $this->updateMetaData($params);
    $result = $this->replacer->replace();

    $fs->flushImageCache();


  }
}
