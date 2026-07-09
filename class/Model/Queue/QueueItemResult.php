<?php 
namespace ShortPixel\Model\Queue;

if (!defined('ABSPATH')) {
   exit; // Exit if accessed directly.
}

use JsonSerializable;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Per-item result payload returned from the queue-processing pipeline.
 *
 * Wraps a fixed schema of protected fields behind magic __get / __set so
 * producers can only assign known keys — unknown reads and writes log a
 * warning instead of silently succeeding. Serialising for the JS response
 * (via JsonSerializable / forReturn()) strips any field that was never set,
 * so the client gets a compact object containing only what actually
 * happened.
 *
 * Populated by ApiController, QueueController and the various optimize /
 * background-processing paths.
 *
 * @package ShortPixel\Model\Queue
 */
class QueueItemResult implements JsonSerializable
{

   /** @var int Attachment or custom-image ID this result relates to. */
   protected $item_id;
   /** @var bool True once the whole item has finished processing (success or terminal failure). */
   protected $is_done = false;
   /** @var bool True when the round terminated in an error state. */
   protected $is_error = false;

   /** @var int|null Numeric status code returned by the ShortPixel API. */
   protected $apiStatus;
   /** @var string|null Human-readable message; final wording is decided by ResponseController. */
   protected $message;
   /** @var mixed Single-file payload; kept for backwards compatibility with older callers (should eventually be merged with $files). */
   protected $file;
   /** @var array<int, mixed>|null Multi-file payload for items with several files (e.g. thumbnails). */
   protected $files;
   /** @var int|null File-status code (see ImageModel::FILE_STATUS_*). */
   protected $fileStatus;
   /** @var string|null Filename attached to this result; set by QueueController when reconciling API responses. */
   protected $filename;
   /** @var int|string|null Error identifier; likely to become `error_code` in a future rename. */
   protected $error;
   /** @var int|null New attachment ID after a background remove-and-recreate flow. */
   protected $new_attach_id;
   /** @var bool|null Success flag (newer alternative to !$is_error). */
   protected $success;
   /** @var array<string, mixed>|null Per-variant improvement percentages captured after optimization. */
   protected $improvements;
   /** @var string|null Link to the original image, used by the bulk view. */
   protected $original;
   /** @var string|null Link to the optimized image, used by the bulk view. */
   protected $optimized;
   /** @var string|null Redirect target for background operations that must reload the admin page. */
   protected $redirect;
   /** @var string|null Queue type marker (media / custom); used by OptimizeController. */
   protected $queueType;
   /** @var string|null Knowledge-base URL for the error code, shown next to the error message. */
   protected $kblink;
   /** @var array|null Return-data list from ApiController; free-form structured payload. */
   protected $data;
   /** @var string|null Name of the handling API implementation; drives per-API response rendering in JS. */
   protected $apiName;
   /** @var string|int|null Remote reference id from the upstream API for support / tracing. */
   protected $remote_id;
   /** @var array<string, mixed>|null AI data returned by the AI-features pipeline. */
   protected $aiData;
   /** @var array<string, string>|null Human-readable labels for AI data, shown on bulk screens. */
   protected $aiDataLabels;


   /**
    * Constructor.
    *
    * Only assigns the identity field — every other field stays uninitialised
    * so forReturn() can distinguish "not set" from "set to null".
    *
    * @param int $item_id Attachment or custom-image ID this result belongs to.
    */
   public function __construct($item_id)
   {
        $this->item_id = $item_id;
   }

   /**
    * Magic accessor — returns the value of a declared field, or null (with a
    * warning log) when the field is unknown.
    *
    * @param string $name Field name.
    * @return mixed|null
    */
   public function __get($name)
   {
       if (property_exists($this, $name))
       {
           $value = $this->$name;


           return $value;
       }
       else
       {
           Log::addWarn('QueueItemResult Field requested not found: ' . $name);
       }
       return null;
   }

   /**
    * Magic mutator — assigns to a declared field, or logs a warning and does
    * nothing when the field is unknown. Guarantees that arbitrary key names
    * cannot pollute the object between construction and serialisation.
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
            Log::addWarn('QueueItemResult Field not exists - ' . $name);
       }

   }

   /**
    * Reset a declared field back to null so forReturn() will drop it.
    *
    * Silently no-ops for unknown fields — callers can safely try to clear a
    * field that may not exist on older schemas.
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
    * JsonSerializable hook; delegates to forReturn() so both paths (json_encode
    * and manual serialisation) produce the same compact object.
    *
    * @return object
    */
   public function jsonSerialize() : object
   {
        return $this->forReturn();
   }

   /**
    * Produce the compact response object: every declared field whose value is
    * non-null, cast to an object.
    *
    * Uses UtilHelper::arrayFilterNullValues to strip unset keys so the JS
    * client only sees fields the pipeline actually populated.
    *
    * @return object
    */
   public function forReturn()
   {
      $vars = get_object_vars($this);
      $vars = array_filter($vars, ['\ShortPixel\Helper\UtilHelper','arrayFilterNullValues']);
      return (object) $vars;
   }

} // Class
