<?php

namespace ShortPixel\Controller;


class OptimizeController
{
    protected static $instance;

    const PLUGIN_SLUG = 'SPIO';

    public function __construct()
    {

    }

    public function getInstance()
    {
       if ( is_null(self::$instance))
          self::$instance = new OptimizeController();

      return self::$instance;
    }

    public function optimize()
    {

    }

    public function ajax_optimize()
    {

    }


}
