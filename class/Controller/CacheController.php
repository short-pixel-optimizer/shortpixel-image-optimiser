<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\CacheModel as CacheModel;

/**
 * Storage-agnostic cache layer for temporary plugin data.
 *
 * Acts as the single access point for cached values so that callers do not need
 * to know where data is physically stored. Delegates persistence to CacheModel.
 * Items are also kept in the static in-memory registry `$cached_items` to avoid
 * repeated storage lookups within the same request.
 *
 * Fires filters:
 *   - `shortpixel/cache/get`  — after loading an item, before returning it.
 *   - `shortpixel/cache/save` — after persisting an item, before updating the registry.
 *
 * @package ShortPixel\Controller
 */
class CacheController extends \ShortPixel\Controller
{
  /** @var CacheModel[] Request-level registry of loaded/stored cache items, keyed by name. */
  protected static $cached_items = array();

  /**
   * Constructor — no initialisation required.
   */
  public function __construct()
  {
  }

  /**
   * Create or overwrite a named cache entry with a scalar/array value.
   *
   * Applies the `shortpixel/cache/save` filter after persisting so that
   * third-party cache back-ends (e.g. Redis via another plugin) can intercept.
   *
   * @param string $name    Cache key.
   * @param mixed  $value   Value to store.
   * @param int    $expires TTL in seconds. Defaults to HOUR_IN_SECONDS.
   * @return CacheModel The saved cache model object.
   */
  public function storeItem($name, $value, $expires = HOUR_IN_SECONDS)
  {
     $cache = $this->getItem($name);
     $cache->setValue($value);
     $cache->setExpires($expires);

     $cache->save();
     $cache = apply_filters('shortpixel/cache/save', $cache, $name);
     self::$cached_items[$name] = $cache;

     return $cache;
  }

  /**
   * Persist an already-configured CacheModel object and register it in the request cache.
   *
   * Use this after retrieving an item via getItem() and mutating it directly.
   *
   * @param CacheModel $cache The Cache Model Item.
   * @return void
   */
  public function storeItemObject(CacheModel $cache)
  {
       self::$cached_items[$cache->getName()] = $cache;
       $cache->save();
  }

  /**
   * Retrieve a cache item by name, creating an empty model if not yet loaded.
   *
   * Checks the in-memory registry first. On a miss instantiates a new CacheModel
   * and applies the `shortpixel/cache/get` filter before storing in the registry.
   * Check `CacheModel::exists()` on the returned object to determine whether a
   * persisted value is actually available.
   *
   * @param string $name Cache key.
   * @return CacheModel The (possibly empty) cache model object.
   */
  public function getItem($name)
  {
     if (isset(self::$cached_items[$name]))
      return self::$cached_items[$name];

     $cache = new CacheModel($name);
     $cache = apply_filters('shortpixel/cache/get', $cache, $name);
     self::$cached_items[$name] = $cache;

     return $cache;
  }

  /**
   * Delete a cache entry by name if it exists.
   *
   * Silently returns if the item does not exist in the backing store.
   *
   * @param string $name Cache key.
   * @return void
   */
  public function deleteItem($name)
  {
    $cache = $this->getItem($name);

    if ($cache->exists())
    {
      $cache->delete();
    }

  }

  /**
   * Delete a cache entry given its CacheModel object.
   *
   * @param CacheModel $cache The cache model to delete.
   * @return void
   */
  public function deleteItemObject(CacheModel $cache)
  {
    $cache->delete();
  }

}
