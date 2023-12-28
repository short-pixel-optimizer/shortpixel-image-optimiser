<?php
namespace ShortPixel\Controller\View;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;
use ShortPixel\Helper\InstallHelper as InstallHelper;
use ShortPixel\Controller\OtherMediaController as OtherMediaController;


class OtherMediaFolderViewController extends \ShortPixel\ViewController
{

  protected $template = 'view-other-media-folder';

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
  protected $customFolderBase;

	private $controller;

  public function __construct()
  {
    parent::__construct();

    $fs = \wpSPIO()->filesystem();

		$this->controller = OtherMediaController::getInstance();

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

    $customFolderBase = $fs->getWPFileBase();
    $this->customFolderBase = $customFolderBase->getPath();

    $this->loadSettings();
  }

  /** Controller default action - overview */
  public function load()
  {
    //  $this->process_actions();
    //

      $this->view->items = $this->getItems();
  //    $this->view->folders = $this->getItemFolders($this->view->items);
      $this->view->headings = $this->getHeadings();
      $this->view->pagination = $this->getPagination();
      $this->view->filter = $this->getFilter();


//      $this->checkQueue();
      $this->loadView();
  }

  public function singleItemView($folderObj)
  {
    ob_start();
    $this->view->current_item = $folderObj;
    $this->loadView('custom/part-single-folder', false);
    $result = ob_get_contents();

    ob_end_clean();

    return $result;
  }

  protected function loadSettings()
  {

    $settings = \wpSPIO()->settings();
    $this->view->settings = new \stdclass;
    $this->view->settings->includeNextGen = $settings->includeNextGen;

    $this->view->title = __('Shortpixel Custom Folders', 'shortpixel-image-optimiser');
    $this->view->show_search = true;
    $this->view->has_filters = true;

  }

  protected function getRowActions($item)
  {

     $actions = array();

     $removeAction = array('remove' => array(
        'function' => 'window.ShortPixelProcessor.screen.StopMonitoringFolder(' . intval($item->get('id')) . ')',
        'type' => 'js',
        'text' => __('Stop Monitoring', 'shortpixel-image-optimiser'),
        'display' => 'inline',
     ));

     $refreshAction = array('refresh' => array(
        'function' => 'window.ShortPixelProcessor.screen.RefreshFolder(' . intval($item->get('id')) . ')',
        'type' => 'js',
        'text' => __('Refresh Folder', 'shortpixel-image-optimiser'),
        'display' => 'inline',
     ));

		 // @todo Get path of last one/two subdirectories and link to files page (?) or add a query for folder_id options.
		 $url = add_query_arg('part', 'files', $this->url);
		 $url = add_query_arg('folder_id', $item->get('id'), $url);

     $showFilesAction = array('showfiles' => array(
        'function' => esc_url($url),
        'type' => 'link',
        'text' => __('Show all Files', 'shortpixel-image-optimiser'),
        'display' => 'inline',
     ));

     $actions = array_merge($actions, $refreshAction, $removeAction, $showFilesAction);
//     $actions = array_merge($actions, );

     return $actions;
  }

	private function getItems($args = array())
	{
		  $results = $this->queryItems($args);
			$items = array();

			foreach($results as $index => $databaseObj)
			{
          $db_id = $databaseObj->id;
          $folderObj = $this->controller->getFolderByID($db_id);
					$items[$db_id] = $folderObj;

			}

      $this->total_items = $this->queryItems(array('limit' => -1, 'only_count' => true));
			return $items;
	}

  private function queryItems($args = array())
  {
    global $wpdb;

    $page = $this->currentPage;
    if ($page <= 0)
      $page = 1;

    $defaults = array(
        'id' => false,  // Get folder by Id
        'remove_hidden' => true, // Query only active folders
        'path' => false,
        'only_count' => false,
        'limit' => $this->items_per_page,
        'offset' => ($page - 1) * $this->items_per_page,
    );


    $filters = $this->getFilter();
    $args = wp_parse_args($args, $defaults);

    if (! $this->hasFoldersTable())
    {
      if ($args['only_count'])
         return 0;
      else
        return array();
    }
    $fs =  \wpSPIO()->fileSystem();

    if ($args['only_count'])
      $selector = 'count(id) as id';
    else
      $selector = '*';

    $sql = "SELECT " . $selector . "  FROM " . $wpdb->prefix . "shortpixel_folders WHERE 1=1 ";
    $prepare = array();
  //  $mask = array();

    if ($args['id'] !== false && $args['id'] > 0)
    {
        $sql .= ' AND id = %d';
        $prepare[] = $args['id'];

    }
    elseif($args['path'] !== false && strlen($args['path']) > 0)
    {
        $sql .= ' AND path = %s';
        $prepare[] = $args['path'];
    }

    if ($args['remove_hidden'])
    {
        $sql .= " AND status <> -1";
    }

    $sql .= ($this->orderby ? " ORDER BY " . $this->orderby . " " . $this->order . " " : "");

    if ($args['limit'] > 0)
    {
       $sql .=  " LIMIT " . intval($args['limit']) . " OFFSET " . intval($args['offset']);
    }


    if (count($prepare) > 0)
      $sql = $wpdb->prepare($sql, $prepare);

    if ($args['only_count'])
      $results = intval($wpdb->get_var($sql));
    else
      $results = $wpdb->get_results($sql);


    return $results;
  }

  protected function getHeadings()
  {
     $headings = array(

          'checkbox' => array('title' => '<input type="checkbox" name="select-all">',
                          'sortable' => false,
                          'orderby' => 'id',  // placeholder to allow sort on this.
                        ),
           'name' =>  array('title' => __('Folder Name', 'shortpixel-image-optimiser'),
                            'sortable' => true,
                            'orderby' => 'name',
                        ),
          'type' => array('title' => __('Type', 'shortpixel-image-optimiser'),
                            'sortable' => false,
                            'orderby' => 'path',
                        ),
           'files' =>   array('title' => __('Files', 'shortpixel-image-optimiser'),
                            'sortable' => false,
                            'orderby' => 'files',
                            'title_context' => __('Images in folder - optimized / unoptimized ','shortpixel-image-optimiser'),
                            ),
           'date' =>    array('title' => __('Last change', 'shortpixel-image-optimiser'),
                            'sortable' => true,
                            'orderby' => 'ts_updated',
                         ),
          /* Status is only yes, or nextgen. Already in the Type string.  Status use for messages */
           'status' => array('title' => __('Message', 'shortpixel-image-optimiser'),
                            'sortable' => false,
                            'orderby' => 'status',
                        ),


    );

		return $headings;
  }

    private function getPageArgs($args = array())
    {
      $defaults = array(
          'orderby' => $this->orderby,
          'order' => $this->order,
          's' => $this->search,
          'paged' => $this->currentPage,
          'part' => 'folders',
      );


      $page_args = array_filter(wp_parse_args($args, $defaults));
      return $page_args; // has url

    }

		// @todo duplicate of OtherMediaViewController which is not nice.
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

    protected function getFilter() {
        $filter = array();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
        $search = (isset($_GET['s'])) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        if(strlen($search) > 0) {
          // phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
            $filter['path'] = (object)array("operator" => "like", "value" =>"'%" . esc_sql($search) . "%'");
        }
        return $filter;
    }

    private function hasFoldersTable()
    {
      return InstallHelper::checkTableExists('shortpixel_folders');
    }


} // class
