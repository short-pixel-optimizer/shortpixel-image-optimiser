<?php
namespace ShortPixel;

  /*** Logger class
  *
  * Class uses the debug data model for keeping log entries.
  */
 class ShortPixelLogger extends shortPixelController
 {
   static protected $instance = null;
   protected $start_time;

   protected $is_active = false;
   protected $is_manual_request = false;
   protected $show_debug_view = false;

   protected $items = array();
   protected $logPath = false;

   protected $logLevel;
   protected $format = "[ %%time%% ] %%color%% %%level%% %%color_end%% \t %%message%%  \t ( %%time_passed%% )";
   protected $format_data = "\t %%data%% ";

   protected $template = 'view-debug-box';

   public function __construct()
   {
      $this->start_time = microtime(true);
      $this->logLevel = DebugItem::LEVEL_WARN;

      if (isset($_REQUEST['SHORTPIXEL_DEBUG']))
      {
        $this->is_manual_request = true;
        $this->is_active = true;

        if ($_REQUEST['SHORTPIXEL_DEBUG'] === 'true')
        {
          $this->logLevel = DebugItem::LEVEL_INFO;
        }
        else {
          $this->logLevel = intval($_REQUEST['SHORTPIXEL_DEBUG']);
        }

      }

      if ( (defined('SHORTPIXEL_DEBUG') && SHORTPIXEL_DEBUG > 0) )
      {
            $this->is_active = true;
            if (SHORTPIXEL_DEBUG === true)
              $this->logLevel = DebugItem::LEVEL_INFO;
            else {
              $this->logLevel = intval(SHORTPIXEL_DEBUG);
            }
      }

      if (defined('SHORTPIXEL_DEBUG_TARGET') && SHORTPIXEL_DEBUG_TARGET || $this->is_manual_request)
      {
          $this->logPath = SHORTPIXEL_BACKUP_FOLDER . "/shortpixel_log";
      }

      $user_is_administrator = (current_user_can('manage_options')) ? true : false;

      if ($this->is_active && $this->is_manual_request && $user_is_administrator )
      {
          $this->layout = new \stdClass;
          $this->layout->logLink = SHORTPIXEL_BACKUP_URL . "/shortpixel_log";

          add_action('admin_footer', array($this, 'loadView'));
      }
   }

   public static function getInstance()
   {
      if ( self::$instance === null)
      {
          self::$instance = new ShortPixelLogger();
      }

      return self::$instance;
   }

   protected static function addLog($message, $level, $data = array())
   {
     $log = self::getInstance();

     // don't log anything too low.
     if ($log->logLevel < $level)
     {
       return;
     }

     $arg = array();
     $args['level'] = $level;
     $args['data'] = $data;

     $newItem = new \ShortPixel\DebugItem($message, $args);
     $log->items[] = $newItem;

      if ($log->is_active)
      {
          $log->write($newItem);
      }
   }

   protected function write($debugItem, $mode = 'file')
   {
      $items = $debugItem->getForFormat();
      $items['time_passed'] =  round ( ($items['time'] - $this->start_time), 5);
      $items['time'] =  date('Y-m-d H:i:s', $items['time'] );

      $line = $this->formatLine($items);

      if ($this->logPath)
      {
        file_put_contents($this->logPath,$line, FILE_APPEND);
      }
      else {
        error_log($line);
      }
   }

   protected function formatLine($args = array() )
   {
      $line= $this->format;
      foreach($args as $key => $value)
      {
        if (! is_array($value) && ! is_object($value))
          $line = str_replace('%%' . $key . '%%', $value, $line);
      }

      $line .= PHP_EOL;

      if (isset($args['data']))
      {
        foreach($args['data'] as $item)
        {
            $line .= $item . PHP_EOL;
        }
      }

      return $line;
   }

   protected function setLogLevel($level)
   {
     $this->logLevel = $level;
   }

   protected function getEnv($name)
   {
     if (isset($this->{$name}))
     {
       return $this->{$name};
     }
     else {
       return false;
     }
   }

   public static function addError($message, $args = array())
   {
      $level = DebugItem::LEVEL_ERROR;
      static::addLog($message, $level, $args);
   }
   public static function addWarn($message, $args = array())
   {
     $level = DebugItem::LEVEL_WARN;
     static::addLog($message, $level, $args);
   }
   public static function addInfo($message, $args = array())
   {
     $level = DebugItem::LEVEL_INFO;
     static::addLog($message, $level, $args);
   }
   public static function addDebug($message, $args = array())
   {
     $level = DebugItem::LEVEL_DEBUG;
     static::addLog($message, $level, $args);
   }

   public static function logLevel($level)
   {
      $log = self::getInstance();
      $log->setLogLevel($level);
   }

   public static function isManualDebug()
   {
        $log = self::getInstance();
        return $log->getEnv('is_manual_request');
   }

   public static function getLogPath()
   {
     $log = self::getInstance();
     return $log->getEnv('logPath');
   }


 } // class debugController
