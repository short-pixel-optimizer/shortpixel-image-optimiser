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

// Future contoller for the edit media metabox view.
class OtherMediaViewController extends \ShortPixel\ViewController
{
      //$this->model = new
      protected $template = 'view-other-media';

			protected static $instance;

      // Pagination .
      protected $items_per_page = 20;
      protected $currentPage = 1;
      protected $total_items = 0;
      protected $order;
      protected $orderby;
      protected $search;

			protected $show_hidden = false;
			protected $has_hidden_items = false;

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
        $this->search =  (isset($_GET["s"]) && strlen($_GET["s"]) > 0)  ? sanitize_text_field( wp_unslash($_GET['s'])) : false;
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
				$this->show_hidden = isset($_GET['show_hidden']) ? sanitize_text_field(wp_unslash($_GET['show_hidden'])) : false;

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
              /* 'actions' => array('title' => __('Actions', 'shortpixel-image-optimiser'),
                                 'sortable' => false,
                            ), */
        );

        $keyControl = ApiKeyController::getInstance();
        if (! $keyControl->keyIsVerified())
        {
            $headings['actions']['title']  = '';
        }

        return $headings;
      }

      protected function getItems()
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
            Notices::addWarning(sprintf(__('Some images were missing. They have been removed from the Custom Media overview : %s %s'),
                '<BR>', implode('<BR>', $removed)));
          }

          return $items;
      }

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

      /* Check which folders are in result, and load them. */
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


          $sql  .= ($this->orderby ? " ORDER BY " . $this->orderby . " " . $this->order . " " : "")
                  . " LIMIT " . $this->items_per_page . " OFFSET " . ($page - 1) * $this->items_per_page;


          $results = $wpdb->get_results($sql);
          return $results;
      }

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
                        esc_html__( 'First page' ),
                        '&laquo;'
                    );
                }

            if ( $disable_prev ) {
                $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
            } else {
                $page_links[] = sprintf(
                    "<a class='prev-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                    esc_url( add_query_arg( 'paged', max( 1, $current - 1 ), $current_url ) ),
                    esc_html__( 'Previous page' ),
                    '&lsaquo;'
                );
            }

            $html_current_page = sprintf(
                "%s<input class='current-page' id='current-page-selector' type='text' name='paged' value='%s' size='%d' aria-describedby='table-paging' /><span class='tablenav-paging-text'>",
                '<label for="current-page-selector" class="screen-reader-text">' . esc_html__( 'Current Page' ) . '</label>',
                $current,
                strlen( $pages )
            );

            $html_total_pages = sprintf( "<span class='total-pages'>%s</span>", number_format_i18n( $pages ) );
            $page_links[]     = $total_pages_before . sprintf(
                /* translators: 1: Current page, 2: Total pages. */
                _x( '%1$s of %2$s', 'paging' ),
                $html_current_page,
                $html_total_pages
            ) . $total_pages_after;

            if ( $disable_next ) {
                $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
            } else {
                $page_links[] = sprintf(
                    "<a class='next-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                    esc_url( add_query_arg( 'paged', min( $pages, $current + 1 ), $current_url ) ),
                    __( 'Next page' ),
                    '&rsaquo;'
                );
            }

            if ( $disable_last ) {
                $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
            } else {
                $page_links[] = sprintf(
                    "<a class='last-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                    esc_url( add_query_arg( 'paged', $pages, $current_url ) ),
                    __( 'Last page' ),
                    '&raquo;'
                );
            }

            $output .= "\n<span class='pagination-links'>" . join( "\n", $page_links ) . '</span>';
            $output .= "</form>";


          return $output;
      }

      /** Actions to list under the Image row
      * @param $item CustomImageModel
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

			// Function to sync output exactly with Media Library functions for consistency
			public function doActionColumn($item)
			{
          ?>
					<div id='sp-msg-<?php echo esc_attr($item->get('id')) ?>'  class='sp-column-info'><?php
							$this->printItemActions($item);

            echo "<div>" .  UiHelper::getStatusText($item) . "</div>";
           ?>
         </div> <!-- sp-column-info -->
        <?php
			}

      // Use for view, also for renderItemView
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

      public function printFilter()
      {
            $status   = filter_input(INPUT_GET, 'custom-status', FILTER_UNSAFE_RAW );

            $options = array(
                'all' => __('Any ShortPixel State', 'shortpixel-image-optimiser'),
                'optimized' => __('Optimized', 'shortpixel-image-optimiser'),
                'unoptimized' => __('Unoptimized', 'shortpixel-image-optimiser'),
                'prevented' => __('Optimization Error', 'shortpixer-image-optimiser'),

            );

            echo  "<select name='custom-status' id='status'>\n";
            foreach($options as $optname => $optval)
            {
                $selected = ($status == $optname) ? esc_attr('selected') : '';
                echo "<option value='". esc_attr($optname) . "' $selected >" . esc_html($optval) . "</option>\n";
            }
            echo "</select>";

      }


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
