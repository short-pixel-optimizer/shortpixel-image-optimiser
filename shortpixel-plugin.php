<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;
use ShortPixel\Controller\QueueController as QueueController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\AjaxController as AjaxController;
use ShortPixel\Controller\AdminController as AdminController;
use ShortPixel\Controller\ImageEditorController as ImageEditorController;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\FileSystemController;
use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Controller\OtherMediaController as OtherMediaController;
use ShortPixel\NextGenController as NextGenController;

use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
use ShortPixel\Controller\View\OtherMediaViewController;
use ShortPixel\Helper\InstallHelper as InstallHelper;
use ShortPixel\Helper\UiHelper as UiHelper;

use ShortPixel\Model\AccessModel as AccessModel;
use ShortPixel\Model\EnvironmentModel;
use ShortPixel\Model\SettingsModel as SettingsModel;

/**
 * Singleton plugin bootstrapper — the one class that turns a fresh WordPress
 * request into a running SPIO install.
 *
 * Responsibilities, in the order they fire:
 *
 *   1. `__construct()` — schedule `lowInit` at `plugins_loaded` priority 5
 *      (as early as reasonable without racing WP core).
 *   2. `lowInit()` — capture plugin paths, honour the `shortpixel/plugin/init`
 *      opt-out filter, boot the Front + Admin + AdminNotices controllers,
 *      wire the WP-CLI controller when applicable, register ajax hooks, and
 *      schedule `init`, `initHooks`, `admin_init`.
 *   3. `init()` — start the cron controller and (for admin users only) hook
 *      the deactivate-conflict handler + the feedback popup.
 *   4. `initHooks()` — the big hook-registration block: ~25 hooks that
 *      route thumbnail regenerate signals, Media Library actions, EMR,
 *      cron-based processing, autoprocess uploads, AI upload hooks,
 *      restore/backup cleanup, image editor filters, and admin toolbar.
 *   5. `admin_init()` — plugin-version check (triggers `activatePlugin`
 *      when the stored version differs), notice controller hookup, quota
 *      fetch.
 *
 * The class also owns:
 *
 *   - **Accessors** for the shared SettingsModel / EnvironmentModel /
 *     FileSystemController singletons (`settings()`, `env()`, `fileSystem()`).
 *   - **Admin menu registration** (`admin_pages`, `admin_network_pages`).
 *   - **Asset registration + on-demand enqueue** (`admin_scripts`,
 *     `admin_styles`, `load_script`, `load_style`, `load_admin_scripts`).
 *   - **Page router** (`route`) — dispatches admin page loads to the
 *     appropriate `Controller\View\*` class based on `$plugin_page` /
 *     `$screen_id`.
 *   - **Path helpers** (`plugin_url`, `plugin_path`) and the admin-page
 *     hook list (`get_admin_pages`).
 *
 * The class is a singleton; call `getInstance()` from anywhere and use
 * `wpSPIO()` (defined in `wp-shortpixel.php`) as the global shorthand.
 * Never instantiate this directly.
 *
 * @package ShortPixel
 */
class ShortPixelPlugin {


	/** @var ShortPixelPlugin|null Singleton instance held by getInstance(). */
	private static $instance;

	/** @var array Legacy require-once guard for model files; currently unused (kept for BC). */
	protected static $modelsLoaded = array(); // don't require twice, limit amount of require looksups..

	/** @var bool True when the request carries the `noheader` query flag; suppresses on-demand style/script enqueue. */
	protected $is_noheaders = false;

	/** @var string Absolute filesystem path to the plugin directory (with trailing slash). Populated in lowInit(). */
	protected $plugin_path;

	/** @var string Public URL to the plugin directory (with trailing slash). Populated in lowInit(). */
	protected $plugin_url;

	/** @var mixed Reserved / unused — historical "shortpixel megaclass" slot; never assigned. */
	protected $shortPixel; // shortpixel megaclass

	/** @var array<int, string> WP admin-page hook suffixes returned by add_options_page / add_media_page. */
	protected $admin_pages = [];  // admin page hooks.

	/**
	 * Register the `lowInit` bootstrap at `plugins_loaded` priority 5.
	 *
	 * Nothing else runs here — real init is deferred so third-party plugins
	 * (and WP core) can finish loading before we start wiring hooks. Callers
	 * that need to prevent SPIO from booting should hook
	 * `shortpixel/plugin/init` at `plugins_loaded` priority < 5 and return
	 * false; see `lowInit()`.
	 */
	public function __construct() {
		// $this->initHooks();
		add_action( 'plugins_loaded', [$this, 'lowInit'], 5 ); // early as possible init.

	}

	/**
	 * Bootstrap the plugin at `plugins_loaded` priority 5.
	 *
	 * Runs before WP core is fully available (some functions may still be
	 * missing) so this method is deliberately minimal — its job is to
	 * capture paths, honour the opt-out filter, boot the always-on
	 * controllers, and schedule the real init work for later hooks.
	 *
	 * Sequence:
	 *
	 *   1. Populate `$plugin_path` / `$plugin_url` from
	 *      `SHORTPIXEL_PLUGIN_FILE`.
	 *   2. Detect the `noheader` request flag (asset loaders bail when set).
	 *   3. Apply `shortpixel/plugin/init` — third-parties returning false
	 *      here short-circuit the whole plugin. Hook at priority < 5.
	 *   4. Instantiate `FrontController` (front-end asset delivery hooks),
	 *      `AdminController` (singleton — most admin behaviour lives here),
	 *      and `AdminNoticesController` (admin_notices dispatcher).
	 *   5. Wire ajax hooks now (they don't need `init`).
	 *   6. Boot the WP-CLI controller when `WP_CLI` is defined.
	 *   7. Schedule `init`, `initHooks`, `admin_init` for the WP action hooks
	 *      of the same name.
	 *
	 * @return void
	 */
	public function lowInit() {

		$this->plugin_path = plugin_dir_path( SHORTPIXEL_PLUGIN_FILE );
		$this->plugin_url  = plugin_dir_url( SHORTPIXEL_PLUGIN_FILE );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
		if ( isset( $_REQUEST['noheader'] ) ) {
			$this->is_noheaders = true;
		}

		/*
		Filter to prevent SPIO from starting. This can be used by third-parties to prevent init when needed for a particular situation.
		* Hook into plugins_loaded with priority lower than 5 */
		$init = apply_filters( 'shortpixel/plugin/init', true );

		if (false === $init ) {
			return;
		}


		$front        = new Controller\FrontController(); // init front checkers
		$admin        = Controller\AdminController::getInstance();
		$adminNotices = Controller\AdminNoticesController::getInstance(); // Hook in the admin notices.

//		$this->initHooks();
		$this->ajaxHooks();

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			WPCliController::getInstance();
		}

