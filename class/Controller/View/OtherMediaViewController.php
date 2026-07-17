<?php
namespace ShortPixel\Controller\View;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\OtherMediaController as OtherMediaController;

use ShortPixel\Model\File\DirectoryOtherMediaModel as DirectoryOtherMediaModel;
use ShortPixel\Model\Image\ImageModel as ImageModel;

use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;

use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Model\Image\CustomImageModel;

/**
 * View controller for the Custom Media (Other Media) list screen.
 *
 * Renders the paginated, sortable, and filterable image list on the Custom
 * Media admin page (upload.php?page=wp-short-pixel-custom) using the
 * `view-other-media` template.
 *
 * Wired up by AdminController. Pagination state, ordering, and search terms
 * are read from GET parameters in the constructor and applied in queryItems().
 * Images found to be missing on disk are removed automatically during getItems().
 *
 * @package ShortPixel\Controller\View
 */
class OtherMediaViewController extends \ShortPixel\ViewController
{
      protected $template = 'view-other-media';

			protected static $instance;

    	/** @var string WordPress user option key for storing the per-page preference. */
    	const OTHER_MEDIA_PER_PAGE_OPTION = 'shortpixel_custom_media_per_page';

      /** @var int Number of items to show per page (loaded from the user screen option). */
      protected $items_per_page = 20;
      /** @var int Current page number (1-based), derived from $_GET['paged']. */
      protected $currentPage = 1;
      /** @var int Total number of items matching the current filter, set by queryItems(). */
      protected $total_items = 0;
      /** @var string Sort direction ('asc' or 'desc'), derived from $_GET['order']. */
      protected $order;
      /** @var string Column to sort by, validated against allowed headings, derived from $_GET['orderby']. */
      protected $orderby;
      /** @var string|false Search string from $_GET['s'], or false when no search is active. */
      protected $search;

      /** @var bool|string Whether to show hidden (inactive) directories instead of active ones. */
			protected $show_hidden = false;
      /** @var bool Whether any hidden directory IDs exist, used to conditionally show the "show hidden" toggle. */
			protected $has_hidden_items = false;

      /**
       * Reads pagination, ordering, search, and per-page preferences from GET parameters.
       *
       * No nonce is required for these read-only URL parameters (PHPCS suppression
       * comments are present in-code). The per-page value is loaded from the user's
       * stored screen option via loadScreenPerPageOption().
       */
      public function __construct()
      {
        parent::__construct();

				// 2015: https://github.com/WordPress/WordPress-Coding-Standards/issues/426 !
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
        $this->currentPage = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
        $this->orderby = ( ! empty( $_GET['orderby'] ) ) ? $this->filterAllowedOrderBy(sanitize_text_field(wp_unslash($_GET['orderby']))) : 'id';
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
        $this->order = ( ! empty($_GET['order'] ) ) ? sanitize_text_field( wp_unslash($_GET['order'])) : 'desc'; // If no order, default to asc
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
        $this->search =  (isset($_GET["s"]) && is_string($_GET['s']) && strlen($_GET["s"]) > 0)  ? sanitize_text_field( wp_unslash($_GET['s'])) : false;
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
				$this->show_hidden = isset($_GET['show_hidden']) ? sanitize_text_field(wp_unslash($_GET['show_hidden'])) : false;

        $this->items_per_page = $this->loadScreenPerPageOption(self::OTHER_MEDIA_PER_PAGE_OPTION, $this->items_per_page );

      }

      /** Controller default action - overview */
      public function load()
      {
        //  $this->process_actions();

          $this->view->items = $this->getItems();
          $this->view->folders = $this->getItemFolders($this->view->items);
          $this->view->headings = $this->getHeadings();
          $this->view->pagination = $this->getPagination();

          $this->view->filter = $this->getFilter();

					$this->view->title = __('Custom Media optimized by ShortPixel', 'shortpixel-image-optimiser');
					$this->view->show_search = true;

    //      $this->checkQueue();
          $this->loadView();
      }


