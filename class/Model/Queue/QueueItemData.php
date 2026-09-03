<?php 
namespace ShortPixel\Model\Queue;

if (!defined('ABSPATH')) {
   exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Per-item input payload attached to a QueueItem for the duration of
 * processing.
 *
 * Companion to QueueItemResult: this side holds the *request* state (URLs,
 * parameters, the action to perform, retry counters), while QueueItemResult
 * holds the *response* state. Magic __get / __set + a fixed schema stop
 * arbitrary keys from being smuggled in, and toObject() strips unset fields
 * so persistence stays compact.
 *
 * The "next actions" trio (`addNextAction` / `hasNextAction` / `popNextAction`)
 * implements a FIFO of follow-up actions so a single queue slot can chain
 * work — e.g. "convert then optimize" gets scheduled as two actions on the
 * same item.
 *
 * @package ShortPixel\Model\Queue
 */
class QueueItemData
{
        /** @var array<string, string>|null URLs to be submitted to the API for this item, keyed by size name. */
        protected $urls;
        /** @var bool|null When true, user-configured exclusions are bypassed for this item (manual "process anyway"). */
        protected $forceExclusion;
        /** @var string|null The action currently being processed (e.g. 'optimize', 'restore', 'convert'). */
        protected $action;
        /** @var array<int, string>|null FIFO of actions to run after the current one — the requeue mechanism used to chain multi-step flows. */
        protected $next_actions;
        /** @var array<int|string, mixed>|null Data to preserve across the next action(s); see getKeepDataArgs(). */
        protected $next_keepdata;
        /** @var bool|null Smartcrop override for this item, when different from the global setting. */
        protected $smartcrop;
        /** @var string|int|null Remote reference id from an AI service, used to correlate follow-up requests. */
        protected $remote_id;
        /** @var array|null Structured return data that should be echoed back to the caller untouched. */
        protected $returndatalist;
        /** @var array|null Parameter list handed to the ShortPixel API for this item. */
        protected $paramlist;
        /** @var array|null Files array produced by the optimizer pipeline (typically main + thumbnails). */
        protected $files;
        /**
         * @var array<int, mixed>|null Free-form flags array. NOTE: __get coerces
         *      this field to [] when it is not an array so consumers can foreach
         *      unconditionally.
         */
        protected $flags;
        /** @var int|null Compression type used for this item (see ImageModel::COMPRESSION_*). */
        protected $compressionType;
        /** @var int|null When converting, the compression type to apply *after* the conversion step (e.g. lossless during conversion, lossy afterwards). */
        protected $compressionTypeRequested;
        /** @var int|null Retry counter — the queue drops items that exceed the configured limit to avoid hangs. */
        protected $tries;
        /** @var mixed Block indicator set while the item is being processed elsewhere so parallel workers skip it. */
        protected $block;
        /** @var \stdClass|null Ad-hoc counters used by the UI (built up via addCount()). */
        protected $counts;
        /** @var int|null Optional queue-position hint so re-queued items don't sink to the bottom. */
        protected $queue_list_order;
        /** @var bool|null True when the item was added by the upload hook, so recent-upload heuristics can apply. */
        protected $recent_upload;

        /**
         * Constructor.
         *
         * No-op — every field is left unset so toObject() can distinguish
         * "never assigned" from "explicitly null". Producers assign fields
         * directly via the magic mutator after construction.
         */
        public function __construct()
        {

        }

        /**
         * Magic accessor — returns the value of a declared field, or null
         * (with a warning log) when the field is unknown.
         *
         * Special case: `flags` is coerced to an empty array when not an
         * array, so callers can always foreach the result without a guard.
         *
         * @param string $name Field name.
         * @return mixed|null
         */
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

        /**
         * Magic mutator — assigns to a declared field, or logs a warning and
         * does nothing when the field is unknown. Prevents accidental
         * schema drift when producers evolve.
         *
         * @param string $name  Field name.
         * @param mixed  $value Value to assign.
         * @return void
         */
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

        /**
         * Reset a declared field back to null so toObject() will drop it.
         * Silently no-ops for unknown fields.
         *
         * @param string $name Field name to clear.
         * @return void
         */
        public function remove($name)
        {
              if (property_exists($this, $name))
              {
                 $this->$name = null;
              }
        }

        /**
         * Return a compact stdClass representation containing every
         * non-null field.
         *
         * Used when persisting the queue item so the DB row only carries
         * fields the item actually cares about. The class itself is not
         * serialised — only its data.
         *
         * @return object
         */
        public function toObject()
        {
             $vars = get_object_vars($this);
             $vars = array_filter($vars, ['\ShortPixel\Helper\UtilHelper','arrayFilterNullValues']);
             return (object) $vars;

        }

        /**
         * Whether the item is scheduled to process a given action — either
         * as the current action or anywhere in the next-actions queue.
         *
         * @param mixed $action Action name to look up (string in practice).
         * @return bool
         */
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

        /**
         * Append a follow-up action to the FIFO. Does not persist — the
         * caller is responsible for saving the queue item afterwards.
         *
         * @param string $action Action name to enqueue after the current one.
         * @return void
         *
         * @todo Also incorporate keep_args per next action so each stage
         *       can carry its own preserved data.
         */
        public function addNextAction($action)
        {
            if (false === is_null($this->next_actions))
            {
                $this->next_actions = array_merge($this->next_actions, [$action]);
            }
            else
            {
                $this->next_actions = [$action];
            }

        }

        /**
         * Whether the item has any queued follow-up actions.
         *
         * @return bool True when at least one next action is queued.
         */
        public function hasNextAction()
        {
             if (! is_null($this->next_actions) && count($this->next_actions) > 0)
             {
                 return true;
             }

             return false;
        }

        /**
         * Pop the next action off the FIFO (using array_shift, so it's
         * strictly first-in-first-out) and return it.
         *
         * @return string|null The next action, or null when the queue is empty.
         */
        public function popNextAction()
        {
            $next_action = null;

            if (! is_null($this->next_actions) && count($this->next_actions) > 0)
            {
                 $next_action = array_shift($this->next_actions);

            }

            return $next_action;
        }

        /**
         * Register data to be preserved when the current action completes
         * and the next one starts.
         *
         * Accepts either a bare property name (numeric key → the value is
         * the field name, and the current value of that field will be
         * captured at getKeepDataArgs() time) or a name/value pair (string
         * key → the value is used verbatim). A non-array $args is wrapped
         * so single-value calls are convenient. Repeated registrations of the
         * same entry are deduplicated (array_unique).
         *
         * @param mixed $args Preserved-data entries; see semantics above.
         * @return void
         */
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
                $this->next_keepdata = array_unique(array_merge($this->next_keepdata, $args));
             }

        }

        /**
         * Materialise the preserved-data payload as a name → value array,
         * ready to be applied to the next action.
         *
         * Semantics of the stored entries:
         *   - **Numeric key + string value** → look up the value as a
         *     property name on $this; if present and non-null, capture its
         *     current value under that name.
         *   - **String key + any non-null value** → keep as-is.
         *   - **Null values / unknown property lookups** → dropped.
         *
         * @return array<string, mixed>
         */
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

        /**
         * Merge counters into the ad-hoc `$counts` object, lazily creating
         * it on first use. Existing keys are overwritten.
         *
         * @param iterable<string, int|string> $new_count Counter values keyed by name.
         * @return void
         */
        public function addCount($new_count)
        {
             if (! is_object($this->counts))
             {
                 $this->counts = new \stdClass;
             }

             foreach($new_count as $name => $value)
             {
                 $this->counts->{$name} = $value;
             }

        }

        

} // class 