<?php
namespace ShortPixel\Controller\View;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;
use ShortPixel\Model\MultiSettingsModel as MultiSettingsModel;
use ShortPixel\Notices\NoticeController as Notice;

/**
 * View controller for the WordPress Multisite network settings screen.
 *
 * Extends SettingsViewController to reuse the settings-save pipeline and the
 * regular `view-settings` template (rendered with $is_network_page = true, which
 * adds the Network Control tab AND a hidden `is_network_admin` input read by
 * shortpixel-settings.js for the AJAX routing gate), but binds to the
 * MultiSettingsModel (network-wide settings stored in the network options
 * table).
 *
 * Wired up by AdminController on the `network_admin_menu` hook when the site
 * is part of a multisite network.
 *
 * TEMPLATE NOTE (e4d1d0a8, 2026-08-28): view-network-settings.php was removed
 * and the shared view-settings.php is used instead — the network-specific
 * bits (Network Control tab, hidden routing input) are conditional on
 * $this->is_network_page = true.
 *
 * ROUTING NOTE: AjaxController::settingsFormSubmit() selects this controller
 * when the client posts is_network_admin=true. See the bug #41 pins in
 * tests/Multisite/test-MultisiteNetworkSave.php for the security limits of
 * that mechanism.
 *
 * @package ShortPixel\Controller\View
 */
class MultiSiteViewController extends SettingsViewController
{

      /** @var string Template for the network settings page. */
      protected $template = 'view-settings';
      /** @var string Nonce action name for the network settings form. */
      protected $form_action = 'save-multi-settings';
      /*
       * Since e4d1d0a8 (2026-08-28) $all_display_parts is INHERITED from
       * SettingsViewController (overview/optimisation/exclusions/processing/
       * webp/ai/integrations/debug/tools/help) and only the 'network' entry
       * is appended in the constructor. The previous redeclaration lived
       * here and only exposed a 5-entry list, which broke deep-linked tabs
       * that the parent set knows about. Keeping the old array commented
       * out as a reminder — remove after the release ships.
       */
     // protected $all_display_parts = array('network', 'optimisation', 'processing', 'webp', 'ai');

      /** @var bool Marks this controller as the WPMU network settings page for shared templates. */
      protected $is_network_page = true;

