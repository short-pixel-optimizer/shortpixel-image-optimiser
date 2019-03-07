<?php

if( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}        

class ShortPixelListTable extends WP_List_Table {

    protected $ctrl;
    protected $spMetaDao;
    protected $hasNextGen;
    
    public function __construct($ctrl, $spMetaDao, $hasNextGen) {
        parent::__construct( array(
            'singular' => __('Image','shortpixel-image-optimiser'), //singular name of the listed records
            'plural'   => __('Images','shortpixel-image-optimiser'), //plural name of the listed records
            'ajax'     => false //should this table support ajax?
        ));
        $this->ctrl = $ctrl;
        $this->spMetaDao = $spMetaDao;
        $this->hasNextGen = $hasNextGen;
    }

    // define the columns to display, the syntax is 'internal name' => 'display name'
    function get_columns() {
        $columns = array();

        //pe viitor. $columns['cb'] = '<input type="checkbox" />';
        $columns['name'] = __('Filename','shortpixel-image-optimiser');
        $columns['folder'] = __('Folder','shortpixel-image-optimiser');
        $columns['media_type'] = __('Type','shortpixel-image-optimiser');
        $columns['status'] = __('Status','shortpixel-image-optimiser');
        $columns['options'] = __('Options','shortpixel-image-optimiser');
        //$columns = apply_filters('shortpixel_list_columns', $columns);

        return $columns;
    }

    function column_cb( $item ) {
        return sprintf('<input type="checkbox" name="bulk-optimize[]" value="%s" />', $item->id);
    }
    
