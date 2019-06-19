<?php
namespace ShortPixel;
use ShortPixel\Notices\NoticeController as Notice;

class NextGen
{
  protected $shortPixel;

  /** @todo Temporary constructor. In future, shortpixel should not have to be passed all the time */
  public function __construct($shortPixel)
  {
    $this->shortPixel = $shortPixel;
  }

  /** Enables nextGen, add galleries to custom folders
  * @param boolean $silent Throw a notice or not. This seems to be based if nextgen was already activated previously or not.
  */
  public function nextGenEnabled($silent)
  {
    \WpShortPixelDb::checkCustomTables(); // check if custom tables are created, if not, create them
//    $prevNextGen = $this->_settings->includeNextGen;
    $this->addNextGenGalleriesToCustom($silent);
//    $folderMsg = $ret["message"];
//    $customFolders = $ret["customFolders"];
  }

  /** Adds nextGen galleries to custom table
  * Note - this function does *Not* check if nextgen is enabled, not if checks custom Tables. Use nextgenEnabled for this.
  * Enabled checks are not an external class issue, so must be done before calling.
  */
  public function addNextGenGalleriesToCustom($silent = true) {
    //  $customFolders = array();
      $folderMsg = "";

      //add the NextGen galleries to custom folders
      $ngGalleries = \ShortPixelNextGenAdapter::getGalleries();
      $meta = $this->shortPixel->getSpMetaDao();
      foreach($ngGalleries as $gallery) {
          $msg = $meta->newFolderFromPath($gallery, get_home_path(), \WPShortPixel::getCustomFolderBase());
          if($msg) { //try again with ABSPATH as maybe WP is in a subdir
              $msg = $meta->newFolderFromPath($gallery, ABSPATH, \WPShortPixel::getCustomFolderBase());
          }
          $folderMsg .= $msg;
          //$this->_settings->hasCustomFolders = time();
      }

      if (count($ngGalleries) > 0)
      {
        // put timestamp to this setting.
        $settings = new \WPShortPixelSettings();
        $settings->hasCustomFolders = time();

      }
    //  $customFolders = $this->spMetaDao->getFolders();
      if (! $silent)
      {
          Notice::addNormal($folderMsg);
      }

    //  return array("message" => $silent? "" : $folderMsg, "customFolders" => $customFolders);
  }
}
