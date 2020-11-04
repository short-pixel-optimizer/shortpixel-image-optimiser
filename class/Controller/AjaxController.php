<?php

namespace ShortPixel\Controller;


use ShortPixel\Controller\View\ListMediaViewController as ListMediaViewController;

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

    // Support for JS Processor
    public function getProcessorKey()
    {
      // Get a Secret Key.
      $cacheControl = new CacheController();
      $bulkSecret = $cacheControl->getItem('bulk-secret');

      $secretKey = $bulkSecret->getValue();
      if (is_null($secretKey) || strlen($secretKey) == 0)
        $secretKey = false;


      return $secretKey;
    }

    public function removeProcessorKey()
    {
        $cacheControl->deleteItem('bulk-secret');

        $json = new \stdClass;
        $json->status = 0;
        $this->send($json);

    }

    public function getItemView()
    {
          $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';
          $id = isset($_POST['id']) ? intval($_POST['id']) : false;

          if ($id > 0)
          {
             if ($type == 'media')
             {
               ob_start();
                  $control = new ListMediaViewController();
                  $control->doColumn('wp-shortPixel', $id);
                $result = ob_get_contents();
                ob_end_clean();
             }
          }

          $json = new \stdClass;
          $json->$type = new \stdClass;
          $json->$type->result = $result;
          $json->$type->id = $id;
          $json->$type->results = null;
          $json->$type->is_error = false;
          $json->status = true;

          $this->send($json);
    }


    public function addItem()
    {
          $id = intval($_POST['id']);
          $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

          $control = OptimizeController::getInstance();
          $json = $control->addItemToQueue($id, $type);

          ResponseController::add()->withMessage('TESTING');
          $this->send($json);
    }

    public function processQueue()
    {

        if (isset($_POST['bulk-secret']))
        {
          $secret = sanitize_text_field($_POST['bulk-secret']);
          $cacheControl = new CacheController();
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

      //  $result = $this->tempFakeResult();

        $this->send($result);
    }

    public function tempFakeResult()
    {
      // Result when image is done ( faked )
       $resultObj = new \stdClass;
       $resultObj->compressionType = null;
       $resultObj->id = 1893;
       $resultObj->urls = array("http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/IMG_20160417_162622.jpg?ver=1562769552", "http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/IMG_20160417_162622-150x150.jpg?ver=1562769552", "http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/IMG_20160417_162622-400x300.jpg?ver=1562769552", "http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/IMG_20160417_162622-768x576.jpg?ver=1562769552", "http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/IMG_20160417_162622-1024x768.jpg?ver=1562769552", "http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/IMG_20160417_162622-1568x1176.jpg?ver=1562769552", "http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/IMG_20160417_162622-300x300.jpg?ver=1562769552", "http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/IMG_20160417_162622-450x338.jpg?ver=1562769552", "http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/IMG_20160417_162622-100x100.jpg?ver=1562769552", "http://shortpixel.weblogmechanic.com/wp-content/uploads/2019/07/IMG_20160417_162622-300x300.jpg?ver=1562769552",
    );
      $resultObj->result = new \stdClass;
      $resultObj->result->status = 2;
      $resultObj->result->message = 'Image 32 optimized';
      $resultObj->result->is_error = false;
      $resultObj->result->is_done = true;

      $resultObj->improvements = new \stdClass;
      $resultObj->improvements->main = array('80.04', 113952);
      $resultObj->improvements->thumbnails = array(
          'main' => array(99, 666),
          'large' => array('25.05', '13235948'),
      );
      $resultObj->has_error = false;
      $resultObj->message = "Fetched 1 items";

      $statsObj = new \stdClass;
      $statsObj->in_queue = 10;
      $statsObj->errors = 0;
      $statsObj->done = 54;
      $statsObj->total = 64;


        $media = new \stdClass;
        $media->has_error = false;
        $media->message = 'temp message';
        $media->result = null;
        $media->results = array(0 => $resultObj,
        );

        $media->stats = $statsObj;
        $ar = array(
            'customer' => new \stdClass,
            'media' => $media,
        );

        return $ar;
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
        $json->responses = ResponseController::getAll();

        $callback = isset($_POST['callback']) ? sanitize_text_field($_POST['callback']) : false;
        if ($callback)
          $json->callback = $callback; // which type of request we just fullfilled ( response processing )

        wp_send_json($json);
        exit();
    }


    /** Generate the action output for an item, via UIHelper */
    protected function getActions($id)
    {

    }


}