		add_action ('init', [$this, 'init']);
		add_action('init', [$this, 'initHooks']);
		add_action( 'admin_init', [ $this, 'admin_init' ] );
	}

	/**
	 * WordPress `init` hook — start the cron controller and (for admin users
	 * only) hook the deactivate-conflict handler + the plugin feedback popup.
	 *
	 * The cron controller MUST be booted here so its wp-cron schedules are
	 * registered before WP fires `wp_loaded`. The admin-user block is a
	 * capability gate: only users the `AccessModel` recognises as "admin"
	 * see the conflict-deactivator link and the feedback prompt.
	 *
	 * The feedback-popup gate loads only for admin users whose key is
	 * unverified OR who have fewer than 4000 credits — the "true ||"
	 * debug shortcut that was making it load unconditionally was
	 * removed in 399b29e2.
	 *
	 * @return void
	 */
	public function init() : void
	{
		Controller\CronController::getInstance();  // cron jobs - must be init to function!

		$access = AccessModel::getInstance();

		$isAdminUser = $access->userIsAllowed('is_admin_user');

		if ( $isAdminUser ) {
			// toolbar notifications

			// deactivate conflicting plugins if found
			add_action( 'admin_post_shortpixel_deactivate_conflict_plugin', array( '\ShortPixel\Helper\InstallHelper', 'deactivateConflictingPlugin' ) );

			// only if the key is not yet valid or the user hasn't bought any credits.
			// @todo This should not be done here.
			$settings     = $this->settings();
			$stats        = $settings->currentStats;
			$totalCredits = isset( $stats['APICallsQuota'] ) ? $stats['APICallsQuota'] + $stats['APICallsQuotaOneTime'] : 0;
			$keyControl = ApiKeyController::getInstance();


			if (  false === $keyControl->keyIsVerified() || $totalCredits < 4000 ) {
				require_once 'class/view/shortpixel-feedback.php';
				new ShortPixelFeedback( SHORTPIXEL_PLUGIN_FILE, 'shortpixel-image-optimiser' );
			}
		}
		
	}


	/**
	 * WordPress `admin_init` hook — version check, notice hookup, quota fetch.
	 *
	 * Order matters:
	 *
	 *   1. `check_plugin_version()` — compares the constant version against
	 *      the settings-stored version; a mismatch triggers
	 *      `InstallHelper::activatePlugin()` (table upgrades, option
	 *      migrations, etc.). Runs first so the rest can trust the schema.
	 *   2. `NoticeController::getInstance()` — attaches its ajax listener.
	 *   3. `QuotaController::getInstance()->getQuota()` — refreshes cached
	 *      quota data from the API when stale.
	 *
	 * @return void
	 */
	public function admin_init() {
			// This runs activation thing. Should be -after- init
			$this->check_plugin_version();

			$notices             = Notices::getInstance(); // This hooks the ajax listener
			$quotaController = QuotaController::getInstance();
			$quotaController->getQuota();
	}

	/**
	 * Return the SettingsModel singleton — plugin configuration + persisted state.
	 *
	 * @return SettingsModel The settings model object.
	 */
	public function settings() : SettingsModel
	{
			return SettingsModel::getInstance();
	}

	/**
	 * Return the EnvironmentModel singleton — request-scoped environment
	 * detection (current screen, is_bulk_page, is_autoprocess, editor flags,
	 * PHP/WP capabilities, etc.).
	 *
	 * @return EnvironmentModel
	 */
	public function env() : EnvironmentModel
	{
		return Model\EnvironmentModel::getInstance();
	}

	/**
	 * Return a fresh FileSystemController instance — filesystem access
	 * façade the rest of the codebase uses to build FileModel / DirectoryModel
	 * objects and resolve virtual (remote) files.
	 *
	 * Unlike `settings()` and `env()`, this returns a new instance every call
	 * — the controller itself is stateless and cheap to construct.
	 *
	 * @return FileSystemController
	 */
	public function fileSystem() : FileSystemController
	{
		return new Controller\FileSystemController();
	}

	/**
	 * Return the singleton `ShortPixelPlugin` instance, constructing it on
	 * first call.
	 *
	 * This is called once from the plugin bootstrap (`wp-shortpixel.php`)
	 * to seed the singleton; everywhere else should use the `wpSPIO()`
	 * shorthand. Do NOT call this after `plugins_loaded` has fired — the
	 * lowInit sequence assumes the singleton was set up before the hook.
	 *
	 * @return ShortPixelPlugin
	 */
	public static function getInstance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new ShortPixelPlugin();
		}
		return self::$instance;

	}

	/**
	 * Register the bulk of SPIO's WordPress hooks — admin menus, assets,
	 * thumbnail-regenerate signals, Media Library actions, cron entry
	 * points, upload autoprocess, AI upload hooks, restore/backup cleanup,
	 * image editor filters, and admin toolbar.
	 *
	 * Runs on the `init` hook (scheduled by `lowInit()`). Most hooks
	 * register handlers on the shared `AdminController` singleton; a few
	 * target the `ImageEditorController`, `AjaxController`, and the
	 * `OtherMediaViewController` for screen-options.
	 *
	 * Notable gates inside this method:
	 *
	 *   - The autoprocess block (uploads-trigger-optimize) only runs when
	 *     `env()->is_autoprocess` is true AND the `shortpixel/init/automedialibrary`
	 *     filter returns true. External integrations (see `class/external/visualcomposer.php`)
	 *     use this filter to short-circuit optimization for specific contexts.
	 *   - The AI-auto block only fires when `OptimizeAiController::isAutoAiEnabled()`
	 *     is true; it registers upload hooks at priority 4 so AI runs BEFORE
	 *     the normal optimization at priority 5.
	 *   - The `network_admin_menu` hook only registers on multisite installs.
	 *
	 * @return void
	 */
	public function initHooks() {

		add_action( 'admin_menu', array( $this, 'admin_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) ); // admin scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) ); // admin styles
		add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_scripts' ), 90 ); // loader via route.
		add_action( 'enqueue_block_assets', array($this, 'load_admin_scripts'), 90);

		// defer notices a little to allow other hooks ( notable adminnotices )

		$queueController = new QueueController();
		add_action( 'shortpixel-thumbnails-regenerated', array( $queueController, 'thumbnailsChangedHookLegacy' ), 10, 4 );
		add_action( 'rta/image/thumbnails_regenerated', array( $queueController, 'thumbnailsChangedHook' ), 10, 2 );
		add_action( 'rta/image/thumbnails_removed', array( $queueController, 'thumbnailsChangedHook' ), 10, 2 );
		add_action('rta/image/scaled_image_regenerated', array($queueController, 'scaledImageChangedHook'), 10, 2);


		// Media Library - Actions to route screen
		add_action( 'load-upload.php', array( $this, 'route' ) );
		add_action( 'load-post.php', array( $this, 'route' ) );

		$admin = AdminController::getInstance();
		$imageEditor = ImageEditorController::getInstance();

		// Handle for EMR
		add_action( 'wp_handle_replace', array( $admin, 'handleReplaceHook' ) );

		// Action / hook for who wants to use CRON. Please refer to manual / support to prevent loss of credits.
		add_action( 'shortpixel/hook/processqueue', array( $admin, 'processQueueHook' ) );
		add_action( 'shortpixel/hook/scancustomfolders', array($admin, 'scanCustomFoldersHook'));

		// Action for media library gallery view
		//add_filter('attachment_fields_to_edit', array($admin, 'editAttachmentScreen'), 10, 2);
		add_action('print_media_templates', array($admin, 'printComparer'));

		// Placeholder function for heic and such, return placeholder URL in image to help w/ database replacements after conversion.
		add_filter('wp_get_attachment_url', array($admin, 'checkPlaceHolder'), 10, 2);

		add_filter('rest_post_dispatch', [$admin, 'checkRestMedia'],10, 3);

		/** When automagically process images when uploaded is on */
		if ( $this->env()->is_autoprocess ) {
			// compat filter to shortcircuit this in cases.  (see external - visualcomposer)
			if ( apply_filters( 'shortpixel/init/automedialibrary', true ) ) {

      			add_action( 'shortpixel-thumbnails-before-regenerate', array( $admin, 'preventImageHook' ), 10, 1 );

				add_action( 'enable-media-replace-upload-done', array( $admin, 'handleReplaceEnqueue' ), 10, 3 );

				add_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleImageUploadHook' ), 5, 2 );
				add_action('add_attachment', array($admin, 'addAttachmentHook'));

				// @integration MediaPress
				add_filter( 'mpp_generate_metadata', array( $admin, 'handleImageUploadHook' ), 10, 2 );
			}
		}

		$optimizeAiController = OptimizeAiController::getInstance(); 
		if (true === $optimizeAiController->isAutoAiEnabled())
		{

			// Run one hit earlier than optimization, to do this action first if needed.
			add_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleAiImageUploadHook' ), 4, 2 );
			add_filter( 'mpp_generate_metadata', array( $admin, 'handleAiImageUploadHook' ), 9, 2 );
			add_action( 'enable-media-replace-upload-done', array( $admin, 'handleAiReplaceEnqueue' ), 10, 3 );

		}


		$this->env()->setDefaultViewModeList();// set default mode as list. only @ first run

		add_filter( 'plugin_action_links_' . plugin_basename( SHORTPIXEL_PLUGIN_FILE ), array( $admin, 'generatePluginLinks' ) );// for plugin settings page

		// for cleaning up the WebP images when an attachment is deleted . Loading this early because it's possible other plugins delete files in the uploads, but we need those to remove backups.
		add_action( 'delete_attachment', array( $admin, 'onDeleteAttachment' ), 5 );
		add_action( 'mime_types', array( $admin, 'addMimes' ) );

		// integration with WP/LR Sync plugin
		//add_action( 'wplr_update_media', array( AjaxController::getInstance(), 'onWpLrUpdateMedia' ), 10, 2 );
		add_action( 'wplr_sync_media', array( AjaxController::getInstance(), 'onWpLrSyncMedia' ), 10, 2 );

		add_action( 'admin_bar_menu', array( $admin, 'toolbar_shortpixel_processing' ), 999 );

		// Image Editor Actions
		add_filter('load_image_to_edit_path', array($imageEditor, 'getImageForEditor'), 10, 3);
		add_filter('wp_save_image_editor_file', array($imageEditor, 'saveImageFile'), 10, 5);  // hook when saving
	//	add_action('update_post_meta', array($imageEditor, 'checkUpdateMeta'), 10, 4 );


		if (is_admin())
		{
			  add_filter('pre_get_posts', array($admin, 'filter_listener'));

			add_action( 'load-media_page_wp-short-pixel-custom', array( OtherMediaViewController::getInstance(), 'addOtherMediaScreenOptions' ) );
		    add_filter( 'set-screen-option', array( OtherMediaViewController::getInstance() , 'setScreenOption' ), 10, 3 );
		}

		if ($this->env()->is_multisite)
		{
			 add_action('network_admin_menu', [$this, 'admin_network_pages']) ;
		}

	}

	/**
	 * Register the plugin's authenticated ajax endpoints (`wp_ajax_*` hooks).
	 *
	 * Called from `lowInit()` — ajax hooks don't need to wait for `init`.
	 * All handlers live on the `AjaxController` singleton, are prefixed
	 * `ajax_`, and MUST verify the request nonce inside the handler (the
	 * nonces are minted in `admin_scripts()` and passed to the JS layer
	 * via `wp_localize_script`).
	 *
	 * No `wp_ajax_nopriv_*` variants are registered — SPIO ajax is
	 * admin-only. Anonymous requests get a 400 from WordPress by default.
	 *
	 * @return void
	 */
	protected function ajaxHooks() {

		// Ajax hooks. Should always be prepended with ajax_ and *must* check on nonce in function
		add_action( 'wp_ajax_shortpixel_image_processing', array( AjaxController::getInstance(), 'ajax_processQueue' ) );

		// Custom Media

		//add_action( 'wp_ajax_shortpixel_get_backup_size', array( AjaxController::getInstance(), 'ajax_getBackupFolderSize' ) );

		add_action( 'wp_ajax_shortpixel_propose_upgrade', array( AjaxController::getInstance(), 'ajax_proposeQuotaUpgrade' ) );
		add_action( 'wp_ajax_shortpixel_check_quota', array( AjaxController::getInstance(), 'ajax_checkquota' ) );


		add_action( 'wp_ajax_shortpixel_ajaxRequest', array( AjaxController::getInstance(), 'ajaxRequest' ) );
		add_action( 'wp_ajax_shortpixel_settingsRequest', array( AjaxController::getInstance(), 'settingsRequest'));

	}



	/**
	 * Register SPIO's admin submenu pages under Settings and Media.
	 *
	 * Three pages, all routed through `route()` as their callback:
	 *
	 *   - **Settings > ShortPixel** — the main settings screen. Suppressed
	 *     on multisite child sites when the network admin has ticked
	 *     `disable_site_settings_page` in the `spio_wpmu` network option.
	 *   - **Media > Custom Media** — the "Other Media" folder scanner.
	 *     Only shown when `OtherMediaController::showMenuItem()` returns
	 *     true (i.e. at least one custom folder is registered).
	 *   - **Media > Bulk ShortPixel** — the bulk-optimize workflow.
	 *     Always registered.
	 *
	 * The returned hook suffixes are collected in `$this->admin_pages` for
	 * downstream use (asset-loading decisions live in `load_admin_scripts()`).
	 *
	 * @return void
	 */
	public function admin_pages() {
		$admin_pages = array();

		$show_site_settings = true;
		if ($this->env()->is_multisite && ! is_network_admin()) {
			$network_settings = get_site_option('spio_wpmu', array());
			$disable_site_settings = isset($network_settings['disable_site_settings_page']) && $network_settings['disable_site_settings_page'];
			$network_override_enabled = isset($network_settings['network_settings_override_enabled']) && $network_settings['network_settings_override_enabled'];
			if ($disable_site_settings || $network_override_enabled) {
				$show_site_settings = false;
			}
		}

		// settings page
		if ( $show_site_settings ) {
			$admin_pages[] = add_options_page( __( 'ShortPixel Settings', 'shortpixel-image-optimiser' ), 'ShortPixel', 'manage_options', 'wp-shortpixel-settings', array( $this, 'route' ) );
		}

		$otherMediaController = OtherMediaController::getInstance();
		if ( $otherMediaController->showMenuItem() ) {
			/*translators: title and menu name for the Other media page*/
			$admin_pages[] = add_media_page( __( 'Custom Media Optimized by ShortPixel', 'shortpixel-image-optimiser' ), __( 'Custom Media', 'shortpixel-image-optimiser' ), 'edit_others_posts', 'wp-short-pixel-custom', array( $this, 'route' ) );
		}
		/*translators: title and menu name for the Bulk Processing page*/
		$admin_pages[] = add_media_page( __( 'ShortPixel Bulk Process', 'shortpixel-image-optimiser' ), __( 'Bulk ShortPixel', 'shortpixel-image-optimiser' ), 'edit_others_posts', 'wp-short-pixel-bulk', array( $this, 'route' ) );

		$this->admin_pages = array_merge($this->admin_pages, $admin_pages);
	}

	/**
	 * Register the multisite network-settings submenu.
	 *
	 * Currently a **stub** — the method's very first statement is an
	 * unconditional `return;` guarded by an `@todo`. When re-enabled, this
	 * will add a `ShortPixel` entry under network Settings that routes to
	 * `MultiSiteViewController` via `route()`.
	 *
	 * @return void
	 */
	public function admin_network_pages()
	{
		$page = add_submenu_page(
			'settings.php',
			__( 'ShortPixel Network Settings', 'shortpixel-image-optimiser' ),
			__( 'ShortPixel', 'shortpixel-image-optimiser' ),
			'manage_network_options',
			'shortpixel-network-settings',
			[ $this, 'route' ]
		);

		if ($page !== false)
		{
			// WPMU adds the -network prefix to screen_id;
			$this->admin_pages[] = $page . '-network'; 
		}
	}

	/**
	 * Register every JavaScript file the plugin might need and attach their
	 * localize-script payloads. Actual enqueue happens in
	 * `load_admin_scripts()` on a screen-by-screen basis.
	 *
	 * The full script inventory covers three concerns:
	 *
	 *   1. **UI / helper scripts** — folderbrowser, tooltip, jquery.knob,
	 *      debug, settings, onboarding, shiftselect, inline-help, chatbot.
	 *   2. **Processor scripts** — `shortpixel-processor` (the queue-driving
	 *      loop) plus the `shortpixel-screen-*` variants (base, item-base,
	 *      media, custom, bulk, nolist) that own the per-screen UI wiring.
	 *   3. **Legacy / shared scripts** — `shortpixel`, media, datepicker.
	 *
	 * Localize payloads set here:
	 *
	 *   - `spio_folderbrowser` — folder browser i18n + icons.
	 *   - `spio_tooltipStrings` — processor tooltip labels.
	 *   - `settings_strings` — settings screen i18n (via UiHelper).
	 *   - `spio_media` — media grid editor state + AI toggles.
	 *   - `ShortPixelProcessorData` — nonces, worker URL, timing filters
	 *     (`shortpixel/processor/interval`, `shortpixel/process/deferInterval`),
	 *     debug flag, autoprocess flag, kill-switch filter.
	 *   - `spio_screenStrings` — shared screen error strings.
	 *   - `spio_mediascreen_settings` — media screen AI / modal / preview.
	 *   - `shortPixelScreen` — bulk-screen strings + panel deep-link.
	 *   - `_spTr` + `ShortPixelConstants` — legacy globals for the
	 *     `shortpixel.js` (jQuery/knob) codepath.
	 *
	 * Filterable knobs:
	 *
	 *   - `shortpixel/plugin/nohelp` — override the chatbot script URL.
	 *   - `shortpixel/processor/interval` — poll interval in ms (default 3000).
	 *   - `shortpixel/process/deferInterval` — idle poll interval (default 60000).
	 *   - `shortpixel/processorjs/disable` — hard kill for the frontend processor.
	 *   - `/shortpixel/front/showConsoleLog` — surface `console.log` output.
	 *   - `shortpixel/js/media/hide_in_popups` — suppress SPIO UI in modal frames.
	 *
	 * @param string $hook_suffix WP admin hook suffix (currently unused; the
	 *                            script selection happens later in
	 *                            `load_admin_scripts()` based on
	 *                            `$plugin_page` / `$screen_id`).
	 * @return void
	 */
	public function admin_scripts( $hook_suffix ) {

		$settings       = \wpSPIO()->settings();
		$env = \wpSPIO()->env();
		$ajaxController = AjaxController::getInstance();

		$secretKey = $ajaxController->getProcessorKey();

		$keyControl = \ShortPixel\Controller\ApiKeyController::getInstance();
		$apikey     = $keyControl->getKeyForDisplay();

		$is_bulk_page = \wpSPIO()->env()->is_bulk_page;

		$queueController = new QueueController(['is_bulk' =>  $is_bulk_page ]);
		$quotaController = QuotaController::getInstance();

		$OptimizeAiController = OptimizeAiController::getInstance(); 

		$wp_script_debug = ( defined("SCRIPT_DEBUG") && true === \SCRIPT_DEBUG) ? true : false;

		$args_footer_async = ['strategy' => 'async', 'in_footer' => true];

	 wp_register_script('shortpixel-folderbrowser', plugins_url('/res/js/shortpixel-folderbrowser.js', SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

	 wp_localize_script('shortpixel-folderbrowser', 'spio_folderbrowser', array(
		 		'strings' => array(
						'loading' => __('Loading', 'shortpixel-image-optimiser'),
						'empty_result' => __('No Directories found that can be added to Custom Folders', 'shortpixel-image-optimiser'),
				),
				'icons' => array(
						'folder_closed' => plugins_url('res/img/filebrowser/folder-closed.svg', SHORTPIXEL_PLUGIN_FILE),
						'folder_open' => plugins_url('res/img/filebrowser/folder-closed.svg', SHORTPIXEL_PLUGIN_FILE),
				),
	 ));

		wp_register_script( 'jquery.knob.min.js', plugins_url( ($wp_script_debug) ? '/res/js/jquery.knob.js' : '/res/js/jquery.knob.min.js', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script( 'shortpixel-debug', plugins_url( '/res/js/debug.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'jquery-ui-draggable' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script( 'shortpixel-tooltip', plugins_url( '/res/js/shortpixel-tooltip.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		$tooltip_localize = array(
			'processing' => __('Processing... ','shortpixel-image-optimiser'),
			'pause' =>  __('Click to pause', 'shortpixel-image-optimiser'),
			'resume' => __('Click to resume', 'shortpixel-image-optimiser'),
			'item' => __('item in queue', 'shortpixel-image-optimiser'),
			'items' => __('items in queue', 'shortpixel-image-optimiser'),
		);

		wp_localize_script( 'shortpixel-tooltip', 'spio_tooltipStrings', $tooltip_localize);

		wp_register_script( 'shortpixel-settings', plugins_url( 'res/js/shortpixel-settings.js', SHORTPIXEL_PLUGIN_FILE ), array('shortpixel-shiftselect', 'shortpixel-inline-help'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script('shortpixel-shiftselect', plugins_url('res/js/shift-select.js', SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

		wp_localize_script('shortpixel-settings', 'settings_strings', UiHelper::getSettingsStrings(false));


		wp_register_script( 'shortpixel-onboarding', plugins_url( 'res/js/shortpixel-onboarding.js', SHORTPIXEL_PLUGIN_FILE ), array('shortpixel-settings'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script('shortpixel-media', plugins_url('res/js/shortpixel-media.js',  SHORTPIXEL_PLUGIN_FILE), array('jquery'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

		wp_register_script('shortpixel-inline-help', plugins_url('res/js/shortpixel-inline-help.js',  SHORTPIXEL_PLUGIN_FILE), [], SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);
		wp_register_script('shortpixel-chatbot', 
		apply_filters('shortpixel/plugin/nohelp', 'https://spcdn.shortpixel.ai/assets/js/ext/ai-chat-agent.js'), [], SHORTPIXEL_IMAGE_OPTIMISER_VERSION, $args_footer_async);

		// This filter is from ListMediaViewController for the media library grid display, executive script in shortpixel-media.js.

		$filters = array('optimized' => array(
					'all' => __('Any ShortPixel State', 'shortpixel-image-optimiser'),
					'optimized' => __('Optimized', 'shortpixel-image-optimiser'),
					'unoptimized' => __('Unoptimized', 'shortpixel-image-optimiser'),
					'prevented' => __('Optimization Error', 'shortpixel-image-optimiser'),
		));

		$editor_localize = ImageEditorController::localizeScript();
		$editor_localize['mediafilters'] = $filters;
		wp_localize_script('shortpixel-media', 'spio_media', $editor_localize);

		wp_register_script( 'shortpixel-processor', plugins_url( '/res/js/shortpixel-processor.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-tooltip' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		 // How often JS processor asks for next tick on server. Low for fastestness and high loads, high number for surviving servers.
		$interval = apply_filters( 'shortpixel/processor/interval', 3000 );

		// If the queue is empty how often to check if something new appeared from somewhere. Excluding the manual items added by current processor user.
		$deferInterval = apply_filters( 'shortpixel/process/deferInterval', 60000 );

		$debug = (\wpSPIO()->env()->is_debug) ? 'true' : 'false';

		wp_localize_script(
            'shortpixel-processor',
            'ShortPixelProcessorData',
            array(
				'bulkSecret'        => $secretKey,
				'isBulkPage'        => (bool) $is_bulk_page,
				'workerURL'         => plugins_url( 'res/js/shortpixel-worker.js', SHORTPIXEL_PLUGIN_FILE ),
				'nonce_process'     => wp_create_nonce( 'processing' ),
				'nonce_exit'        => wp_create_nonce( 'exit_process' ),
				'nonce_ajaxrequest' => wp_create_nonce( 'ajax_request' ),
				'nonce_settingsrequest' => wp_create_nonce('settings_request'),
				'startData'         => ( \wpSPIO()->env()->is_screen_to_use ) ? $queueController->getStartupData() : false,
				'interval'          => $interval,
				'deferInterval'     => $deferInterval,
				'debugIsActive' 	=> $debug,
				'autoMediaLibrary'  => ($settings->autoMediaLibrary) ? 'true' : 'false',
				'disable_processor' => apply_filters('shortpixel/processorjs/disable', false),
				// Whether to show console logging in frontend JS. Filter name: '/shortpixel/front/disablelog'
				// NOTE: The filter should return a boolean: true => show logs, false => don't show logs.
				'showConsoleLog'    => apply_filters('/shortpixel/front/showConsoleLog', $debug),
            )
        );

		//https://github.com/thedatepicker/thedatepicker
		wp_register_script('shortpixel-datepicker', plugins_url('res/js/the-datepicker.min.js', SHORTPIXEL_PLUGIN_FILE),  ['wp-components', 'wp-i18n', 'wp-element', 'wp-hooks'], SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);
		

		/*** SCREENS */
		wp_register_script('shortpixel-screen-base', plugins_url( '/res/js/screens/screen-base.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script('shortpixel-screen-item-base', plugins_url( '/res/js/screens/screen-item-base.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor', 'shortpixel-screen-base'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script( 'shortpixel-screen-media', plugins_url( '/res/js/screens/screen-media.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor', 'shortpixel-screen-base', 'shortpixel-screen-item-base' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script( 'shortpixel-screen-custom', plugins_url( '/res/js/screens/screen-custom.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor', 'shortpixel-screen-base', 'shortpixel-screen-item-base' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script( 'shortpixel-screen-nolist', plugins_url( '/res/js/screens/screen-nolist.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor', 'shortpixel-screen-base' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

	  $screen_localize = array(  // Item Base
			'startAction' => __('Processing... ','shortpixel-image-optimiser'),
			'startActionAI' => __('Generating image SEO data', 'shortpixel-image-optimiser'),
			'fatalError' => __('ShortPixel encountered a fatal error when optimizing images. Please check the issue below. If this is caused by a bug please contact our support', 'shortpixel-image-optimiser'),
			'fatalErrorStop' => __('ShortPixel has encounted multiple errors and has now stopped processing', 'shortpixel-image-optimiser'),
			'fatalErrorStopText' => __('No items are being processed. To try again after solving the issues, please reload the page ', 'shortpixel-image-optimiser'),
			'fatalError500' => __('A fatal error HTTP 500 has occurred. On the bulk screen, this may be caused by the script running out of memory. Check your error log, increase memory or disable heavy plugins.'),

		);
	

	 $screen_localize_custom = array( // Custom Screen
			'stopActionMessage' => __('Folder scan has stopped', 'shortpixel-image-optimiser'),
		);

	 $screen_localize_media = [ 
			'hide_ai' => ! $OptimizeAiController->isAiEnabled(),  // turn around negative setting
			'hide_spio_in_popups' => apply_filters('shortpixel/js/media/hide_in_popups', false), 
			'modalcss' => plugins_url('res/css/shortpixel-media-modal.css', SHORTPIXEL_PLUGIN_FILE), 
			'remove_background_title' => __('AI Background Removal', 'shortpixel-image-optimiser'),
			'scale_title' => __('AI Image Upscale', 'shortpixel-image-optimiser'),
			'upscale_max_width' => 1200, // Scale X and max width pin Pixels.
			'popup_load_preview' => true, // Upon opening, load Preview or not.
			'too_big_for_scale_title'  => __('Image too big for scaling', 'shortpixel-image-optimiser'), 
			'wp_screen_id' => $env->screen_id, 
	 ];

		wp_localize_script('shortpixel-screen-media', 'spio_mediascreen_settings', $screen_localize_media); 

		wp_localize_script( 'shortpixel-screen-base', 'spio_screenStrings', array_merge($screen_localize, $screen_localize_custom));

		wp_register_script( 'shortpixel-screen-bulk', plugins_url( '/res/js/screens/screen-bulk.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor', 'shortpixel-screen-base'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
		$panel = isset( $_GET['panel'] ) ? sanitize_text_field( wp_unslash($_GET['panel']) ) : false;

		$bulkLocalize = [
			'endBulk'   => __( 'This will stop the bulk processing and take you back to the start. Are you sure you want to do this?', 'shortpixel-image-optimiser' ),
			'reloadURL' => admin_url( 'upload.php?page=wp-short-pixel-bulk'),
		];
		if ( $panel ) {
			$bulkLocalize['panel'] = $panel;
        }

		// screen translations. Can all be loaded on the same var, since only one screen can be active.
		wp_localize_script( 'shortpixel-screen-bulk', 'shortPixelScreen', $bulkLocalize );

		wp_register_script( 'shortpixel', plugins_url( '/res/js/shortpixel.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'jquery.knob.min.js' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		// Using an Array within another Array to protect the primitive values from being cast to strings
		$ShortPixelConstants = array(
			array(
				'WP_PLUGIN_URL'     => plugins_url( '', SHORTPIXEL_PLUGIN_FILE ),
				'WP_ADMIN_URL'      => admin_url(),
				'API_IS_ACTIVE'     => $keyControl->keyIsVerified(),
				'AJAX_URL'          => admin_url( 'admin-ajax.php' ),
				'BULK_SECRET'       => $secretKey,
				'nonce_ajaxrequest' => wp_create_nonce( 'ajax_request' ),
				'HAS_QUOTA'         => ( $quotaController->hasQuota() ) ? 1 : 0,

			),
		);

		if ( Log::isManualDebug() ) {
			Log::addInfo( 'Ajax Manual Debug Mode' );
			$logLevel                           = Log::getLogLevel();
			$ShortPixelConstants[0]['AJAX_URL'] = admin_url( 'admin-ajax.php?SHORTPIXEL_DEBUG=' . $logLevel );
		}

		$jsTranslation = array(
			'optimizeWithSP'              => __( 'ShortPixel', 'shortpixel-image-optimiser' ),
			'optimize'              => __( 'Optimize', 'shortpixel-image-optimiser' ),
			'redoLossy'                   => __( 'Re-optimize Lossy', 'shortpixel-image-optimiser' ),
			'redoGlossy'                  => __( 'Re-optimize Glossy', 'shortpixel-image-optimiser' ),
			'redoLossless'                => __( 'Re-optimize Lossless', 'shortpixel-image-optimiser' ),
			'redoSmartcrop'               => __( 'Re-optimize with SmartCrop', 'shortpixel-image-optimiser'),
			'redoSmartcropless'           => __( 'Re-optimize without SmartCrop', 'shortpixel-image-optimiser'),
			'restoreOriginal'             => __( 'Restore Originals', 'shortpixel-image-optimiser' ),
			'generateAI' 				  => __( 'Generate image SEO data', 'shortpixel-image-optimiser'),
			'markCompleted' 			  => __('Mark as completed' ,'shortpixel-image-optimiser'),
			'areYouSureStopOptimizing'    => __( 'Are you sure you want to stop optimizing the folder {0}?', 'shortpixel-image-optimiser' ),
			'pleaseDoNotSetLesserSize'    => __( "Please do not set a {0} less than the {1} of the largest thumbnail which is {2}, to be able to still regenerate all your thumbnails in case you'll ever need this.", 'shortpixel-image-optimiser' ),
			'pleaseDoNotSetLesser1024'    => __( "Please do not set a {0} less than 1024, to be able to still regenerate all your thumbnails in case you'll ever need this.", 'shortpixel-image-optimiser' ),
			'confirmBulkRestore'          => __( 'Are you sure you want to restore from backup all the images in your Media Library optimized with ShortPixel?', 'shortpixel-image-optimiser' ),
			'confirmBulkCleanup'          => __( "Are you sure you want to cleanup the ShortPixel metadata info for the images in your Media Library optimized with ShortPixel? This will make ShortPixel 'forget' that it optimized them and will optimize them again if you re-run the Bulk Optimization process.", 'shortpixel-image-optimiser' ),
			'alertDeliverWebPAltered'     => __( "Warning: Using this method alters the structure of the rendered HTML code (IMG tags get included in PICTURE tags), which, in some rare \ncases, can lead to CSS/JS inconsistencies.\n\nPlease test this functionality thoroughly after activating!\n\nIf you notice any issue, just deactivate it and the HTML will will revert to the previous state.", 'shortpixel-image-optimiser' ),
			'alertDeliverWebPUnaltered'   => __( 'This option will serve both WebP and the original image using the same URL, based on the web browser capabilities, please make sure you\'re serving the images from your server and not using a CDN which caches the images.', 'shortpixel-image-optimiser' ),
			'originalImage'               => __( 'Original image', 'shortpixel-image-optimiser' ),
			'optimizedImage'              => __( 'Optimized image', 'shortpixel-image-optimiser' ),
			'loading'                     => __( 'Loading...', 'shortpixel-image-optimiser' ),

		);

		wp_localize_script( 'shortpixel', '_spTr', $jsTranslation );
		wp_localize_script( 'shortpixel', 'ShortPixelConstants', $ShortPixelConstants );

	}

	/**
	 * Register every CSS stylesheet the plugin might need. Actual enqueue
	 * happens in `load_admin_scripts()` and via `load_style()` for
	 * on-demand cases.
	 *
	 * The inventory covers: folderbrowser, notices (SPIO + module),
	 * othermedia screen, toolbar (loaded everywhere), general admin,
	 * bulk, nextgen, settings, datepicker. Nothing is enqueued from
	 * here — this method is a pure registration pass.
	 *
	 * @return void
	 */
	public function admin_styles() {

		wp_register_style( 'shortpixel-folderbrowser', plugins_url( '/res/css/shortpixel-folderbrowser.css', SHORTPIXEL_PLUGIN_FILE ),[], SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		//wp_register_style( 'shortpixel', plugins_url( '/res/css/short-pixel.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		// notices. additional styles for SPIO.
		wp_register_style( 'shortpixel-notices', plugins_url( '/res/css/shortpixel-notices.css', SHORTPIXEL_PLUGIN_FILE ), array( 'shortpixel-admin' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		wp_register_style('notices-module', plugins_url('/build/shortpixel/notices/src/css/notices.css', SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

		// other media screen
		wp_register_style( 'shortpixel-othermedia', plugins_url( '/res/css/shortpixel-othermedia.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		// load everywhere, because we are inconsistent.
		wp_register_style( 'shortpixel-toolbar', plugins_url( '/res/css/shortpixel-toolbar.css', SHORTPIXEL_PLUGIN_FILE ), array( 'dashicons' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		wp_register_style( 'shortpixel-admin', plugins_url( '/res/css/shortpixel-admin.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		wp_register_style( 'shortpixel-bulk', plugins_url( '/res/css/shortpixel-bulk.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		wp_register_style( 'shortpixel-nextgen', plugins_url( '/res/css/shortpixel-nextgen.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		wp_register_style( 'shortpixel-settings', plugins_url( '/res/css/shortpixel-settings.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		wp_register_style('shortpixel-datepicker', plugins_url('res/css/the-datepicker.css', SHORTPIXEL_PLUGIN_FILE), [], SHORTPIXEL_IMAGE_OPTIMISER_VERSION );
	}


	/**
	 * On-demand style enqueue with registered-check.
	 *
	 * Silently bails on `noheader` requests (partials rendered without a
	 * full admin shell). Logs a warning when a caller asks for a style
	 * that wasn't registered — usually a typo or a missing `admin_styles`
	 * entry.
	 *
	 * @param string $name Handle previously registered in `admin_styles()`.
	 * @return void
	 */
	public function load_style( $name ) {
		if ( $this->is_noheaders ) {  // fail silently, if this is a no-headers request.
			return;
		}

		if ( wp_style_is( $name, 'registered' ) ) {
			wp_enqueue_style( $name );
		} else {
			Log::addWarn( "Style $name was asked for, but not registered", $_SERVER['REQUEST_URI'] );
		}
	}

	/**
	 * On-demand script enqueue with registered-check. Accepts either a
	 * single handle or an array of handles for batch enqueue.
	 *
	 * Silently bails on `noheader` requests and logs a warning for
	 * unregistered handles — same shape as `load_style()`.
	 *
	 * @param string|string[] $script Handle (or list of handles) previously registered in `admin_scripts()`.
	 * @return void
	 */
	public function load_script( $script ) {
		if ( $this->is_noheaders ) {  // fail silently, if this is a no-headers request.
			return;
		}

		if ( ! is_array( $script ) ) {
			$script = array( $script );
		}

		foreach ( $script as $index => $name ) {
			if ( wp_script_is( $name, 'registered' ) ) {
				wp_enqueue_script( $name );
			} else {
				Log::addWarn( "Script $name was asked for, but not registered", $_SERVER['REQUEST_URI']  );
			}
		}
	}

	/**
	 * Screen-specific asset dispatcher — decides which scripts/styles get
	 * enqueued for the current admin page.
	 *
	 * Runs on `admin_enqueue_scripts` at priority 90 (registered by
	 * `initHooks()`) and, separately, on `enqueue_block_assets` for the
	 * block editor. Runs in `<head>` — deliberately separated from
	 * `route()` so styles arrive before the page body renders (no
	 * flash-of-unstyled-content).
	 *
	 * Decision tree (first match wins), where each branch enqueues the
	 * baseline processor bundle + a screen-specific `shortpixel-screen-*`
	 * bundle + the styles needed for that screen:
	 *
	 *   - Any "SPIO-relevant" screen → processor + toolbar + notices.
	 *   - `wp-shortpixel-settings` / `shortpixel-network-settings` → nolist
	 *     screen, settings, chatbot, onboarding.
	 *   - `wp-short-pixel-bulk` → bulk screen, chatbot, datepicker.
	 *   - `upload` / `attachment` screens → media screen + debug (when
	 *     `env()->is_debug`).
	 *   - `wp-short-pixel-custom` → folderbrowser + custom screen +
	 *     othermedia styles + chatbot.
	 *   - NextGen screen (delegated to `NextGenController::isNextGenScreen()`)
	 *     → custom screen + nextgen style.
	 *   - Gutenberg / classic editor → processor + media screen.
	 *   - Any other SPIO-relevant screen → nolist screen fallback.
	 *
	 * @param string $hook_suffix WP admin hook suffix (unused; branching is
	 *                            on `$plugin_page` / `env()->screen_id`).
	 * @return void
	 */
	 public function load_admin_scripts( $hook_suffix ) {
		global $plugin_page;
		$screen_id = $this->env()->screen_id;

		$load_processor = array( 'shortpixel', 'shortpixel-processor' );  // a whole suit needed for processing, not more. Always needs a screen as well!
		$load_bulk      = array();  // the whole suit needed for bulking.
		if ( \wpSPIO()->env()->is_screen_to_use ) {
			$this->load_script( $load_processor );
			$this->load_style( 'shortpixel-toolbar' );
			$this->load_style('shortpixel-notices');
			$this->load_style('notices-module');
		}

		if ( $plugin_page == 'wp-shortpixel-settings' || $plugin_page == 'shortpixel-network-settings' ) {

			$this->load_script( 'shortpixel-screen-nolist' ); // screen
			$this->load_script( 'shortpixel-settings' );
			$this->load_script('shortpixel-chatbot');

			// @todo Load onboarding only when no api key / onboarding required
			$this->load_script('shortpixel-onboarding');

			$this->load_style( 'shortpixel-admin' );

			$this->load_style( 'shortpixel-settings' );

		} elseif ( $plugin_page == 'wp-short-pixel-bulk' ) {
			$this->load_script( 'shortpixel-screen-bulk' );
			$this->load_script('shortpixel-chatbot');
			$this->load_script('shortpixel-datepicker');

			$this->load_style('shortpixel-datepicker');
			$this->load_style( 'shortpixel-admin' );
			$this->load_style( 'shortpixel-bulk' );
		} elseif ( $screen_id == 'upload' || $screen_id == 'attachment' ) {

			$this->load_script( 'shortpixel-screen-media' ); // screen
			$this->load_script( 'shortpixel-media' );

			$this->load_style( 'shortpixel-admin' );
			$this->load_style( 'notices-module');
		//	$this->load_style( 'shortpixel' );

			if ( $this->env()->is_debug ) {
				$this->load_script( 'shortpixel-debug' );
			}

		} elseif ( $plugin_page == 'wp-short-pixel-custom' ) { // custom media
		//	$this->load_style( 'shortpixel' );

			$this->load_script( 'shortpixel-folderbrowser' );
			$this->load_script('shortpixel-chatbot');

			$this->load_style( 'shortpixel-admin' );
			$this->load_style( 'shortpixel-folderbrowser' );
			$this->load_style( 'shortpixel-othermedia' );
			$this->load_script( 'shortpixel-screen-custom' ); // screen

		} elseif ( NextGenController::getInstance()->isNextGenScreen() ) {

			$this->load_script( 'shortpixel-screen-custom' ); // screen
			$this->load_style( 'shortpixel-admin' );

		//	$this->load_style( 'shortpixel' );
			$this->load_style( 'shortpixel-nextgen' );
		}
		elseif (true === $this->env()->is_gutenberg_editor || true === $this->env()->is_classic_editor)
		{
			$this->load_script( $load_processor );
			$this->load_script( 'shortpixel-screen-media' ); // screen
			$this->load_script( 'shortpixel-media' );

			$this->load_style( 'shortpixel-admin' );
		}
		elseif (true === \wpSPIO()->env()->is_screen_to_use  )
		{
			// If our screen, but we don't have a specific handler for it, do the no-list screen.
			$this->load_script( 'shortpixel-screen-nolist' ); // screen
		}

	}

	/**
	 * Dispatch admin page loads to the appropriate `Controller\View\*` class.
	 *
	 * Registered as the callback for every SPIO admin page (in
	 * `admin_pages()`) and for Media Library screen loads
	 * (`load-upload.php`, `load-post.php`, wired in `initHooks()`).
	 *
	 * Two inputs pick the controller:
	 *
	 *   - `$plugin_page` — the SPIO admin submenu slug when the request
	 *     targets one of our pages (`wp-shortpixel-settings`,
	 *     `shortpixel-network-settings`, `wp-short-pixel-custom` +
	 *     optional `?part=folders|scan`, `wp-short-pixel-bulk`).
	 *   - `env()->screen_id` — when there's no `$plugin_page`, the
	 *     Media Library / Edit Media screens fall through to
	 *     `ListMediaViewController` / `EditMediaViewController`.
	 *
	 * Two inputs pick the action on the resolved controller:
	 *
	 *   - `$_REQUEST['sp-action']` — the method name to invoke; defaults
	 *     to `load`.
	 *   - `$_GET['part']` — used above only to pick the OtherMedia
	 *     sub-controller (folders / scan / default).
	 *
	 * When the requested action doesn't exist on the resolved controller
	 * we log a warning and fall back to `load`. When no controller
	 * matches at all, `route()` no-ops silently — WP renders the empty
	 * page.
	 *
	 * @return void
	 */
	public function route() {
		global $plugin_page;

		$default_action = 'load'; // generic action on controller.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
		$action         = isset( $_REQUEST['sp-action'] ) ? sanitize_text_field( wp_unslash($_REQUEST['sp-action']) ) : $default_action;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
		$template_part  = isset( $_GET['part'] ) ? sanitize_text_field( wp_unslash($_GET['part']) ) : false;

		$controller = false;

		$url = '';
		if (! is_null($plugin_page))
		{
			$url       = menu_page_url( $plugin_page, false );
		}
		$screen_id = \wpSPIO()->env()->screen_id;

        switch ( $plugin_page ) {
            case 'wp-shortpixel-settings': // settings
						$controller = 'ShortPixel\Controller\View\SettingsViewController';
						wp_enqueue_media();
        	break;
			case 'shortpixel-network-settings':
					 	$controller = 'ShortPixel\Controller\View\MultiSiteViewController';
			break;
          case 'wp-short-pixel-custom': // other media
						if ('folders'  === $template_part )
						{
							$controller = 'ShortPixel\Controller\View\OtherMediaFolderViewController';
						}
						elseif('scan' === $template_part)
						{
							$controller = 'ShortPixel\Controller\View\OtherMediaScanViewController';
						}
						else {
							$controller = 'ShortPixel\Controller\View\OtherMediaViewController';
						}

        	break;
        	case 'wp-short-pixel-bulk':
						$controller = '\ShortPixel\Controller\View\BulkViewController';
           break;
           case null:
            default:
                switch ( $screen_id ) {
					case 'upload':
                  $controller = '\ShortPixel\Controller\View\ListMediaViewController';
                        break;
					case 'attachment': // edit-media
                   $controller = '\ShortPixel\Controller\View\EditMediaViewController';
                     break;
                }
                break;

		}
		if ( $controller !== false ) {
			$c = $controller::getInstance();
			$c->setControllerURL( $url );
			if ( method_exists( $c, $action ) ) {
				$c->$action();
			} else {
				Log::addWarn( "Attempted Action $action on $controller does not exist!" );
				$c->$default_action();
			}
		}
	}


	/**
	 * Return the plugin's public URL, optionally with a relative path
	 * appended.
	 *
	 * The base URL was captured in `lowInit()` via `plugin_dir_url()` and
	 * always includes a trailing slash after `trailingslashit`.
	 *
	 * @param string $urlpath Relative URL fragment appended to the base (empty by default).
	 * @return string Absolute URL to the plugin directory (or an asset under it).
	 */
	public function plugin_url( $urlpath = '' ) {
		$url = trailingslashit( $this->plugin_url );
		if ( strlen( $urlpath ) > 0 ) {
			$url .= $urlpath;
		}
		return $url;
	}

	/**
	 * Return the plugin's absolute filesystem path, optionally with a
	 * relative path appended.
	 *
	 * The base path was captured in `lowInit()` via `plugin_dir_path()`
	 * and always includes a trailing slash after `trailingslashit`.
	 *
	 * @param string $path Relative path fragment appended to the base (empty by default).
	 * @return string Absolute filesystem path to the plugin directory (or a file under it).
	 */
	public function plugin_path( $path = '' ) {
		$plugin_path = trailingslashit( $this->plugin_path );
		if ( strlen( $path ) > 0 ) {
			$plugin_path .= $path;
		}

		return $plugin_path;
	}

	/**
	 * Return the WP admin-page hook suffixes captured during
	 * `admin_pages()`.
	 *
	 * For internal use — prefer `EnvironmentModel` flags (`is_bulk_page`,
	 * `is_screen_to_use`, etc.) when you just need to know "are we on an
	 * SPIO screen".
	 *
	 * @return array<int, string> Hook suffixes returned by `add_options_page` / `add_media_page`.
	 */
	public function get_admin_pages() {
		return $this->admin_pages;
	}

	/**
	 * Detect plugin-version drift and re-run activation when necessary.
	 *
	 * Compares the `SHORTPIXEL_IMAGE_OPTIMISER_VERSION` constant against
	 * the `currentVersion` value stored in settings. A mismatch triggers
	 * `InstallHelper::activatePlugin()` (table upgrades, option
	 * migrations, etc.) and then writes the new version back. Runs on
	 * every `admin_init`, so an upgrade takes effect the first time an
	 * admin visits the dashboard after the plugin files change.
	 *
	 * @return void
	 */
	protected function check_plugin_version() {
      $version     = SHORTPIXEL_IMAGE_OPTIMISER_VERSION;
			$db_version = $this->settings()->currentVersion;

		if ( $version !== $db_version ) {
			InstallHelper::activatePlugin();
			$this->settings()->currentVersion = $version;

		}
	}




} // class plugin
