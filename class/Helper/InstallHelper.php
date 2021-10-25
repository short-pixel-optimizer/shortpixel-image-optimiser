<?php
namespace ShortPixel\Helper;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\Controller\BulkController as BulkController;
use ShortPixel\Controller\FileSystemController as FileSystemController;
use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\StatsController as StatsController;


class InstallHelper
{


  public static function activatePlugin()
  {
      self::deactivatePlugin();
      $settings = \wpSPIO()->settings();

      // @todo This will not work in new version
      if(SHORTPIXEL_RESET_ON_ACTIVATE === true && WP_DEBUG === true) { //force reset plugin counters, only on specific occasions and on test environments
          $settings::debugResetOptions();
					self::removeTables();
      }

      $env = wpSPIO()->env();

      if(\WPShortPixelSettings::getOpt('deliverWebp') == 3 && ! $env->is_nginx) {
          \ShortPixelTools::alterHtaccess(true,true); //add the htaccess lines
      }

      self::checkTables();

      AdminNoticesController::resetAllNotices();
      \WPShortPixelSettings::onActivate();
      OptimizeController::resetQueues();
  }

  public static function deactivatePlugin()
  {
    \wpSPIO()->settings()::onDeactivate();

    $env = wpSPIO()->env();

    if (! $env->is_nginx)
      \ShortPixelTools::alterHtaccess(false, false);

    // save remove.
    $fs = new FileSystemController();
    $log = $fs->getFile(SHORTPIXEL_BACKUP_FOLDER . "/shortpixel_log");
		// @todo Debug, put this back
    //if ($log->exists())
    //  $log->delete();

    global $wpdb;
    $sql = "delete from " . $wpdb->options . " where option_name like '%_transient_shortpixel%'";
    $wpdb->query($sql); // remove transients.

		// saved in settings object, reset all stats.
 		StatsController::getInstance()->reset();

  }

  public static function uninstallPlugin()
  {
    $settings = \wpSPIO()->settings();
    $env = \wpSPIO()->env();
  BulkController::uninstallPlugin();
    if($settings->removeSettingsOnDeletePlugin == 1) {
        $settings::debugResetOptions();
        if (! $env->is_nginx)
          insert_with_markers( get_home_path() . '.htaccess', 'ShortPixelWebp', '');

        self::removeTables();
    }

    OptimizeController::uninstallPlugin();
    BulkController::uninstallPlugin();
  }

  public static function deactivateConflictingPlugin()
  {
    if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'sp_deactivate_plugin_nonce' ) ) {
          wp_nonce_ays( '' );
    }

    $referrer_url = wp_get_referer();
    $conflict = \ShortPixelTools::getConflictingPlugins();
    $url = wp_get_referer();

    foreach($conflict as $c => $value) {
        $conflictingString = $value['page'];
        if($conflictingString != null && strpos($referrer_url, $conflictingString) !== false){
            $url = get_dashboard_url();
            deactivate_plugins( sanitize_text_field($_GET['plugin']) );
            break;
        }
    }

    wp_safe_redirect($url);
    die();


  }

	public static function checkTableExists($tableName)
	{
		      global $wpdb;
		      $sql = $wpdb->prepare("
		               SHOW TABLES LIKE %s
		               ", $tableName);

		       $result = intval($wpdb->query($sql));

		       if ($result == 0)
		         return false;
		       else {
		         return true;
		       }
	}


	public static function checkTables()
	{
			global $wpdb;
    	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			if (self::checkTableExists($wpdb->prefix . 'shortpixel_folders') === false)
	    {
					dbDelta(self::getFolderTableSQL());
			}
			if (self::checkTableExists($wpdb->prefix . 'shortpixel_meta') === false)
			{
	 	    	dbDelta(self::getMetaTableSQL());
			}
	}

	private static function removeTables()
	{
		 global $wpdb;
			if (self::checkTableExists($wpdb->prefix . 'shortpixel_folders') === false)
	    {
					$sql = 'DROP TABLE  ' . $wpdb->prefix . 'shortpixel_folders';
					$wpdb->query($sql);
			}
			if (self::checkTableExists($wpdb->prefix . 'shortpixel_meta') === false)
			{
	 	    	$sql = 'DROP TABLE  ' . $wpdb->prefix . 'shortpixel_meta';
					$wpdb->query($sql);
			}
	}

  public static function getFolderTableSQL() {
		 global $wpdb;
		 $charsetCollate = $wpdb->get_charset_collate();
		 $prefix = $wpdb->prefix;

     return "CREATE TABLE {$prefix}shortpixel_folders (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          path varchar(512),
          name varchar(150),
          path_md5 char(32),
          file_count int,
          status SMALLINT NOT NULL DEFAULT 0,
          ts_updated timestamp,
          ts_created timestamp,
          PRIMARY KEY id (id)
        ) $charsetCollate;";

  }

  public static function getMetaTableSQL() {
		 	global $wpdb;
		 	$charsetCollate = $wpdb->get_charset_collate();
			$prefix = $wpdb->prefix;

     return "CREATE TABLE {$prefix}shortpixel_meta (
          id mediumint(10) NOT NULL AUTO_INCREMENT,
          folder_id mediumint(9) NOT NULL,
          ext_meta_id int(10),
          path varchar(512),
          name varchar(150),
          path_md5 char(32),
          compressed_size int(10) NOT NULL DEFAULT 0,
          compression_type tinyint,
          keep_exif tinyint DEFAULT 0,
          cmyk2rgb tinyint DEFAULT 0,
          resize tinyint,
          resize_width smallint,
          resize_height smallint,
          backup tinyint DEFAULT 0,
          status SMALLINT NOT NULL DEFAULT 0,
          retries tinyint NOT NULL DEFAULT 0,
          message varchar(255),
          ts_added timestamp,
          ts_optimized timestamp,
          PRIMARY KEY sp_id (id)
        ) $charsetCollate;";

  }


} // InstallHelper
