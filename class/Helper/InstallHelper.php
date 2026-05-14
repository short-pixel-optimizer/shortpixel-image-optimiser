<?php

namespace ShortPixel\Helper;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\QueueController as QueueController;
use ShortPixel\Controller\CronController as CronController;
use ShortPixel\Controller\BulkController as BulkController;
use ShortPixel\Controller\FileSystemController as FileSystemController;
use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\StatsController as StatsController;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Notices\NoticeController as Notices;
use ShortPixel\Helper\UtilHelper as UtilHelper;

/**
 * Handles plugin lifecycle operations: activation, deactivation, uninstallation,
 * and database schema management.
 *
 * All methods are static and are typically invoked from WordPress register_activation_hook(),
 * register_deactivation_hook(), and similar lifecycle callbacks.
 *
 * @package ShortPixel\Helper
 */
class InstallHelper
{

	/**
	 * Runs all setup steps needed when the plugin is activated.
	 *
	 * Calls deactivatePlugin() first to ensure a clean state, then conditionally
	 * writes WebP/AVIF rewrite rules to .htaccess, creates or updates database tables,
	 * resets stale admin notices, fires the settings onActivate hook, installs the
	 * media queue table, and flushes the object cache.
	 *
	 * @return void
	 */
	public static function activatePlugin()
	{
		self::deactivatePlugin();

		$env = wpSPIO()->env();
		$settings = \wpSPIO()->settings();

		if ($settings->deliverWebp == 3 && ! $env->is_nginx) {
			UtilHelper::alterHtaccess(true, true); //add the htaccess lines. Both are true because even if one option is now off in the past both fileformats could have been generated.
		}

		self::checkTables();

		AdminNoticesController::resetOldNotices();

		$queueController = new QueueController();
		$q = $queueController->getQueue('media');
		$q->getShortQ()->install(); // create table.


		$settings->onActivate();
		$settings->currentVersion = SHORTPIXEL_IMAGE_OPTIMISER_VERSION;

		wp_cache_flush();
	}

	/**
	 * Cleans up runtime state when the plugin is deactivated.
	 *
	 * Fires the settings onDeactivate hook, removes .htaccess rewrite rules on
	 * Apache servers, deletes the plugin log file, removes all ShortPixel transients
	 * from the database, resets statistics, and stops scheduled cron events.
	 *
	 * @return void
	 */
	public static function deactivatePlugin()
	{

		$settings = \wpSPIO()->settings();
		$settings->onDeactivate(); 

		$env = wpSPIO()->env();

		if (! $env->is_nginx) {
			UtilHelper::alterHtaccess(false, false);
		}

		// save remove.
		$fs = new FileSystemController();
		$log = $fs->getFile(SHORTPIXEL_BACKUP_FOLDER . "/shortpixel_log");

		if ($log->exists())
			$log->delete();

		// Known Transients in system, deleting this way for caches etc : 
		$transients = [
			'spio_settings_ai_example_id',  // settings - example ai 
			'bulk-secret',  // cachecontroller in ajaxcontroller
			'average_compression',  // average compression in system 
			'quotaData', // the cached quota data.
		];

		foreach($transients as $transient)
		{
				 delete_transient($transient); 
		}

		/** We still have to do a hard database delete because the plugin has dynamically names transients we can't predict the name of  */
		global $wpdb;
		$sql = "delete from " . $wpdb->options . " where option_name like '%_transient_shortpixel%' or option_name like '%_transient_timeout_shortpixel%'";
		$wpdb->query($sql); // remove transients.

		// saved in settings object, reset all stats.
		StatsController::getInstance()->reset();
		CronController::getInstance()->onDeactivate();
	}

	/**
	 * Removes persistent plugin data during an uninstall.
	 *
	 * Delegates queue and API key removal to their respective controllers, then
	 * deletes known transients stored by the plugin.
	 *
	 * @return void
	 */
	public static function uninstallPlugin()
	{
		QueueController::uninstallPlugin();
		ApiKeyController::uninstallPlugin();

		delete_transient('bulk-secret');
		delete_transient('othermedia_refresh_folder_delay');
		delete_transient('avif_server_check');
		delete_transient('quotaData');
	}

