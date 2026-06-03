<?php
namespace ShortPixel\Controller\View;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;
use ShortPixel\Model\MultiSettingsModel as MultiSettingsModel;

class MultiSiteViewController extends SettingsViewController
{

      protected $template = 'view-network-settings'; // template name to include when loading.
      protected $form_action = 'save-multi-settings';

      public function __construct()
      {
         parent::__construct();
         $this->model = new MultiSettingsModel();
      }

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

      protected function load_network_settings()
      {
          $this->view->data = (object) $this->model->getData();
          $this->loadAPiKeyData();
          $this->loadDashBoardInfo();
          $this->loadView();
      }

}