      /**
       * Returns the column heading definitions for the custom-media list table.
       *
       * Each entry is an associative array with 'title', 'sortable', and optionally
       * 'orderby'. The 'actions' heading is conditionally removed when no API key
       * is verified.
       *
       * @return array<string, array<string, mixed>> Column heading definitions keyed by column slug.
       */
      protected function getHeadings()
      {
         $headings = array(
              'checkbox' => array('title' => '<input type="checkbox" name="select-all">', 'sortable' => false),
              'thumbnails' => array('title' => __('Thumbnail', 'shortpixel-image-optimiser'),
                              'sortable' => false,
                              'orderby' => 'id',  // placeholder to allow sort on this.
                            ),
               'name' =>  array('title' => __('Name', 'shortpixel-image-optimiser'),
                                'sortable' => true,
                                'orderby' => 'name',
                            ),
               'folder' => array('title' => __('Folder', 'shortpixel-image-optimiser'),
                                'sortable' => true,
                                'orderby' => 'path',
                            ),
               'type' =>   array('title' => __('Type', 'shortpixel-image-optimiser'),
                                'sortable' => false,
                                ),
               'date' =>    array('title' => __('Date', 'shortpixel-image-optimiser'),
                                'sortable' => true,
                                'orderby' => 'ts_optimized',
                             ),
               'status' => array('title' => __('Status', 'shortpixel-image-optimiser'),
                                'sortable' => true,
                                'orderby' => 'status',
                            ),

        );

        $keyControl = ApiKeyController::getInstance();
        if (! $keyControl->keyIsVerified())
        {
            $headings['actions']['title']  = '';
        }

        return $headings;
      }

      /**
       * Registers the "Items per page" screen option for the custom-media list screen.
       *
       * Hooked to the `load-{page_hook}` action by AdminController so the option
       * appears in the Screen Options panel.
       *
       * @return void
       */
      public function addOtherMediaScreenOptions() {
      add_screen_option( 'per_page', array(
        'label'   => __( 'Items per page', 'shortpixel-image-optimiser' ),
        'default' => 20,
        'option'  => self::OTHER_MEDIA_PER_PAGE_OPTION,
      ) );
  	}

    /**
     * Filters the saved value for the custom-media per-page screen option.
     *
     * Hooked to `set-screen-option` (pre-WP 5.4) or `set_screen_option_{option}`
     * (WP 5.4+). Returns the int value when the option matches; otherwise passes
     * $status through unchanged.
     *
     * @param bool|int $status  The current status (passed from WordPress).
     * @param string   $option  The option name being saved.
     * @param int      $value   The value the user selected.
     * @return bool|int The integer value when our option matches; $status otherwise.
     */
    public function setScreenOption( $status, $option, $value ) {
      if ( self::OTHER_MEDIA_PER_PAGE_OPTION === $option ) {
        return intval( $value );
      }

      return $status;
    }

      /**
       * Loads the current page of custom-media items, removing any that no longer exist on disk.
       *
       * Calls queryItems() to get raw DB rows, then resolves each to a
       * CustomImageModel via the filesystem helper. Models whose file is missing
       * are deleted via onDelete() and a warning notice is added listing the
       * removed paths.
       *
       * @return array<int, \ShortPixel\Model\Image\CustomImageModel> Loaded, existing image models.
       */
      protected function getItems() : array
      {
          $fs = \wpSPIO()->filesystem();

          // [BS] Moving this from ts_added since often images get added at the same time, resulting in unpredictable sorting
          $items = $this->queryItems();

          $removed = array();
          foreach($items as $index => $item)
          {
             $mediaItem = $fs->getImage($item->id, 'custom');

             if (! $mediaItem->exists()) // remove image if it doesn't exist.
             {
                $mediaItem->onDelete();

                $removed[] = $item->path;
                unset($items[$index]);
             }
             $items[$index] = $mediaItem;
          }

          if (count($removed) > 0)
          {
            Notices::addWarning(sprintf(__('Some images were missing. They have been removed from the Custom Media overview : %s %s', 'shortpixel-image-optimiser'),
                '<BR>', implode('<BR>', $removed)));
          }

          return $items;
      }

