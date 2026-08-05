<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


/**
 * Multisite variant of {@see SettingsModel} — persists to network-level
 * options instead of per-site options.
 *
 * Adds one network-only field to the parent's schema
 * (`disable_site_settings_page`) and overrides load() / save() to hit
 * `get_site_option` / `update_site_option` against the `spio_wpmu` row.
 *
 * Owns its own singleton pool (independent of the per-site SettingsModel
 * singleton) so both models can coexist during a network-admin request.
 *
 * @package ShortPixel\Model
 */
class MultiSettingsModel extends \ShortPixel\Model\SettingsModel
{

  /**
   * Singleton instance of this class, distinct from SettingsModel::$instance.
   * @var MultiSettingsModel|null
   */
  private static $instance;

  /** @var string WordPress network-option name used for persistence. */
  protected $option_name = 'spio_wpmu';


  /**
   * Constructor.
   *
   * Extends the inherited $model schema with the network-only field
   * `disable_site_settings_page` before delegating to the parent, which
   * calls load() and thus reads `spio_wpmu` from the site options row.
   */
  public function __construct()
  {
      // Ensure the parent model fields are available and add network-only options.
      $this->model = array_merge($this->model, [
          'disable_site_settings_page' => ['s' => 'boolean', 'default' => false],
      ]);

      parent::__construct();
  }

  /**
   * Return the network-settings singleton, instantiating on first access.
   *
   * Uses `new static()` so subclasses can inherit the pattern without
   * overriding this method.
   *
   * @return static
   */
  public static function getInstance()
  {
      if (is_null(self::$instance))
      {
          self::$instance = new static();
      }
      return self::$instance;
  }

  /**
   * Read the persisted network-settings row into memory.
   *
   * Overrides the parent to use `get_site_option` (multisite-aware) and
   * defends against a stored non-array value by falling back to an empty
   * array. Registers a PHP shutdown hook so any in-memory changes are
   * flushed to the DB at request end.
   *
   * NOTE: does NOT add the WordPress `shutdown` action fallback that the
   * per-site SettingsModel installs — the PHP-level hook alone is relied
   * upon here.
   *
   * @return void
   */
  protected function load()
  {
     $this->settings = get_site_option($this->option_name, array());
     if (! is_array($this->settings)) {
         $this->settings = array();
     }

     if (false === function_exists('register_shutdown_function'))
     {
        Log::addError('Register shutdown function not found!');
     }
     else
     {
        register_shutdown_function([$this, 'onShutdown']);
     }
  }

  /**
   * Persist the in-memory settings to `spio_wpmu` via `update_site_option`
   * and clear the parent's `$updated` dirty flag so the shutdown handler
   * does not double-save.
   *
   * @return void
   */
  protected function save()
  {
       update_site_option($this->option_name, $this->settings);
       $this->updated = false;
  }

} // class
