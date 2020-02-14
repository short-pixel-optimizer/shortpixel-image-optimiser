<?php
namespace ShortPixel;
use ShortPixel\Notices\NoticeController as Notice;

class NextGen
{
  protected static $instance;
  protected $view;

// ngg_created_new_gallery

  public function __construct()
  {
    add_filter('shortpixel/init/optimize_on_screens', array($this, 'add_screen_loads'));
    $this->view = new nextGenView();


    add_action('plugins_loaded', array($this, 'hooks'));
  }

  public function hooks()
  {
    if ($this->optimizeNextGen()) // if optimization is on, hook.
    {
      add_action('ngg_update_addgallery_page', array( &$this, 'addNextGenGalleriesToCustom'));
      add_action('ngg_added_new_image', array($this,'handleImageUpload'));
    }

  }

  // Use GetInstance, don't use the construct.
  public static function getInstance()
  {
    if (is_null(self::$instance))
      self::$instance = new NextGen();

     return self::$instance;
  }

    public function has_nextgen()
  {
     if (defined('NGG_PLUGIN'))
      return true;
     else
       return false;
  }

  public function optimizeNextGen()
  {
     if (\wpSPIO()->settings()->includeNextGen == 1)
       return true;
    else
      return false;
  }


  public function add_screen_loads($use_screens)
  {

    $use_screens[] = 'toplevel_page_nextgen-gallery'; // toplevel
    $use_screens[] = 'gallery_page_ngg_addgallery';  // add gallery
    $use_screens[] = 'nggallery-manage-gallery'; // manage gallery
    $use_screens[] = 'gallery_page_nggallery-manage-album'; // manage album

    return $use_screens;
  }
  /** Enables nextGen, add galleries to custom folders
  * @param boolean $silent Throw a notice or not. This seems to be based if nextgen was already activated previously or not.
  */
  public function nextGenEnabled($silent)
  {
    \WpShortPixelDb::checkCustomTables(); // check if custom tables are created, if not, create them

    $this->addNextGenGalleriesToCustom($silent);

  }

  /* @return DirectoryModel */
  public function getGalleries()
  {
    global $wpdb;
    $fs = \wpSPIO()->filesystem();
    $homepath = $fs->getWPFileBase();
    $result = $wpdb->get_results("SELECT path FROM {$wpdb->prefix}ngg_gallery");

    $galleries = array();

    foreach($result as $row)
    {
      $directory = $fs->getDirectory($homepath->getPath() . $row->path);
      if ($directory->exists())
        $galleries[] = $directory;
    }

    return $galleries;
  }

  /** Adds nextGen galleries to custom table
  * Note - this function does *Not* check if nextgen is enabled, not if checks custom Tables. Use nextgenEnabled for this.
  * Enabled checks are not an external class issue, so must be done before calling.
  */
  public function addNextGenGalleriesToCustom($silent = true) {
      $shortPixel = \wpSPIO()->getShortPixel();
      $fs = \wpSPIO()->filesystem();
      $homepath = $fs->getWPFileBase();
      $folderMsg = "";
      //add the NextGen galleries to custom folders
      $ngGalleries = $this->getGalleries();


      $meta = $shortPixel->getSpMetaDao();
      foreach($ngGalleries as $gallery) {
          $msg = $meta->newFolderFromPath($gallery, $homepath->getPath(), \WPShortPixel::getCustomFolderBase());
        //  if($msg) { //try again with ABSPATH as maybe WP is in a subdir
          //    $msg = $meta->newFolderFromPath($gallery, ABSPATH, \WPShortPixel::getCustomFolderBase());
        //  }
          if ($msg)
            $folderMsg .= $msg . '(' . $gallery .  ') <br>';
          //$this->_settings->hasCustomFolders = time();
      }

      if (count($ngGalleries) > 0)
      {
        // put timestamp to this setting.
        $settings = \wpSPIO()->settings();
        $settings->hasCustomFolders = time();

      }
      if (! $silent && (strlen(trim($folderMsg)) > 0 && $folderMsg !== false))
      {
          Notice::addNormal($folderMsg);
      }

  }

