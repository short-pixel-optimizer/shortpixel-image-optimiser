<?php
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

class WpShortPixelDb implements ShortPixelDb {

    protected $prefix;
    protected $defaultShowErrors;

    // mostly unimplemented.
    const QTYPE_INSERT = 1;
    const QTYPE_DELETE = 2;
    const QTYPE_UPDATE = 3;
    const QTYPE_QUERY = 4;

    public function __construct($prefix = null) {
        $this->prefix = $prefix;
    }

    public static function createUpdateSchema($tableDefinitions) {
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        $res = array();
        foreach($tableDefinitions as $tableDef) {
            array_merge($res, dbDelta( $tableDef ));
        }
        return $res;
    }

    public static function checkCustomTables() {
        global $wpdb;

        /*$slug = \wpSPIO()->env()->getRelativePluginSlug();
        $network_active = \wpSPIO()->env()->is_multisite && function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($slug) ? true : false;

        if($network_active)
        {
          if (! function_exists("get_sites") )
          { exit('get_sites fail'); return null;  }

            $sites = get_sites();
            foreach($sites as $site) {
                  $site_id = $site->blog_id;
                  $prefix = $wpdb->get_blog_prefix($site_id);


                  $spMetaDao = new ShortPixelCustomMetaDao(new WpShortPixelDb($prefix));
                  $spMetaDao->createUpdateShortPixelTables();
            }

        } else { */

      /*  if (! $spMetaDao->tablesExist())
        {

        } */
        /* **** **** **** **** **** **** **** **** **** ***** **** **** **** ****
        * Check why database is not created like this.
        */
        $spMetaDao = new ShortPixelCustomMetaDao(new WpShortPixelDb());

        $spMetaDao->createUpdateShortPixelTables();
        //}
    }

    private static function activeOnBlog($site, $slug)
    {
      $option = get_blog_option($site, 'active_plugins');

      var_dump($option);
      foreach($option as $active_slug)
      {
         if ($active_slug == $slug)
          return true;
      }

      return false;
    }

    public function getCharsetCollate() {
        global $wpdb;
        return $wpdb->get_charset_collate();
    }

    public function getPrefix() {
        global $wpdb;
      //  return $this->prefix ? $this->prefix : $wpdb->prefix;
       return $wpdb->prefix;
    }

    public function getDbName() {
        global $wpdb;
        return $wpdb->dbname;
    }

    public function query($sql, $params = false) {
        global $wpdb;
        if($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        $result = $wpdb->get_results($sql);

        if (count($result) == 0 && strlen($wpdb->last_error) > 0)
        {
           $this->handleError(self::QTYPE_QUERY);
        }

        return $result;
    }

    public function insert($table, $params, $format = null) {
        global $wpdb;

        $num_inserted = $wpdb->insert($table, $params, $format);
        if ($num_inserted === false)
        {
            $this->handleError(self::QTYPE_INSERT);
            return false;
        }

        return $wpdb->insert_id;
    }

    public function update($table, $params, $where, $format = null, $where_format = null)
    {
      global $wpdb;
      $updated = $wpdb->update($table, $params, $where, $format, $where_format);
      return $updated;

    }

    public function prepare($query, $args) {
        global $wpdb;
        return $wpdb->prepare($query, $args);
    }

    public function handleError($error_type)
    {
        global $wpdb;

        Log::addError('WP Database error: ' . $wpdb->last_error, $wpdb->last_query );
        self::checkCustomTables(); // on error, test if tables are fine.

        if (strlen($wpdb->last_error) > 0)
        {    $wpdb->last_error = ''; }

        switch($error_type)
        {
            case self::QTYPE_INSERT:
              Notice::addError('Shortpixel tried to run a database query, but it failed. See the logs for details', 'shortpixel-image-optimiser');
            break;
        }

    }

    public function hideErrors() {
        global $wpdb;
        $this->defaultShowErrors = $wpdb->show_errors;
        $wpdb->show_errors = false;
    }

    public function restoreErrors() {
        global $wpdb;
        $wpdb->show_errors = $this->defaultShowErrors;
    }
}
