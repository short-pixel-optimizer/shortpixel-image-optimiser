<?php
namespace ShortPixel\Helper;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\Controller\BulkController as BulkController;
use ShortPixel\Controller\FileSystemController as FileSystemController;
use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;


class InstallHelper
{
  public static function activatePlugin()
  {
      self::deactivatePlugin();
      $settings = \wpSPIO()->settings();

      if(SHORTPIXEL_RESET_ON_ACTIVATE === true && WP_DEBUG === true) { //force reset plugin counters, only on specific occasions and on test environments
          $settings::debugResetOptions();
        //  $settings = new \WPShortPixelSettings();
          $spMetaDao = new \ShortPixelCustomMetaDao(new \WpShortPixelDb(), $settings->excludePatterns);
          $spMetaDao->dropTables();
      }

      $env = wpSPIO()->env();

      if(\WPShortPixelSettings::getOpt('deliverWebp') == 3 && ! $env->is_nginx) {
          \ShortPixelTools::alterHtaccess(true,true); //add the htaccess lines
      }

      \WpShortPixelDb::checkCustomTables();

      AdminNoticesController::resetAllNotices();
      \WPShortPixelSettings::onActivate();
      OptimizeController::activatePlugin();
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
    if ($log->exists())
      $log->delete();

    global $wpdb;
    $sql = "delete from wp_options where option_name like '%_transient_shortpixel%'";
    $wpdb->query($sql); // remove transients.
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

        $spMetaDao = new \ShortPixelCustomMetaDao(new \WpShortPixelDb());
        $spMetaDao->dropTables();
    }

    OptimizeController::uninstallPlugin();
    BulkController::uninstallPlugin();
  }

}
