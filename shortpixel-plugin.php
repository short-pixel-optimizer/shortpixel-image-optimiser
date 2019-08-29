<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;


/** Plugin class
* This class is meant for: WP Hooks, init of runtime and Controller Routing.

*/
class ShortPixelPlugin
{
  static private $instance;
  private $paths = array('class', 'class/controller', 'class/external'); // classes that are autoloaded

  protected $is_noheaders = false;

  protected $plugin_path;
  protected $plugin_url;

  public function __construct()
  {
      $this->plugin_path = plugin_dir_path(SHORTPIXEL_PLUGIN_FILE);
      $this->plugin_url = plugin_dir_url(SHORTPIXEL_PLUGIN_FILE);

      $this->initRuntime();
      $this->initHooks();

      if(isset($_REQUEST['noheader'])) {
          $this->is_noheaders = true;
      }
  }

  /** Create instance. This should not be needed to call anywhere else than main plugin file **/
  public static function getInstance()
  {
    if (is_null(self::$instance))
    {
      self::$instance = new shortPixelPlugin();
    }
    return self::$instance;
  }

  /** Init Runtime. Loads all classes. */
  protected function initRuntime()
  {
      $plugin_path = plugin_dir_path(SHORTPIXEL_PLUGIN_FILE);
      foreach($this->paths as $short_path)
      {
        $directory_path = realpath($plugin_path . $short_path);

        if ($directory_path !== false)
        {
          $it = new \DirectoryIterator($directory_path);
          foreach($it as $file)
          {
            $file_path = $file->getRealPath();
            if ($file->isFile() && pathinfo($file_path, PATHINFO_EXTENSION) == 'php')
            {
              require_once($file_path);
            }
          }
        }
      }

      // Loads all subclassed controllers. This is used for slug-based discovery of which controller to run
      $controllerClass = \ShortPixelTools::namespaceit('ShortPixelController');
      $controllerClass::init();
  }

  /** Hooks for all WordPress related hooks
  */
  public function initHooks()
  {
      add_action('admin_menu', array($this,'admin_pages'));
      add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
      add_action('admin_notices', array($this, 'admin_notices')); // notices occured before page load
      add_action('admin_footer', array($this, 'admin_notices'));  // called in views.

  }

  /** Hook in our admin pages */
  public function admin_pages()
  {
      // settings page
      add_options_page( __('ShortPixel Settings','shortpixel-image-optimiser'), 'ShortPixel', 'manage_options', 'wp-shortpixel-settings', array($this, 'route'));
  }

  /** PluginRunTime. Items that should be initialized *only* when doing our pages and territory. */
  protected function initPluginRunTime()
  {

  }

  /** All scripts should be registed, not enqueued here (unless global wp-admin is needed )
  *
  * Not all those registered must be enqueued however.
  */
  public function admin_scripts()
  {
    // FileTree in Settings
    wp_register_style('sp-file-tree', plugins_url('/res/css/sp-file-tree.min.css',SHORTPIXEL_PLUGIN_FILE),array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );
    wp_register_script('sp-file-tree', plugins_url('/res/js/sp-file-tree.min.js',SHORTPIXEL_PLUGIN_FILE) );

    wp_register_style('shortpixel-admin', plugins_url('/res/css/shortpixel-admin.css', SHORTPIXEL_PLUGIN_FILE),array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

    wp_register_style('shortpixel', plugins_url('/res/css/short-pixel.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);
    //modal - used in settings for selecting folder
    wp_register_style('shortpixel-modal', plugins_url('/res/css/short-pixel-modal.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

  }

  public function admin_notices()
  {
      $noticeControl = Notices::getInstance();
      $noticeControl->loadIcons(array(
          'normal' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/robo-cool.png', SHORTPIXEL_PLUGIN_FILE) . '">',
          'success' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/robo-cool.png', SHORTPIXEL_PLUGIN_FILE) . '">',
          'warning' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/robo-scared.png', SHORTPIXEL_PLUGIN_FILE) . '">',
          'error' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/robo-scared.png', SHORTPIXEL_PLUGIN_FILE) . '">',
      ));

      if ($noticeControl->countNotices() > 0)
      {
          wp_enqueue_style('shortpixel-admin'); // queue on places when it's not our runtime.
          foreach($noticeControl->getNotices() as $notice)
          {
            echo $notice->getForDisplay();
          }
      }
      $noticeControl->update(); // puts views, and updates
  }

  /** Load Style via Route, on demand */
  public function load_style($name)
  {
    if ($this->is_noheaders)  // fail silently, if this is a no-headers request.
      return;

    if (wp_style_is($name, 'registered'))
    {
      wp_enqueue_style($name);
    }
    else {
      Log::addWarn("Style $name was asked for, but not registered");
    }
  }

  /** Load Style via Route, on demand */
  public function load_script($name)
  {
    if ($this->is_noheaders)  // fail silently, if this is a no-headers request.
      return;

    if (wp_script_is($name, 'registered'))
    {
      wp_enqueue_script($name);
    }
    else {
      Log::addWarn("Script $name was asked for, but not registered");
    }
  }

  /** Route, based on the page slug
  *
  * Principially all page controller should be routed from here.
  */
  public function route()
  {
      global $plugin_page;
      global $shortPixelPluginInstance; //brrr @todo Find better solution for this some day.

      $this->initPluginRunTime();

      $default_action = 'load'; // generic action on controller.
      $action = isset($_REQUEST['sp-action']) ? sanitize_text_field($_REQUEST['sp-action']) : $default_action;
      Log::addDebug('Request', $_REQUEST);
      $controller = false;

      switch($plugin_page)
      {
          case 'wp-shortpixel-settings':
            $this->load_style('shortpixel-admin');
            $this->load_style('shortpixel');
            $this->load_style('shortpixel-modal');
            $this->load_style('sp-file-tree');
            $this->load_script('sp-file-tree');
            $controller = \shortPixelTools::namespaceit("SettingsController");
            $url = menu_page_url($plugin_page, false);
          break;
      }

      if ($controller !== false)
      {
        $c = new $controller();
        $c->setShortPixel($shortPixelPluginInstance);
        $c->setControllerURL($url);
        if (method_exists($c, $action))
          $c->$action();
        else {
          Log::addWarn("Attempted Action $action on $controller does not exist!");
          $c->$default_action();
        }

      }
  }

} // class plugin