     /**
      * Reads the user's stored screen-option value for items per page.
      *
      * Returns $default when get_user_option() is unavailable or when the
      * stored value is zero or negative.
      *
      * @param string $option_name WordPress user option key.
      * @param int    $default     Fallback items-per-page value. Default 20.
      * @return int Number of items per page.
      */
     protected function loadScreenPerPageOption( $option_name, $default = 20 )
    {
      if ( ! function_exists( 'get_user_option' ) ) {
          return $default;
      }

      $value = get_user_option( $option_name );
      $value = intval( $value );

      if ( $value > 0 ) {
          return $value;
      }

      return $default;
    }

      /**
       * Returns a map of folder_id to DirectoryOtherMediaModel for all items in the current page.
       *
       * Deduplicates folder lookups: each unique folder_id is fetched only once.
       *
       * @param array<int, \ShortPixel\Model\Image\CustomImageModel> $items Loaded image models.
       * @return array<int, \ShortPixel\Model\File\DirectoryOtherMediaModel> Folder models keyed by folder_id.
       */
      protected function getItemFolders($items)
      {
         $folderArray = array();
         $otherMedia = OtherMediaController::getInstance();

         foreach ($items as $item) // mediaItem;
         {
            $folder_id = $item->get('folder_id');
            if (! isset($folderArray[$folder_id]))
            {
              $folderArray[$folder_id] = $otherMedia->getFolderByID($folder_id);
            }
         }

         return $folderArray;
      }

      /**
       * Alias of getItemFolders() — returns a folder-id-to-model map for a set of items.
       *
       * This method duplicates getItemFolders() and is not currently called from
       * the main load() path; getItemFolders() is preferred.
       *
       * @param array<int, \ShortPixel\Model\Image\CustomImageModel> $items Loaded image models.
       * @return array<int, \ShortPixel\Model\File\DirectoryOtherMediaModel> Folder models keyed by folder_id.
       */
      protected function loadFolders($items)
      {
         $folderArray = array();
         $otherMedia = OtherMediaController::getInstance();

         foreach($items as $item)
         {
            $folder_id = $item->get('folder_id');
            if (! isset($folderArray[$folder_id]))
            {
                $folderArray[$folder_id]  = $otherMedia->getFolderByID($folder_id);
            }
         }

         return $folderArray;

      }

      /**
       * Builds the SQL filter array from active GET parameters.
       *
       * Reads $_GET['s'] (path LIKE search), $_GET['folder_id'] (exact folder match),
       * and $_GET['custom-status'] (optimized / unoptimized / prevented). Also sets
       * $this->view->hasFilter and $this->view->hasSearch flags for the template.
       *
       * Filter values are constructed as stdClass objects with 'operator' and 'value'
       * properties and consumed directly by queryItems(). Note that SQL values are
       * assembled via string concatenation with esc_sql(); no wpdb::prepare() is used
       * here (see Suspected bugs in report).
       *
       * @return array<string, object> Filter objects keyed by (pseudo-)column name.
       */
      protected function getFilter() {
          $filter = array();

          $this->view->hasFilter = false;
          $this->view->hasSearch = false;

					// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
					$search = (isset($_GET['s'])) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
          if(strlen($search) > 0) {

            $this->view->hasSearch = true;
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
              $filter['path'] = (object)array("operator" => "like", "value" =>"'%" . esc_sql($search) . "%'");
          }

				  $folderFilter =  (isset($_GET['folder_id'])) ? intval($_GET['folder_id']) : false;
					if (false !== $folderFilter)
					{
              $this->view->hasFilter = true;
						  $filter['folder_id'] = (object)array("operator" => "=", "value" =>"'" . esc_sql($folderFilter) . "'");
					}

          $statusFilter = isset($_GET['custom-status']) ? sanitize_text_field($_GET['custom-status']) : false;
          if (false !== $statusFilter)
					{
              $operator = '=';
              $value = false;
              $this->view->hasFilter = true;

              switch($statusFilter)
              {
                 case 'optimized':
                    $value = ImageModel::FILE_STATUS_SUCCESS;
                 break;
                 case 'unoptimized':
                     $value = ImageModel::FILE_STATUS_UNPROCESSED;
                 break;
                 case 'prevented':
                    //  $value = 0;
                    //  $operator = '<';
                    $filter['status'] = (object) array('field' => 'status',
                      'operator' => "<", 'value' => "0");

                    $filter['status2'] = (object) array('field' => 'status',
                      'operator' => '<>', 'value' => ImageModel::FILE_STATUS_MARKED_DONE
                  );

                 break;
              }
              if (false !== $value)
              {
						        $filter['status'] = (object)array("operator" => $operator, "value" =>"'" . esc_sql($value) . "'");

              }
					}

          return $filter;
      }