    function column_default( $item, $column_name ) {
        switch( $column_name ) { 
            case 'name':
                $title = '<a href="' . ShortPixelMetaFacade::pathToWebPath($item->folder) . '" title="'.$item->folder.'" target="_blank"><strong>' . $item->name . '</strong></a>';

                $url = ShortPixelMetaFacade::pathToWebPath($item->folder);
                $actions = array(
                    'optimize' => sprintf( '<a href="?page=%s&action=%s&image=%s&_wpnonce=%s&noheader=true">%s</a>', 
                            esc_attr( $_REQUEST['page'] ), 'optimize', absint( $item->id ), wp_create_nonce( 'sp_optimize_image' ), 
                            __('Optimize','shortpixel-image-optimiser')),
                    'retry' => sprintf( '<a href="?page=%s&action=%s&image=%s&_wpnonce=%s&noheader=true">%s</a>', 
                            esc_attr( $_REQUEST['page'] ), 'optimize', absint( $item->id ), wp_create_nonce( 'sp_optimize_image' ), 
                            __('Retry','shortpixel-image-optimiser')),
                    'restore' => sprintf( '<a href="?page=%s&action=%s&image=%s&_wpnonce=%s&noheader=true">%s</a>', 
                            esc_attr( $_REQUEST['page'] ), 'restore', absint( $item->id ), wp_create_nonce( 'sp_restore_image' ), 
                            __('Restore','shortpixel-image-optimiser')),
                    'redolossless' => sprintf( '<a href="?page=%s&action=%s&type=%s&image=%s&_wpnonce=%s&noheader=true">%s</a>', 
                            esc_attr( $_REQUEST['page'] ), 'redo', 'lossless', absint( $item->id ), wp_create_nonce( 'sp_redo_image' ), 
                            __('Re-optimize lossless','shortpixel-image-optimiser')),
                    'redolossy' => sprintf( '<a href="?page=%s&action=%s&type=%s&image=%s&_wpnonce=%s&noheader=true">%s</a>', 
                            esc_attr( $_REQUEST['page'] ), 'redo', 'lossy', absint( $item->id ), wp_create_nonce( 'sp_redo_image' ), 
                            __('Re-optimize lossy','shortpixel-image-optimiser')),
                    'redoglossy' => sprintf( '<a href="?page=%s&action=%s&type=%s&image=%s&_wpnonce=%s&noheader=true">%s</a>', 
                            esc_attr( $_REQUEST['page'] ), 'redo', 'glossy', absint( $item->id ), wp_create_nonce( 'sp_redo_image' ), 
                            __('Re-optimize glossy','shortpixel-image-optimiser')),
                    'quota' => sprintf( '<a href="?page=%s&action=%s&image=%s&_wpnonce=%s&noheader=true">%s</a>', 
                            esc_attr( $_REQUEST['page'] ), 'quota', absint( $item->id ), wp_create_nonce( 'sp_check_quota' ), 
                            __('Check quota','shortpixel-image-optimiser')),
                    'view' => sprintf( '<a href="%s" target="_blank">%s</a>', $url, __('View','shortpixel-image-optimiser'))
                );
                $settings = $this->ctrl->getSettings();
                $actionsEnabled = array();
                if($settings->quotaExceeded) {
                    $actionsEnabled['quota'] = true;
                } elseif($item->status == 0 || $item->status == 1 || $item->status == 3 ) {
                    $actionsEnabled['optimize'] = true;
                } elseif($item->status == 2) {
                    $actionsEnabled['restore'] = true;
                    switch($item->compression_type) {
                        case 2:
                            $actionsEnabled['redolossy'] = $actionsEnabled['redolossless'] = true;
                            break;
                        case 1:
                            $actionsEnabled['redoglossy'] = $actionsEnabled['redolossless'] = true;
                            break;
                        default:
                            $actionsEnabled['redolossy'] = $actionsEnabled['redoglossy'] = true;
                    }
                    //$actionsEnabled['redo'.($item->compression_type == 1 ? "lossless" : "lossy")] = true;
                } elseif($item->status == 3 || $item->status < 0) {
                    $actionsEnabled['retry'] = true;
                }
                $actionsEnabled['view'] = true;
                $title = $title . $this->row_actions($actions, false, $item->id, $actionsEnabled );
                return $title;
            case 'folder':
                return ShortPixelMetaFacade::pathToRootRelative($item->folder);
            case 'status':
                switch($item->status) {
                    case 3: $msg = __('Restored','shortpixel-image-optimiser');
                        break;
                    case 2: $msg = 0 + $item->message  == 0 
                            ? __('Bonus processing','shortpixel-image-optimiser') 
                            : __('Reduced by','shortpixel-image-optimiser') . " <strong>" . $item->message . "%</strong>" 
                              . (0 + $item->message < 5 ? "<br>" . __('Bonus processing','shortpixel-image-optimiser') . "." : "");
                        break;
                    case 1: $msg = "<img src=\"" . plugins_url( 'shortpixel-image-optimiser/res/img/loading.gif') . "\" class='sp-loading-small'>&nbsp;" 
                                   . __('Pending','shortpixel-image-optimiser');
                        break;
                    case 0: $msg = __('Waiting','shortpixel-image-optimiser');
                        break;
                    default:
                        if($item->status < 0) {
                            $msg = $item->message . "(" . __('code','shortpixel-image-optimiser') . ": " . $item->status . ")";
                        } else {
                            $msg = "<span style='display:none;'>" . $item->status . "</span>";
                        }
                }
                return "<div id='sp-cust-msg-C-" . $item->id . "'>" . $msg . "</div>";
                break;
            case 'options':
                return  __($item->compression_type == 2 ? 'Glossy' : ($item->compression_type == 1 ? 'Lossy' : 'Lossless'),'shortpixel-image-optimiser') 
                     . ($item->keep_exif == 1 ? "": ", " . __('Keep EXIF','shortpixel-image-optimiser')) 
                     . ($item->cmyk2rgb ? "": ", " . __('Preserve CMYK','shortpixel-image-optimiser'));
            case 'media_type':
                return $item->$column_name;
            default:
                return print_r( $item, true ) ; //Show the whole array for troubleshooting purposes
        }
    }
    
    public function no_items() {
        echo(__('No images avaliable. Go to <a href="options-general.php?page=wp-shortpixel#adv-settings">Advanced Settings</a> to configure additional folders to be optimized.','shortpixel-image-optimiser'));
    }
    
    /**
    * Columns to make sortable.
    *
    * @return array
    */
    public function get_sortable_columns() {
        $sortable_columns = array(
          'name' => array( 'name', true ),
          'folder' => array( 'folder', true ),
          'status' => array( 'status', false )
        );

        return $sortable_columns;
    }
    