  public function handleImageUpload($image)
  {
    $shortPixel = \wpSPIO()->getShortPixel();
    $metadao = $shortPixel->getSpMetaDao();

      if (\wpSPIO()->settings()->includeNextGen == 1) {
          $imageFsPath = $this->getImageAbspath($image);
          $customFolders = $metadao->getFolders();

          $folderId = -1;
          foreach ($customFolders as $folder) {
              if (strpos($imageFsPath, $folder->getPath()) === 0) {
                  $folderId = $folder->getId();
                  break;
              }
          }
          if ($folderId == -1) { //if not found, create
              $galleryPath = dirname($imageFsPath);
              $folder = new \ShortPixelFolder(array("path" => $galleryPath), $this->_settings->excludePatterns);
              $folderMsg = $metadao->saveFolder($folder);
              $folderId = $folder->getId();
              //self::log("NG Image Upload: created folder from path $galleryPath : Folder info: " .  json_encode($folder));
          }

          return $shortPixel->addPathToCustomFolder($imageFsPath, $folderId, $image->pid);
      }
  }

  public function updateImageSize($nggId, $path) {

      $mapper = \C_Image_Mapper::get_instance();
      $image = $mapper->find($nggId);

      $dimensions = getimagesize($this->getImageAbspath($image));
      $size_meta = array('width' => $dimensions[0], 'height' => $dimensions[1]);
      $image->meta_data = array_merge($image->meta_data, $size_meta);
      $image->meta_data['full'] = $size_meta;
      $mapper->save($image);
  }

  public function getImageAbspath($image) {
      $storage = \C_Gallery_Storage::get_instance();
      return $storage->get_image_abspath($image);
  }

} // class .

class nextGenView
{
  protected $nggColumnIndex = 0;

  public function __construct()
  {
    $this->hooks();
  }

   protected function hooks()
   {
     add_filter( 'ngg_manage_images_columns', array( $this, 'nggColumns' ) );
     add_filter( 'ngg_manage_images_number_of_columns', array( $this, 'nggCountColumns' ) );
     add_filter( 'ngg_manage_images_column_7_header', array( $this, 'nggColumnHeader' ) );
     add_filter( 'ngg_manage_images_column_7_content', array( $this, 'nggColumnContent' ) );
   }

   // @todo move NGG specific function to own integration
   public function nggColumns( $defaults ) {
       $this->nggColumnIndex = count($defaults) + 1;
       add_filter( 'ngg_manage_images_column_' . $this->nggColumnIndex . '_header', array( &$this, 'nggColumnHeader' ) );
       add_filter( 'ngg_manage_images_column_' . $this->nggColumnIndex . '_content', array( &$this, 'nggColumnContent' ), 10, 2 );
       $defaults['wp-shortPixelNgg'] = 'ShortPixel Compression';
       return $defaults;
   }

   public function nggCountColumns( $count ) {
       return $count + 1;
   }

   public function nggColumnHeader( $default ) {
       return __('ShortPixel Compression','shortpixel-image-optimiser');
   }

   public function nggColumnContent( $unknown, $picture ) {
       $shortPixel = \wpSPIO()->getShortPixel();
       $metadao = $shortPixel->getSpMetaDao();
       $view = new \ShortPixelView($shortPixel);

       $meta = $metadao->getMetaForPath($picture->imagePath);
       if($meta) {
           switch($meta->getStatus()) {
               case "0": echo("<div id='sp-msg-C-{$meta->getId()}' class='column-wp-shortPixel' style='color: #000'>Waiting</div>"); break;
               case "1": echo("<div id='sp-msg-C-{$meta->getId()}' class='column-wp-shortPixel' style='color: #000'>Pending</div>"); break;
               case "2": $view->renderCustomColumn("C-" . $meta->getId(), array(
                   'showActions' => false && current_user_can( 'manage_options' ),
                   'status' => 'imgOptimized',
                   'type' => \ShortPixelAPI::getCompressionTypeName($meta->getCompressionType()),
                   'percent' => $meta->getImprovementPercent(),
                   'bonus' => $meta->getImprovementPercent() < 5,
                   'thumbsOpt' => 0,
                   'thumbsOptList' => array(),
                   'thumbsTotal' => 0,
                   'retinasOpt' => 0,
                   'backup' => true,
                   'excludeSizes' => \wpSPIO()->settings()->excludeSizes,
                   'thumbsToOptimize' => array(),
                   'invType' => array(),

               ));
               break;
           }
       } else {
           $view->renderCustomColumn($meta ? "C-" . $meta->getId() : "N-" . $picture->pid, array(
                   'showActions' => false && current_user_can( 'manage_options' ),
                   'status' => 'optimizeNow',
                   'thumbsOpt' => 0,
                   'thumbsOptList' => array(),
                   'thumbsTotal' => 0,
                   'retinasOpt' => 0,
                   'message' => "Not optimized"
               ));
       }
//        return var_dump($meta);
   }

} // class


$ng = NextGen::getInstance();
