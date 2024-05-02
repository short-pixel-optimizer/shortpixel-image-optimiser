<?php
namespace ShortPixel\Controller\View;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;



class MultiSiteViewController extends \ShortPixel\ViewController
{

      protected $template = 'view-network-settings'; // template name to include when loading.
      protected $form_action = 'save-settings';


      public function load()
      {
          $this->view->settings = $this->loadSettings();

          $this->loadView();
      }

      protected function loadSettings()
      {
        $settings = array();

        //  $site_delivery =
        $delivery_defaults =
          array(
             'delivery_enable' => false,

             'deliver_method' => 'htaccess',
             'picture_method' => 'hooks',
          );

        $delivery = get_site_option('spio_site_delivery');

        $delivery = wp_parse_args($delivery_defaults, $delivery);

        $settings['delivery'] = $delivery;

        return $settings;

      }


}