    /**
     * Handles data query and filter, sorting, and pagination.
     */
    public function prepare_items() {

        $this->_column_headers = $this->get_column_info();
        
        $this->_column_headers[0] = $this->get_columns();

        /** Process actions */
        $this->process_actions();

        $perPage     = $this->get_items_per_page( 'images_per_page', 20 );
        $currentPage = $this->get_pagenum();
        $total_items  = $this->record_count();

        $this->set_pagination_args( array(
          'total_items' => $total_items, //WE have to calculate the total number of items
          'per_page'    => $perPage //WE have to determine how many items to show on a page
        ));
        
        $orderby = ( ! empty( $_GET['orderby'] ) ) ? $_GET['orderby'] : 'ts_added';
        // If no order, default to asc
        $order = ( ! empty($_GET['order'] ) ) ? $_GET['order'] : 'desc';
        
        $this->items = $this->spMetaDao->getPaginatedMetas($this->hasNextGen, $this->getFilter(), $perPage, $currentPage, $orderby, $order);
        return $this->items;
    }    
    
    protected function getFilter() {
        $filter = array();
        if(isset($_GET["s"]) && strlen($_GET["s"])) {
            $filter['path'] = (object)array("operator" => "like", "value" =>"'%" . esc_sql($_GET["s"]) . "%'");
        }
        return $filter;
    }
    
    public function record_count() {
        return $this->spMetaDao->getCustomMetaCount($this->getFilter());
    }
    
    public function action_optimize_image( $id ) {
        $this->ctrl->optimizeCustomImage($id);
    }
    
    public function action_restore_image( $id ) {
        $this->ctrl->doCustomRestore($id);
    }
    
    public function action_redo_image( $id, $type = false ) {
        $this->ctrl->redo('C-' . $id, $type);
    }
    
    public function process_actions() {

        //Detect when a bulk action is being triggered...
        $nonce = isset($_REQUEST['_wpnonce']) ? esc_attr($_REQUEST['_wpnonce']) : false;
        switch($this->current_action()) {
            case 'optimize':
                if (!wp_verify_nonce($nonce, 'sp_optimize_image')) {
                    die('Error.');
                } else {
                    $this->action_optimize_image(absint($_GET['image']));
                    wp_redirect(esc_url(remove_query_arg(array('action', 'image', '_wpnonce'))));
                    exit;
                }
                break;
            case 'restore':
                if (!wp_verify_nonce($nonce, 'sp_restore_image')) {
                    die('Error.');
                } else {
                    $this->action_restore_image(absint($_GET['image']));
                    wp_redirect(esc_url(remove_query_arg(array('action', 'image', '_wpnonce'))));
                    exit;
                }
                break;
            case 'redo':
                if (!wp_verify_nonce($nonce, 'sp_redo_image')) {
                    die('Error.');
                } else {
                    $this->action_redo_image(absint($_GET['image']), $_GET['type']);
                    wp_redirect(esc_url(remove_query_arg(array('action', 'image', '_wpnonce'))));
                    exit;
                }
                break;
        }

        // If the delete bulk action is triggered
        if (( isset($_POST['action']) && $_POST['action'] == 'bulk-optimize' ) || ( isset($_POST['action2']) && $_POST['action2'] == 'bulk-optimize' )
        ) {

            $optimize_ids = esc_sql($_POST['bulk-optimize']);

            // loop over the array of record IDs and delete them
            foreach ($optimize_ids as $id) {
                $this->action_optimize_image($id);
            }

            wp_redirect(esc_url(add_query_arg()));
            exit;
        }
    }

    protected function row_actions($actions, $always_visible = false, $id = false, $actionsEnabled = false ) {
        if($id === false) {
            return parent::row_actions($actions, $always_visible);
        }
        $action_count = count( $actions );
        $i = 0;

        if ( !$action_count )
            return '';

        $out = '<div class="' . ( $always_visible ? 'row-actions visible' : 'row-actions' ) . '">';
        foreach ( $actions as $action => $link ) {
            ++$i;
            ( $i == $action_count ) ? $sep = '' : $sep = ' | ';
            $action_id = $action . "_" . $id;
            $display = (isset($actionsEnabled[$action])?"":" style='display:none;'");
            $out .= "<span id='$action_id' class='$action' $display>$link$sep</span>";
        }
        $out .= '</div>';

        $out .= '<button type="button" class="toggle-row"><span class="screen-reader-text">' . __( 'Show more details', 'shortpixel-image-optimiser' ) . '</span></button>';

        return $out;
    }
}