      /**
       * Executes the paginated SQL query for custom-media items and sets $this->total_items.
       *
       * Runs two queries against wp_shortpixel_meta: a COUNT query to determine the
       * total for pagination, and a SELECT query to fetch the current page's rows.
       * Filters from getFilter() are applied to both queries. Ordering is applied via
       * sanitize_sql_orderby(). Returns an empty array when no active (or hidden) folder
       * directories are found.
       *
       * @return array<int, object> Raw DB row objects (not yet resolved to image models).
       */
      public function queryItems() {
          $filters = $this->getFilter();
          global $wpdb;

          $page = $this->currentPage;
					if ($page <= 0)
						$page = 1;

          $controller = OtherMediaController::getInstance();

					$hidden_ids = $controller->getHiddenDirectoryIDS();
					if (count($hidden_ids) > 0)
						$this->has_hidden_items = true;


					if ($this->show_hidden == true)
          	$dirs = implode(',', $hidden_ids );
					else
          	$dirs = implode(',', $controller->getActiveDirectoryIDS() );

          if (strlen($dirs) == 0)
            return array();

          $sql = "SELECT COUNT(id) as count FROM " . $wpdb->prefix . "shortpixel_meta where folder_id in ( " . $dirs  . ") ";

          foreach($filters as $field => $value) {
              $field  = property_exists($value, 'field')  ? $value->field : $field;
              $sql .= " AND $field " . $value->operator . " ". $value->value . " ";
          }

          $this->total_items = $wpdb->get_var($sql);

          $sql = "SELECT * FROM " . $wpdb->prefix . "shortpixel_meta where folder_id in ( " . $dirs  . ") ";

          foreach($filters as $field => $value) {
              $field  = property_exists($value, 'field')  ? $value->field : $field;
              $sql .= " AND $field " . $value->operator . " ". $value->value . " ";
          }


					$sql  .= ($this->orderby ? " ORDER BY " . sanitize_sql_orderby($this->orderby . " " . $this->order) . " " : "")
                  . " LIMIT " . $this->items_per_page . " OFFSET " . ($page - 1) * $this->items_per_page;


          $results = $wpdb->get_results($sql);
          return $results;
      }

      /**
       * Builds the query-string arguments array for pagination links.
       *
       * Merges current state (orderby, order, search, page) with any overrides
       * supplied in $args. Empty values are removed via array_filter so they do
       * not pollute pagination URLs.
       *
       * @param array<string, mixed> $args Overrides for individual arguments.
       * @return array<string, mixed> Filtered query argument map.
       */
      private function getPageArgs($args = array())
      {
        $defaults = array(
            'orderby' => $this->orderby,
            'order' => $this->order,
            's' => $this->search,
            'paged' => $this->currentPage
        );


        $page_args = array_filter(wp_parse_args($args, $defaults));
        return $page_args; // has url

      }

      /**
       * Validates an orderby column name against the allowed heading columns.
       *
       * Collects all 'orderby' values from getHeadings() and returns an empty
       * string when the supplied value is not in the allowed set, preventing
       * SQL injection via the ORDER BY clause.
       *
       * @param string $orderby The raw orderby value from the request.
       * @return string The validated orderby value, or '' if not allowed.
       */
      protected function filterAllowedOrderBy($orderby)
      {
          $headings = $this->getHeadings() ;
          $filters = array();
          foreach ($headings as $heading)
          {
              if (isset($heading['orderby']))
              {
                $filters[]= $heading['orderby'];
              }
          }

          if (! in_array($orderby, $filters))
            return '';

          return $orderby;
      }

