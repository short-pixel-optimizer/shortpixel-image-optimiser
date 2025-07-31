<?php 
namespace ShortPixel\Model\Queue;

if (!defined('ABSPATH')) {
   exit; // Exit if accessed directly.
}

// Handler for QueueItem Data stuff

use ShortPixel\Helper\UtilHelper as UtilHelper;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class QueueItemData
{

        protected $urls; 
        protected $url; 
        protected $forceExclusion; 
        protected $action; 
        protected $next_actions; // multiple actions requeue mechanism. 
        protected $next_keepdata; // keep this data for next actions
        protected $smartcrop; 
        protected $remote_id; // for Ai 
        protected $returndatalist;
        protected $paramlist; 
        protected $files; 
        protected $flags; 
        protected $compressionType; 
        protected $compressionTypeRequested;
        protected $tries;
        protected $block;
        protected $counts;
        protected $queue_list_order;  // optional from Queue class, the place of the queue. This might prevent 'next-action' to end up way at the bottom. 
        

        public function __construct()
        {
             
        }

        public function __get($name)
        {
            if (property_exists($this, $name))
            {
                $value = $this->$name; 

                // Validation 
                switch($name)
                {
                    case 'flags': 
                        if (! is_array($value))
                        {
                             $value = []; 
                        }
                    break; 
                }

                return $value;
            }
            else
            {
                Log::addWarn('QueueItemData Field requested not foudn: ' . $name);
            }
            return null;
        }

        public function __set($name, $value)
        {
            if (property_exists($this, $name))
            {

                 $this->$name = $value; 
            }             
            else
            {
                 Log::addWarn('QueueItemData Field not exists - ' . $name);
            }
            
        }

        public function remove($name)
        {
              if (property_exists($this, $name))
              {
                 $this->$name = null;
              }
        }

        public function toObject()
        {
             $vars = get_object_vars($this);
             $vars = array_filter($vars, ['\ShortPixel\Helper\UtilHelper','arrayFilterNullValues']);
             return (object) $vars; 
            
        }

        public function hasAction($action)
        {
            if (is_array($this->next_actions))
            {
                $actions = array_merge([$this->action], $this->next_actions);
            }
            else
            {
                $actions = [$this->action];
            }

            if (in_array($action, $actions))
            {
                 return true; 
            }
            else
            {
                 return false; 
            }

        }

           /**  Add an action to be performed after current action.  
            * 
            * Note Doesn't save anything! 
            * @param string Action - name of the action
            */
        public function addNextAction($action)
        {   
            // @todo This should also incorporate keep_args -per next action-
            if (false === is_null($this->next_actions))
            {
                $this->next_actions = array_merge($this->next_actions, [$action]);
            }
            else 
            {
                $this->next_actions = [$action];
            }

        }

        public function hasNextAction()
        {
             if (! is_null($this->next_actions) && count($this->next_actions) > 0)
             {
                 return true; 
             }

        }

        public function popNextAction()
        {
            $next_action = null; 

            if (! is_null($this->next_actions) && count($this->next_actions) > 0)
            {
                 $next_action = array_shift($this->next_actions);

            }

            return $next_action; 
        }

        public function addKeepDataArgs($args)
        {
             if (! is_array($args))
             {
                $args = [$args];
             }
             if (is_null($this->next_keepdata))
             {
                 $this->next_keepdata = $args; 
             }
             else
             {
                $this->next_keepdata = array_merge($this->next_keepdata, $args);
             }

        }

        public function getKeepDataArgs()
        {
            $args = []; 

            if (! is_array($this->next_keepdata) || count($this->next_keepdata) === 0)
            {
                return $args; 
            } 

            foreach($this->next_keepdata as $name => $value)
            {
                  // Only arg parsed, take value from this data. 
                  if (is_numeric($name))
                  {
                     if (property_exists($this, $value) && false === is_null($this->$value))
                     {
                      $args[$value] = $this->$value;                       
                     }
                  }
                  elseif (false === is_null($value))
                  {
                      $args[$name]  = $value; 
                  }
            }

            return $args;
        }

        

} // class 