	/**
	 * Performs a complete removal of all plugin data (hard uninstall).
	 *
	 * Verifies the 'remove-all' nonce, then runs deactivatePlugin(),
	 * uninstallPlugin(), BulkController cleanup, option deletion, .htaccess
	 * cleanup, custom database table drops, backup folder deletion, and finally
	 * deactivates the plugin itself. Not recommended for normal use.
	 *
	 * @return void
	 */
	// Removes everything  of SPIO 5.x .  Not recommended.
	public static function hardUninstall()
	{
		$env = \wpSPIO()->env();
		$settings = \wpSPIO()->settings();

		$nonce = (isset($_POST['tools-nonce'])) ? sanitize_key($_POST['tools-nonce']) : null;
		if (! wp_verify_nonce($nonce, 'remove-all')) {
			wp_nonce_ays('');
		}

		self::deactivatePlugin(); // deactivate
		self::uninstallPlugin(); // uninstall

		// Bulk Log
		BulkController::uninstallPlugin();

		$settings->deleteAll();


		if (! $env->is_nginx) {
			insert_with_markers(get_home_path() . '.htaccess', 'ShortPixelWebp', '');
		}

		self::removeTables();

		// Remove Backups
		$dir = \wpSPIO()->filesystem()->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
		$dir->recursiveDelete();

		$plugin = basename(SHORTPIXEL_PLUGIN_DIR) . '/' . basename(SHORTPIXEL_PLUGIN_FILE);
		deactivate_plugins($plugin);
	}


	/**
	 * Deactivates a conflicting third-party plugin via a nonce-verified GET request.
	 *
	 * Reads the target plugin slug from $_GET['plugin'], calls deactivate_plugins(),
	 * then redirects back to the referring page.
	 *
	 * @return void Terminates execution via wp_safe_redirect() and die().
	 */
	public static function deactivateConflictingPlugin()
	{
		if (! isset($_GET['_wpnonce']) || ! wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'sp_deactivate_plugin_nonce')) {
			wp_nonce_ays('Nononce');
		}

		$referrer_url = wp_get_referer();
		$url = wp_get_referer();
		$plugin = (isset($_GET['plugin'])) ? sanitize_text_field(wp_unslash($_GET['plugin'])) : null; // our target.

		if (! is_null($plugin))
			deactivate_plugins($plugin);

