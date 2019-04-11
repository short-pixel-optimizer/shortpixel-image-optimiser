<?php

class WpShortPixelDb implements ShortPixelDb {

    protected $prefix;
    protected $defaultShowErrors;

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
        if(function_exists("is_multisite") && is_multisite()) {
            $sites = function_exists("get_sites") ? get_sites() : wp_get_sites();
            foreach($sites as $site) {
                if(!is_array($site)) {
                    $site = (array)$site;
                }
                $prefix = $wpdb->get_blog_prefix($site['blog_id']);
                $spMetaDao = new ShortPixelCustomMetaDao(new WpShortPixelDb($prefix));
                $spMetaDao->createUpdateShortPixelTables();
            }

        } else {
            $spMetaDao = new ShortPixelCustomMetaDao(new WpShortPixelDb());
            $spMetaDao->createUpdateShortPixelTables();
        }
    }

    public function getCharsetCollate() {
        global $wpdb;
        return $wpdb->get_charset_collate();
    }

    public function getPrefix() {
        global $wpdb;
        return $this->prefix ? $this->prefix : $wpdb->prefix;
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
        return $wpdb->get_results($sql);
    }

    public function insert($table, $params, $format = null) {
        global $wpdb;
        $wpdb->insert($table, $params, $format);
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
