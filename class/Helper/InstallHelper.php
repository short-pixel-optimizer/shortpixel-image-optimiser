<?php
namespace ShortPixel\Helper;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\OptimizeController as OptimizeController;


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
          \WpShortPixel::alterHtaccess(); //add the htaccess lines
      }

      \WpShortPixelDb::checkCustomTables();

      Controller\AdminNoticesController::resetAllNotices();

    /*  Controller\AdminNoticesController::resetCompatNotice();
      Controller\AdminNoticesController::resetAPINotices();
      Controller\AdminNoticesController::resetQuotaNotices();
      Controller\AdminNoticesController::resetIntegrationNotices();
*/
      \WPShortPixelSettings::onActivate();

      OptimizeController::activatePlugin();

  }

  public static function deactivatePlugin()
  {

    \wpSPIO()->settings()::onDeactivate();

    $env = wpSPIO()->env();

    if (! $env->is_nginx)
      \WpShortPixel::alterHtaccess(true);

    // save remove.
    $fs = new Controller\FileSystemController();
    $log = $fs->getFile(SHORTPIXEL_BACKUP_FOLDER . "/shortpixel_log");
    if ($log->exists())
      $log->delete();


  }

  public static function uninstallPlugin()
  {
    $settings = \wpSPIO()->settings();
    $env = \wpSPIO()->env();

    if($settings->removeSettingsOnDeletePlugin == 1) {
        $settings::debugResetOptions();
        if (! $env->is_nginx)
          insert_with_markers( get_home_path() . '.htaccess', 'ShortPixelWebp', '');

        $spMetaDao = new \ShortPixelCustomMetaDao(new \WpShortPixelDb());
        $spMetaDao->dropTables();
    }

    OptimizeController::uninstallPlugin();
  }

}