      /**
       * Instantiates the MultiSettingsModel and chains to the parent constructor.
       *
       * Appends 'network' to the inherited $all_display_parts so the Network
       * Control tab is a recognised deep-link target (see the class docblock
       * for the inheritance-vs-redeclare rationale).
       *
       * Overrides $this->model with a MultiSettingsModel instance so that the
       * inherited processSave() and load_settings() logic operates on network-wide
       * settings rather than per-site settings.
       */
      public function __construct()
      {
        // Only add network to the all parts of settings
         $this->all_display_parts[] = 'network';

         parent::__construct();
         $this->model = new MultiSettingsModel();
         $this->view->network_settings_enabled = false;

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
       * Reads the environment details and uses the network settings tabs by default.
       *
       * @return void
       */
      protected function loadEnv()
      {
          parent::loadEnv();

          if (isset($_GET['part']) && in_array(sanitize_text_field(wp_unslash($_GET['part'])), $this->all_display_parts, true))
          {
              $this->display_part = sanitize_text_field(wp_unslash($_GET['part']));
          }
          else
          {
              $this->display_part = 'network';
          }
      }

      /**
       * Persist posted network settings and keep the UI state in sync.
       *
       * @return void
       */
      protected function processSave()
      {
          $this->processPostData($_POST);

          foreach ($this->postData as $name => $value)
          {
              $this->model->{$name} = $value;
          }

          $data = $this->model->getData();
          foreach ($data as $name => $value)
          {
              $type = $this->model->getType($name);
              if ('boolean' === $type && ! isset($this->postData[$name]))
              {
                  $this->model->{$name} = false;
              }
          }

          $noticeController = Notice::getInstance();
          $notice = Notice::addSuccess(__('Network settings saved.', 'shortpixel-image-optimiser'));
          $notice->is_removable = false;
          $noticeController->update();

          $this->view->network_settings_enabled = (bool) $this->model->network_settings_override_enabled;

          $url = network_admin_url('settings.php?page=shortpixel-network-settings&part=' . rawurlencode($this->display_part));
          $redirect = 'self';

          $this->handleAjaxSave($redirect, $url);

          exit;
      }

      /**
       * Prepares the POST payload so the network form values map to the model.
       *
       * @param array $post Raw POST data.
       * @return void
       */
      protected function processPostData($post)
      {
          if (isset($post['display_part']) && strlen($post['display_part']) > 0)
          {
              $this->display_part = sanitize_text_field($post['display_part']);
          }

          $ignore_fields = array(
              'display_part',
              'save',
              'sp-nonce',
              '_wp_http_referer',
              'action',
              'nonce',
              'save-bulk',
              'validate',
              'ajaxSave',
              'request_url',
          );

          foreach ($ignore_fields as $ignore)
          {
              if (isset($post[$ignore]))
              {
                  unset($post[$ignore]);
              }
          }

          parent::processPostData($post);
          $this->postData = $this->model->getSanitizedData($post, false);
      }

      /**
       * Populates $this->view with network settings data and renders the template.
       *
       * Sets view->data from MultiSettingsModel::getData(), stubs the API key
       * display properties (the network page does not manage keys), loads the
       * dashboard summary and the shared settings view data, then renders the
       * `view-settings` template via loadView().
       *
       * @return void
       */
      protected function load_network_settings()
      {
          $this->view->data = (object) $this->model->getData();
          $this->view->network_settings_enabled = (bool) $this->model->network_settings_override_enabled;
         
          $this->view->key = (object) [
              'is_verifiedkey' => true,
              'is_constant_key' => false,
              'hide_api_key' => true,
              'apiKey' => '',
              'is_editable' => false,
              'can_validate' => false,
          ];
          $this->loadDashBoardInfo();
          $this->load_settings();
          $this->loadView();
      }

      /**
       * Loads the shared settings view data, but never enters quick-tour mode
       * (the tour is a per-site onboarding flow, not a network one).
       *
       * @return void
       */
      protected function load_settings()
      {
          parent::load_settings();

          if ('page-quick-tour' === $this->view_mode)
          {
             $this->view_mode = get_user_option('shortpixel-settings-mode');
          }
      }

      /**
       * Builds links that stay inside the network settings admin page.
       *
       * @param array $args Link arguments.
       * @return string HTML anchor.
       */
      protected function settingLink($args)
      {
          $defaults = [
              'part' => '',
              'title' => __('Title', 'shortpixel-image-optimiser'),
              'icon' => false,
              'icon_position' => 'left',
              'class' => 'anchor-link',
          ];

          $args = wp_parse_args($args, $defaults);

          $link = esc_url(network_admin_url('settings.php?page=shortpixel-network-settings&part=' . $args['part']));
          $active = ($this->display_part == $args['part']) ? ' active ' : '';
          $title = $args['title'];
          $class = $active . $args['class'];

          if (false !== $args['icon'])
          {
              $icon = '<i class="' . esc_attr($args['icon']) . '"></i>';
              $title = ('left' === $args['icon_position']) ? $icon . $title : $title . $icon;
          }

          return sprintf('<a href="%s" class="%s" data-menu-link="%s" %s >%s</a>', $link, esc_attr($class), esc_attr($args['part']), $active, $title);
      }

      /**
       * Sets the controller URL and rewrites plain admin URLs to network-admin URLs.
       *
       * Overrides the ViewController base implementation to inject `/network/`
       * into `/wp-admin/` paths so that the AJAX save redirect targets the
       * network settings screen (the route in shortpixel-plugin.php passes a
       * plain admin URL because the same route serves both single-site and
       * multisite dispatch).
       *
       * Called by AjaxController::settingsFormSubmit() with the client-posted
       * `$_POST['request_url']` when this controller has been selected via the
       * `is_network_admin` routing flag.
       *
       * @param string $url The URL captured from the request; typically an /wp-admin/ URL.
       * @return void
       */
      public function setControllerURL($url)
      {
          // Fix for missing /network/ in admin page URL's from route () in  SPIO main plugin file.
          $url = str_replace( '/wp-admin/', '/wp-admin/network/', $url );
          $this->url = $url;
      }

} // Class
