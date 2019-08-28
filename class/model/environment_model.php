<?php
namespace ShortPixel;

/** Loads a few environment variables handy to have nearby
*
* Notice - This is meant to be loaded *often*, so it shouldn't do any heavy lifting without caching the results. 
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

  public function __construct()
  {
      $this->is_nginx = strpos(strtolower($_SERVER["SERVER_SOFTWARE"]), 'nginx') !== false ? true : false;
      $this->is_apache = strpos(strtolower($_SERVER["SERVER_SOFTWARE"]), 'apache') !== false ? true : false;
      $this->is_gd_installed = function_exists('imagecreatefrompng');
      $this->is_curl_installed = function_exists('curl_init');

      $this->is_multisite = (function_exists("is_multisite") && is_multisite()) ? true : false;
      $this->is_mainsite = is_main_site();

      $this->has_nextgen = \ShortPixelNextGenAdapter::hasNextGen();

  }
}
