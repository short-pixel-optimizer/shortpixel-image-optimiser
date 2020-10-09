<?php

namespace ShortPixel\Controller;

// Class for containing all Ajax Related Actions.
class AjaxController
{

    private static $instance;

    public static function getInstance()
    {
       if (is_null(self::$instance))
         self::$instance = new AjaxController();

      return self::$instance;
    }


    public function addItem()
    {
          $id = intval($_POST['id']);
          $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

          $json = $this->addItemToQueue($id, $type);

          $this->send($json);
    }

    public function processQueue()
    {

        if (isset($_POST['bulk-secret']))
        {
          $secret = sanitize_text_field($_POST['bulk-secret']);
          $cacheControl = new \ShortPixel\Controller\CacheController();
          $cachedObj = $cacheControl->getItem('bulk-secret');

          if (! $cachedObj->exists())
          {
             $cachedObj->setValue($secret);
             $cachedObj->setExpires(3 * MINUTE_IN_SECONDS);
             $cacheControl->storeItemObject($cachedObj);
          }
        }

        $control = OptimizeController::getInstance();
        $result = $control->processQueue();


        $this->send($result);
    }

    public function restoreItem()
    {
      $id = intval($_POST['id']);
      $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

      $control = OptimizeController::getInstance();
      $json = $this->restoreItem($id, $type);

      $this->send($json);
    }

    public function createBulk()
    {

    }

    protected function send($json)
    {
        wp_send_json($json);
        exit();
    }


    /** Generate the action output for an item, via UIHelper */
    protected function getActions($id)
    {

    }


}
