<?php
namespace ShortPixel;

/** Loads a few environment variables handy to have nearby
*
* Notice - This is meant to be loaded via the plugin class. Easy access with wpSPIO()->getEnv().
*/
class EnvironmentModel extends ShortPixelModel
{
    // Server and PHP
    public $is_nginx;
    public $is_apache;
    public $is_gd_installed;
    public $is_curl_installed;

    // MultiSite
    public $is_multisite;
    public $is_mainsite;

    // Integrations
    public $has_nextgen;

    // WordPress
    public $is_front = false;
    public $is_admin = false;
    public $is_ajaxcall = false;

    public $is_screen_to_use = false; // where shortpixel loads
    public $is_our_screen = false;


    protected static $instance;


  public function __construct()
  {
     $this->setServer();
     $this->setWordPress();
     $this->setIntegrations();

     add_action('current_screen', array($this, 'setScreen'));
  }

  public static function getInstance()
  {
    if (is_null(self::$instance))
        self::$instance = new EnvironmentModel;

    return self::$instance;
  }

  private function setServer()
  {
    $this->is_nginx = strpos(strtolower($_SERVER["SERVER_SOFTWARE"]), 'nginx') !== false ? true : false;
    $this->is_apache = strpos(strtolower($_SERVER["SERVER_SOFTWARE"]), 'apache') !== false ? true : false;
    $this->is_gd_installed = function_exists('imagecreatefrompng');
    $this->is_curl_installed = function_exists('curl_init');

  }

  private function setWordPress()
  {
    $this->is_multisite = (function_exists("is_multisite") && is_multisite()) ? true : false;
    $this->is_mainsite = is_main_site();

    if ( is_admin() )
      $this->is_admin = true;
    else
      $this->is_front = true;

    if (defined('DOING_AJAX') && DOING_AJAX)
    {
      $this->is_ajaxcall = true;
    }

  }

  public function setScreen()
  {
    $screen = get_current_screen();

    // WordPress pages where we'll be active on.
    if(in_array($screen->id, array('upload', 'edit', 'edit-tags', 'post-new', 'post'))) {
          $this->is_screen_to_use = true;
    }

    // Our pages.
    $pages = \wpSPIO()->get_admin_pages();
    if (in_array($screen->id, $pages))
    {
       $this->is_screen_to_use = true;
       $this->is_our_screen = true;
    }

  }

  private function setIntegrations()
  {
    $this->has_nextgen = \ShortPixelNextGenAdapter::hasNextGen();

  }
}