      /**
       * Builds the HTML pagination control for the custom-media list table.
       *
       * Returns false when the total number of pages is one or fewer (no pagination
       * needed). Otherwise generates first/prev/current/next/last navigation links
       * wrapped in a GET form so non-JS browsers can submit the page number.
       *
       * @return string|false Pagination HTML string, or false when pagination is unnecessary.
       */
      protected function getPagination()
      {
          $parray = array();

          $current = $this->currentPage;
          $total = $this->total_items;
          $per_page = $this->items_per_page;

          $pages = ceil($total / $per_page);

          if ($pages <= 1)
            return false; // no pages.

          $disable_first = $disable_last = $disable_prev =  $disable_next = false;
          $page_links = array();

           if ( $current == 1 ) {
               $disable_first = true;
               $disable_prev  = true;
           }
           if ( $current == 2 ) {
               $disable_first = true;
           }
           if ( $current == $pages ) {
               $disable_last = true;
               $disable_next = true;
           }
           if ( $current == $pages - 1 ) {
               $disable_last = true;
           }

           $total_pages_before = '<span class="paging-input">';
           $total_pages_after  = '</span></span>';

           $page_args =$this->getPageArgs(); // has url
					 if (isset($page_args['paged']))
					 	unset($page_args['paged']);

					 // Try with controller URL, if not present, try with upload URL and page param.
	         $admin_url = admin_url('upload.php');
	         $url = (is_null($this->url)) ?  add_query_arg('page','wp-short-pixel-custom', $admin_url) : $this->url; // has url
					 $current_url = add_query_arg($page_args, $url);

					 $url = remove_query_arg('page', $url);
					 $page_args['page'] = 'wp-short-pixel-custom';

           $output = '<form method="GET" action="'. esc_attr($url) . '">';
					 foreach($page_args as $arg => $val)
					 {
						  $output .= sprintf('<input type="hidden" name="%s" value="%s">', $arg, $val);
					 }
           $output .= '<span class="displaying-num">'. sprintf(esc_html__('%d Images', 'shortpixel-image-optimiser'), $this->total_items) . '</span>';

           if ( $disable_first ) {
                    $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
                } else {
                    $page_links[] = sprintf(
                        "<a class='first-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                        esc_url( $current_url ),
                        esc_html__( 'First page', 'shortpixel-image-optimiser' ),
                        '&laquo;'
                    );
                }

            if ( $disable_prev ) {
                $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
            } else {
                $page_links[] = sprintf(
                    "<a class='prev-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                    esc_url( add_query_arg( 'paged', max( 1, $current - 1 ), $current_url ) ),
                    esc_html__( 'Previous page', 'shortpixel-image-optimiser' ),
                    '&lsaquo;'
                );
            }

            $html_current_page = sprintf(
                "%s<input class='current-page' id='current-page-selector' type='text' name='paged' value='%s' size='%d' aria-describedby='table-paging' /><span class='tablenav-paging-text'>",
                '<label for="current-page-selector" class="screen-reader-text">' . esc_html__( 'Current Page', 'shortpixel-image-optimiser' ) . '</label>',
                $current,
                strlen( $pages )
            );

            $html_total_pages = sprintf( "<span class='total-pages'>%s</span>", number_format_i18n( $pages ) );
            $page_links[]     = $total_pages_before . sprintf(
                /* translators: 1: Current page, 2: Total pages. */
                _x( '%1$s of %2$s', 'paging', 'shortpixel-image-optimiser' ),
                $html_current_page,
                $html_total_pages
            ) . $total_pages_after;

            if ( $disable_next ) {
                $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
            } else {
                $page_links[] = sprintf(
                    "<a class='next-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                    esc_url( add_query_arg( 'paged', min( $pages, $current + 1 ), $current_url ) ),
                    __( 'Next page', 'shortpixel-image-optimiser'),
                    '&rsaquo;'
                );
            }

            if ( $disable_last ) {
                $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
            } else {
                $page_links[] = sprintf(
                    "<a class='last-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                    esc_url( add_query_arg( 'paged', $pages, $current_url ) ),
                    __( 'Last page', 'shortpixel-image-optimiser' ),
                    '&raquo;'
                );
            }

            $output .= "\n<span class='pagination-links'>" . join( "\n", $page_links ) . '</span>';
            $output .= "</form>";


          return $output;
      }

