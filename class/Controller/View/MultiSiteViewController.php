<?php
namespace ShortPixel\Controller\View;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;
use ShortPixel\Model\MultiSettingsModel as MultiSettingsModel;

/**
 * View controller for the WordPress Multisite network settings screen.
 *
 * Extends SettingsViewController to reuse the settings-save pipeline, but
 * renders the `view-network-settings` template instead and binds to the
 * MultiSettingsModel (network-wide settings stored in the network options table).
 *
 * Wired up by AdminController on the `network_admin_menu` hook when the site
 * is part of a multisite network.
 *
 * @package ShortPixel\Controller\View
 */
class MultiSiteViewController extends SettingsViewController
{

      /** @var string Template for the network settings page. */
      protected $template = 'view-network-settings';
      /** @var string Nonce action name for the network settings form. */
      protected $form_action = 'save-multi-settings';

      /**
       * Instantiates the MultiSettingsModel and chains to the parent constructor.
       *
       * Overrides $this->model with a MultiSettingsModel instance so that the
       * inherited processSave() and load_settings() logic operates on network-wide
       * settings rather than per-site settings.
       */
      public function __construct()
      {
         parent::__construct();
         $this->model = new MultiSettingsModel();
      }

      /**
       * Default action: loads environment state, processes POST, and renders network settings.
       *
       * Mirrors the parent load() flow but delegates template rendering to
       * load_network_settings() instead of load_settings().
       *
       * @return void
       */
      public function load()
      {
          $this->loadEnv();
          $this->checkPost();

          if ($this->is_form_submit)
          {
              $this->processSave();
          }

          $this->load_network_settings();
      }

      /**
       * Populates $this->view with network settings data and renders the template.
       *
       * Sets view->data from MultiSettingsModel::getData(), loads API key display
       * properties, and loads the dashboard summary, then includes the
       * `view-network-settings` template via loadView().
       *
       * @return void
       */
      protected function load_network_settings()
      {
          $this->view->data = (object) $this->model->getData();
          $this->loadAPiKeyData();
          $this->loadDashBoardInfo();
          $this->loadView();
      }

}