		wp_safe_redirect($url);
		die();
	}

	/**
	 * Check if TableName exists
	 *
	 * @param string $tableName The Name of the Table without Prefix.
	 * @return bool True if the table exists, false otherwise.
	 */
	public static function checkTableExists($tableName)
	{
		global $wpdb;
		$tableName = $wpdb->prefix . $tableName;
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


	/**
	 * Creates or upgrades all custom database tables used by the plugin.
	 *
	 * Runs dbDelta() for each table definition and then ensures required indexes exist.
	 *
	 * @return void
	 */
	public static function checkTables()
	{
		global $wpdb;
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		dbDelta(self::getFolderTableSQL());
		dbDelta(self::getMetaTableSQL());
		dbDelta(self::getPostMetaSQL());
		dbDelta(self::getAIPostSQL());

		self::checkIndexes();
	}

	/**
	 * Verifies that all required database indexes exist and creates any that are missing.
	 *
	 * Iterates over a predefined map of table names and their expected indexes,
	 * issuing CREATE INDEX statements for any that are absent.
	 *
	 * @return void
	 */
	private static function checkIndexes()
	{
		global $wpdb;

		$definitions = array(
			'shortpixel_meta' => array(
				'path' => 'path'
			),
			'shortpixel_folders' => array(
				'path' => 'path'
			),
			'shortpixel_postmeta' => array(
				'attach_id' => 'attach_id',
				'parent' => 'parent',
				'size' => 'size',
				'status' => 'status',
				'compression_type' => 'compression_type'
			), 
			'shortpixel_aipostmeta' => [
				'attach_id' => 'attach_id', 
			],
		);

		foreach ($definitions as $raw_tableName => $indexes) {
			$tableName = $wpdb->prefix . $raw_tableName;
			foreach ($indexes as $indexName => $fieldName) {
				// Check exists
				$sql = 'SHOW INDEX FROM ' . $tableName . ' WHERE Key_name = %s';
				$sql = $wpdb->prepare($sql, $indexName);

				$res = $wpdb->get_row($sql);

				if (is_null($res)) {
					// can't prepare for those, also not any user data here.
					$sql = 'CREATE INDEX ' . $indexName . ' ON ' . $tableName . ' ( ' . $fieldName . ')';
					$res = $wpdb->query($sql);
				}
			}
		}
	}

	/**
	 * Drops all custom plugin database tables if they exist.
	 *
	 * Removes shortpixel_folders, shortpixel_meta, shortpixel_postmeta, and
	 * shortpixel_aipostmeta tables. Used during hard uninstall.
	 *
	 * @return void
	 */
	private static function removeTables()
	{
		global $wpdb;
		if (self::checkTableExists('shortpixel_folders') === true) {
			$sql = 'DROP TABLE  ' . $wpdb->prefix . 'shortpixel_folders';
			$wpdb->query($sql);
		}
		if (self::checkTableExists('shortpixel_meta') === true) {
			$sql = 'DROP TABLE  ' . $wpdb->prefix . 'shortpixel_meta';
			$wpdb->query($sql);
		}
		if (self::checkTableExists('shortpixel_postmeta') === true) {
			$sql = 'DROP TABLE  ' . $wpdb->prefix . 'shortpixel_postmeta';
			$wpdb->query($sql);
		}
		if (self::checkTableExists('shortpixel_aipostmeta') === true) {
			$sql = 'DROP TABLE  ' . $wpdb->prefix . 'shortpixel_aipostmeta';
			$wpdb->query($sql);
		}
	}

	/**
	 * Returns the CREATE TABLE SQL for the shortpixel_folders table.
	 *
	 * Stores custom folder entries that the plugin monitors for optimizable images.
	 *
	 * @return string SQL statement string suitable for dbDelta().
	 */
	private static function getFolderTableSQL()
	{
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
          parent SMALLINT DEFAULT 0,
          ts_checked timestamp,
          ts_updated timestamp,
          ts_created timestamp,
          PRIMARY KEY id (id)
        ) $charsetCollate;";
	}

	/**
	 * Returns the CREATE TABLE SQL for the shortpixel_meta table.
	 *
	 * Stores optimization metadata for custom media (non-Media Library) images.
	 *
	 * @return string SQL statement string suitable for dbDelta().
	 */
	private static function getMetaTableSQL()
	{
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
				extra_info LONGTEXT,
          PRIMARY KEY sp_id (id)
        ) $charsetCollate;";
	}

	/**
	 * Returns the CREATE TABLE SQL for the shortpixel_postmeta table.
	 *
	 * Stores per-size optimization metadata for WordPress Media Library attachments.
	 *
	 * @return string SQL statement string suitable for dbDelta().
	 */
	private static function getPostMetaSQL()
	{
		global $wpdb;
		$charsetCollate = $wpdb->get_charset_collate();
		$prefix = $wpdb->prefix;

		$sql = "CREATE TABLE {$prefix}shortpixel_postmeta (
			 id bigint unsigned NOT NULL AUTO_INCREMENT,
			 attach_id bigint unsigned NOT NULL,
			 parent bigint unsigned NOT NULL,
			 image_type tinyint default 0,
			 size varchar(200),
			 status tinyint default 0,
			 compression_type tinyint,
			 compressed_size  int,
			 original_size int,
			 tsAdded timestamp,
			 tsOptimized  timestamp,
			 extra_info LONGTEXT,
			 PRIMARY KEY id (id)
		 ) $charsetCollate;";

		return $sql;
	}

	/**
	 * Returns the CREATE TABLE SQL for the shortpixel_aipostmeta table.
	 *
	 * Stores AI-generated SEO metadata (alt text, captions, descriptions, etc.)
	 * associated with Media Library attachments.
	 *
	 * @return string SQL statement string suitable for dbDelta().
	 */
	private static function getAIPostSQL()
	{
		global $wpdb;
		$charsetCollate = $wpdb->get_charset_collate();
		$prefix = $wpdb->prefix;

		$sql = "CREATE TABLE {$prefix}shortpixel_aipostmeta (
				id bigint unsigned not null AUTO_INCREMENT,
				post_type tinyint default 1,
				attach_id bigint unsigned NOT NULL,
				original_data text,
				generated_data text,
				old_filename varchar(300),
				new_filename varchar(300),
				status int,
				tsUpdated timestamp,
				PRIMARY KEY id (id)
		) $charsetCollate";

		return $sql;
	}
} // InstallHelper