      /**
       * Returns the action list for a single custom-media item row.
       *
       * Always includes a 'view' link. Optimize/restore/re-optimize actions are
       * added from UiHelper::getActions() only when the API key is verified and
       * quota has not been exceeded.
       *
       * @param \ShortPixel\Model\Image\CustomImageModel $item The image model for this row.
       * @return array<string, array<string, mixed>> Action descriptors keyed by action slug.
       */
      protected function getRowActions($item)
      {

          $settings = \wpSPIO()->settings();

          $keyControl = ApiKeyController::getInstance();

					$actions = UIHelper::getActions($item);

					$viewAction = array('view' => array(
						 'function' => $item->getUrl(),
						 'type' => 'link',
						 'text' => __('View', 'shortpixel-image-optimiser'),
						 'display' => 'inline',

					));

          $rowActions = array();
          $rowActions = array_merge($rowActions, $viewAction);

					if (false === $settings->quotaExceeded || true === $keyControl->keyIsVerified() )
              $rowActions = array_merge($rowActions,$actions);

					return $rowActions;
      }

      /**
       * Renders the ShortPixel action column cell for a single custom-media row.
       *
       * Outputs a wrapping div with the item's ID as the element ID, then calls
       * printItemActions() and UiHelper::getStatusText() to populate the cell.
       * Mirrors the structure of the Media Library column for visual consistency.
       *
       * @param \ShortPixel\Model\Image\CustomImageModel $item The image model for this row.
       * @return void
       */
			public function doActionColumn($item)
			{
          ?>
					<div id='shortpixel-data-<?php echo esc_attr($item->get('id')) ?>'  class='sp-column-info'><?php
							$this->printItemActions($item);

            echo "<div>" .  UiHelper::getStatusText($item) . "</div>";
           ?>
         </div> <!-- sp-column-info -->
        <?php
			}

      /**
       * Outputs the inline action buttons and burger-menu list for a custom-media row.
       *
       * Loads the `snippets/part-single-actions` template for button-style actions
       * (when any are present) and echoes the burger-menu HTML for list-style actions.
       * Used both by doActionColumn() (list table) and by individual item AJAX renders.
       *
       * @param \ShortPixel\Model\Image\CustomImageModel $item The image model for this row.
       * @return void
       */
			public function printItemActions($item)
      {

        $this->view->actions = UiHelper::getActions($item); // $this->getActions($item, $itemFile);

        $list_actions = UiHelper::getListActions($item);

        if (count($list_actions) > 0)
          $list_actions = UiHelper::renderBurgerList($list_actions, $item);
        else
          $list_actions = '';

        if (count($this->view->actions) > 0)
        {

          $this->loadView('snippets/part-single-actions', false);

        }
        echo $list_actions;
      }

      /**
       * Outputs the status filter <select> element for the custom-media list table.
       *
       * Options: 'all', 'optimized', 'unoptimized', 'prevented'. Reads the current
       * selection from the INPUT_GET 'custom-status' parameter (FILTER_UNSAFE_RAW
       * is used; the value is output through esc_attr/esc_html).
       *
       * @return void
       */
      public function printFilter()
      {
            $status   = filter_input(INPUT_GET, 'custom-status', FILTER_UNSAFE_RAW );

            $options = array(
                'all' => __('Any ShortPixel State', 'shortpixel-image-optimiser'),
                'optimized' => __('Optimized', 'shortpixel-image-optimiser'),
                'unoptimized' => __('Unoptimized', 'shortpixel-image-optimiser'),
                'prevented' => __('Optimization Error', 'shortpixel-image-optimiser'),

            );

            echo  "<select name='custom-status' id='status'>\n";
            foreach($options as $optname => $optval)
            {
                $selected = ($status == $optname) ? esc_attr('selected') : '';
                echo "<option value='". esc_attr($optname) . "' $selected >" . esc_html($optval) . "</option>\n";
            }
            echo "</select>";

      }

      /**
       * Outputs <option> elements for the bulk-action <select> on the custom-media page.
       *
       * Covers: optimize, restore, re-optimize (lossy/glossy/lossless), and
       * mark-completed. Called directly from the view template inside a <select> element.
       *
       * @return void
       */
      public function printBulkActions()
      {
          $bulkActions =  ['shortpixel-optimize' => __('Optimize','shortpixel-image-optimiser'),
          'shortpixel-restore' => __('Restore', 'shortpixel-image-optimiser'), 
          'shortpixel-lossy' => __( 'Re-optimize Lossy', 'shortpixel-image-optimiser' ), 
          'shortpixel-glossy' => __( 'Re-optimize Glossy', 'shortpixel-image-optimiser' ), 
          'shortpixel-lossless' => __( 'Re-optimize Lossless', 'shortpixel-image-optimiser' ),   
          'shortpixel-mark-completed' => __('Mark completed', 'shortpixel-image-optimiser'),
          ];

          array_walk($bulkActions, function ($text, $name) {
              echo '<option value="' . esc_attr($name) . '">' . esc_html($text) . '</option>';
          });
      }


      /**
       * Renders a single column heading cell, optionally as a sortable link.
       *
       * When $heading['sortable'] is true, wraps the title in an anchor that
       * toggles sort direction on repeated clicks and adds a 'sorted asc/desc'
       * indicator class. Non-sortable headings are returned as plain text.
       *
       * @param array<string, mixed> $heading Heading definition from getHeadings().
       * @return string HTML string for the heading cell content.
       */
      protected function getDisplayHeading($heading)
      {
          $output = '';
          $defaults = array('title' => '', 'sortable' => false);

          $heading = wp_parse_args($heading, $defaults);
          $title = $heading['title'];

          if ($heading['sortable'])
          {
              //$current_order = isset($_GET['order']) ? $current_order : false;
              //$current_orderby = isset($_GET['orderby']) ? $current_orderby : false;

              $sorturl = add_query_arg('orderby', $heading['orderby'] );
              $sorted = '';

              if ($this->orderby == $heading['orderby'])
              {
                if ($this->order == 'desc')
                {
                  $sorturl = add_query_arg('order', 'asc', $sorturl);
                  $sorted = 'sorted desc';
                }
                else
                {
                  $sorturl = add_query_arg('order', 'desc', $sorturl);
                  $sorted = 'sorted asc';
                }
              }
              else
              {
                $sorturl = add_query_arg('order', 'asc', $sorturl);
              }
              $output = '<a href="' . esc_url($sorturl) . '"><span>' . esc_html($title) . '</span><span class="sorting-indicator '. esc_attr($sorted) . '">&nbsp;</span></a>';
          }
          else
          {
            $output = $title;
          }

          return $output;
      }

      /**
       * Returns a formatted date string for a custom-media item's most relevant timestamp.
       *
       * Prefers the tsOptimized timestamp when non-zero; falls back to tsAdded.
       * Uses UiHelper::formatDate() to produce a human-readable string.
       *
       * @param \ShortPixel\Model\Image\CustomImageModel $item The image model.
       * @return string Formatted date string.
       */
      protected function getDisplayDate($item)
      {
        if ($item->getMeta('tsOptimized') > 0)
          $timestamp = $item->getMeta('tsOptimized');
        else
          $timestamp = $item->getMeta('tsAdded');

        $date = new \DateTime();
        $date->setTimestamp($timestamp);

        $display_date = UiHelper::formatDate($date);

         return $display_date;
      }

}
