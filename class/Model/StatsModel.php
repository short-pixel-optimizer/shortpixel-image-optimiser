<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Controller\OtherMediaController as OtherMediaController;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\Image\MediaLibraryModel as MediaLibraryModel;


use ShortPixel\Helper\UtilHelper as UtilHelper;
use ShortPixel\Helper\InstallHelper as InstallHelper;


/**
 * Cached, chainable statistics model backing the admin-dashboard counters.
 *
 * Provides a fluent DSL for reading counts:
 *   `$stats->getStat('media')->grab('items')` → number of optimized media items
 *   `$stats->getStat('period')->grab('months')->grab(3)` → optimized in month N ago
 *
 * `getStat()` selects a top-level bucket and returns `$this`; `grab()` either
 * descends further and returns `$this`, or hits a leaf and returns the value.
 * Leaf reads default to `-1` on the in-memory copy; that sentinel triggers a
 * lazy DB fetch through fetchStatData() and the result is cached back into
 * the settings row `currentStats` (keyed by a `time` field). The cache is
 * considered fresh for `WEEK_IN_SECONDS` by default; the interval is
 * filterable via `shortpixel/statistics/refresh`.
 *
 * @package ShortPixel\Model
 */
class StatsModel
{

  /** @var int|null Unix timestamp of the last time the cache was saved (from the `time` key on currentStats). */
  protected $lastUpdate;
  /** @var string[] Current chain path (e.g. ['media', 'items']) built up by getStat() and grab(). */
  protected $path = array();

  /** @var mixed The chain cursor — set by getStat() and mutated by each grab() step until a leaf is reached. */
  protected $currentStat;

  /** @var int Cache TTL in seconds (default WEEK_IN_SECONDS, filterable). */
  protected $refreshStatTime;


  /**
   * Default bucket structure. Every leaf starts at -1 (sentinel: "not yet
   * loaded"), so the first grab() through fetchStatData() computes and caches.
   *
   * The commented-out lossy/lossless/glossy breakdowns were dropped in a
   * pre-5.0 cleanup. Media `thumbs` / `thumbsTotal` are marked imprecise
   * because they come from a raw substring extraction of the WP metadata
   * blob rather than a properly walked family.
   *
   * @var array<string, array<string, mixed>>
   */
    protected $defaults = array(
      'media' => array('items' => -1, // total optimized media items found
                       'images' => -1, // total optimized images (+thumbs) found
                       'thumbs' => -1, // Optimized thumbs - SQL does thumbs, but queue doesn't. (imprecise query)
                       'itemsTotal' => -1, // Total items in media  ( sql )
                       'thumbsTotal' => -1, // Total thumbs in media ( sql ) - imprecise query
											 'isLimited' => false,
                  /*     'lossy' => 0, // processed x compression
                       'lossy_thumbs' => 0, // main / thumbs
                       'lossless' => 0, // main /thumbs
                       'lossless_thumbs' => 0,
                       'glossy' => 0,
                       'glossy_thumbs' => 0, */
      ),
      'custom' => array('items' => -1, // total optimized custom items
                        'images' => -1, // total optimized custom images
                        'itemsTotal' => -1,

                      /*  'lossy' => 0, // process x compression
                        'lossless' => 0,
                        'glossy' => 0, */
      ),
      'period' => array('months' =>  // amount of images compressed in x month
                    array('1' => -1,  /// count x months ago what was done.
                        '2' => -1,
                        '3' => -1,
                        '4' => -1,
                    ),
      ),
      'total' => array('items' => -1,
                       'images' => -1,
                       'thumbs' => -1,
                       'itemsTotal' => -1,
                       'thumbsTotal' => -1,
                     ),

    /*  'total' => array('items' => 0,  // total items found
                       'images' => 0, // total images found
      ), */
  );

  /** @var array<string, array<string, mixed>>|null Live stats — either the persisted values (if fresh) or a copy of $defaults. */
  protected $stats;

  /**
   * Constructor.
   *
   * Reads the filterable cache TTL from `shortpixel/statistics/refresh`
   * (default: 1 week) and immediately loads the persisted stats.
   */
  public function __construct()
  {
      $this->refreshStatTime = apply_filters('shortpixel/statistics/refresh', WEEK_IN_SECONDS);
      $this->load();
  }

  /**
   * Populate $stats from the `currentStats` setting, applying freshness
   * and schema-repair rules.
   *
   * Flow:
   *   - Non-array or missing → start from $defaults.
   *   - Legacy pre-5.0 shape (detected via the `APIKeyValid` key) →
   *     discarded, fall back to $defaults.
   *   - array_filter drops falsy top-level buckets — protects against a
   *     stored `[]` or `false` from wiping the schema.
   *   - array_merge with $defaults guarantees every top-level bucket is
   *     present (top-level shallow merge only — the leaves may still be
   *     -1 from the merge and will get lazy-loaded on first grab).
   *   - If the persisted `time` + refreshStatTime is still in the future,
   *     accept the payload as fresh; otherwise start over from $defaults.
   *
   * @return void
   */
  public function load()
  {
    $settings = \wpSPIO()->settings();

    $stats = $settings->currentStats;
		if (! is_array($stats))
		{
			 $stats = $this->defaults;
		}

		$stats = array_filter($stats);

    // Legacy. Stats from < 5.0 are loaded somehow. Don't load them.
    if (isset($stats['APIKeyValid']))
		{
      $stats = $this->defaults;
		}

		$stats = array_merge($this->defaults, $stats); // merge like args to ensure full structure present.

    $this->lastUpdate = (isset($stats['time'])) ? $stats['time'] : 0;

    if ( ($this->lastUpdate + $this->refreshStatTime) >= time())
    {
       $this->stats = $stats;
    }
    else
		{
      $this->stats = $this->defaults;
		}

  }

  /**
   * Persist the current $stats payload to the `currentStats` setting,
   * stamping it with the current time for freshness checks.
   *
   * The write goes through SettingsModel so the shutdown handler batches
   * it with any other setting changes.
   *
   * @return void
   */
  public function save()
  {
     $settings = \wpSPIO()->settings();
     $stats = $this->stats;
     $stats['time'] = time();
     $settings->currentStats = $stats;
  }

  /**
   * Reset the in-memory stats to defaults and delete the persisted row.
   *
   * The subsequent load() call would find the option missing and fall
   * back to defaults, so the deletion is enough — no explicit save() is
   * needed here.
   *
   * @return void
   */
  public function reset()
  {
      $this->stats = $this->defaults;
			\wpSPIO()->settings()->deleteOption('currentStats');

  //    $this->save();
  }

  /**
   * Merge counter deltas from a stat-shaped object into the current bucket.
   *
   * NOTE: not currently functional — the leaves start at -1 (sentinel)
   * so `+=` produces incorrect running totals. Kept as scaffolding until
   * the additive counter flow is redesigned.
   *
   * @param object $stat Expected to expose $type + $images / $items numeric fields.
   * @return void
   *
   * @todo Fix the -1 sentinel interaction before wiring this back up.
   */
  public function add($stat)
  {
     if (property_exists($stat, 'images'))
         $this->stats[$stat->type]['images'] += $stat->images;
     if (property_exists($stat, 'items'))
        $this->stats[$stat->type]['items'] += $stat->items;


  }

  /**
   * Property accessor — returns the value of a declared property, or null
   * for unknown names.
   *
   * @param string $name Property name.
   * @return mixed|null
   */
  public function get($name)
  {
      if (property_exists($this, $name))
         return $this->$name;
      else
        return null;
  }

  /**
   * Start a fluent chain by selecting a top-level bucket.
   *
   * Resets the chain state (currentStat + path) then loads the requested
   * bucket if it exists. Always returns $this so callers can immediately
   * chain into `->grab(...)`.
   *
   * @param string $type Top-level bucket name — 'media', 'custom', 'period', or 'total'.
   * @return $this
   */
  public function getStat($type)
  {
      $this->currentStat = null;
      if (isset($this->stats[$type]))
      {
         $stat = $this->stats[$type];

         $this->currentStat = $this->checkInt($stat);
         $this->path = [$type];
      }

      return $this;
  }

  /**
   * Descend one level into the chain, or return the leaf value.
   *
   * Returns null when getStat() was never called or the last step
   * blew away the cursor. When the requested key exists on the current
   * (array) cursor, appends it to $path and steps the cursor down. When
   * the cursor is no longer an array (reached a leaf), inspects the
   * value: if it is the -1 sentinel, delegates to fetchStatData() which
   * computes + caches + saves. Otherwise returns the current value.
   *
   * @param int|string $data Key at the next level of the chain.
   * @return $this|int|string|null
   */
  public function grab($data)
  {
     if (is_null($this->currentStat))
          return null;

       if (is_array($this->currentStat) && array_key_exists($data, $this->currentStat))
       {
          $this->currentStat = $this->checkInt($this->currentStat[$data]);
          $this->path[] = $data;
       }


       if (! is_array($this->currentStat))
       {

         if ($this->currentStat === -1)
         {
            $this->currentStat = $this->checkInt($this->fetchStatdata());  // if -1 stat might not be loaded, load.
         }

        return $this->currentStat;
       }
       else
		{
        return $this;
		}
  }

  /**
   * Lazy backend for grab() — resolves a chain path against the actual
   * data sources, caches the result on $stats and saves.
   *
   * Routes by the leading path segment:
   *   - `period.months.<N>` → countMonthlyOptimized(N)
   *   - `media.items` / `media.itemsTotal` → countMediaItems() variants
   *   - `media.thumbs` / `media.thumbsTotal` → countMediaThumbnails() variants
   *   - `media.images` → composed: media.items + media.thumbs
   *   - `media.isLimited` → the flag countMediaThumbnails() sets when it
   *     hit its row-limit on the unoptimized branch
   *   - `custom.items` / `custom.itemsTotal` → customItems() variants
   *   - `total.*` → summed across media + custom (custom.items == custom.images)
   *
   * Every branch normalises negative results to 0 before storing so a
   * stray DB error can't corrupt the visible counters.
   *
   * @return int|string The computed value (or -1 when the path was unrecognised).
   */
  private function fetchStatData()
  {
      $path = $this->path;
      $data = -1;

      if ($path[0] == 'period' && $path[1] == 'months' && isset($path[2]))
      {
          $month = $path[2];

          $data = $this->countMonthlyOptimized(intval($month));

          if ($data >= 0)
          {
            $this->stats['period']['months'][$month] = $data;
            $this->save();
          }

      }
      if ($path[0] == 'media')
      {
          switch($path[1])
          {
            case 'items':
              $data = $this->countMediaItems(['optimizedOnly' => true]);
            break;
            case 'thumbs': // unrealiable if certain thumbs are not optimized, but the main image is.
              $data = $this->countMediaThumbnails(['optimizedOnly' => true]);
            break;
            case 'images':
              $data = $this->getStat('media')->grab('items') + $this->getStat('media')->grab('thumbs');
            break;
            case 'itemsTotal':
              $data = $this->countMediaItems();
            break;
            case 'thumbsTotal':
               $data = $this->countMediaThumbnails();
            break;
						case 'isLimited':
								$data = $this->stats['media']['isLimited'];
						break;
          }

          if ($data >= 0)
          {
						 if (is_numeric($data))
						 {
							  $data = max($data, 0);
						 }
             $this->stats['media'][$path[1]] = $data; // never allow any data below zero.
             $this->save();
          }
      }


      if ($path[0] == 'custom')
      {
          switch($path[1])
          {
             case 'items':
                $data = $this->customItems(['optimizedOnly' => true]);
             break;
             case 'itemsTotal':
                $data = $this->customItems();
             break;
          }

          if ($data >= 0)
          {
             $this->stats['custom'][$path[1]] = $data;
             $this->save();
          }
      }

      if ($path[0] == 'total')
      {
         switch($path[1])
         {

            case 'items':
              $media = $this->getStat('media')->grab('items');
              $custom = $this->getStat('custom')->grab('items');
              $data = $media + $custom;
            break;
            case 'images':
              $media = $this->getStat('media')->grab('images');
              $custom = $this->getStat('custom')->grab('items'); // items == images
              $data = $media + $custom;
            break;
            case 'thumbs':
               $data = $this->getStat('media')->grab('thumbs');
            break;
            case 'itemsTotal':
                $media = $this->getStat('media')->grab('itemsTotal');
                $custom = $this->getStat('custom')->grab('itemsTotal');
                $data = $media + $custom;
            break;
            case 'thumbsTotal':
                $data = $this->getStat('media')->grab('thumbsTotal');
            break;

         }
         if ($data >= 0)
         {
            $this->stats['total'][$path[1]] = $data;
            $this->save();
         }
      }

      return $data;

  }

  /**
   * Coerce a numeric string to int in place; passes non-numeric values
   * through untouched.
   *
   * Used everywhere in the chain so DB counts (which come back as strings
   * from wpdb) get normalised before being cached / compared.
   *
   * @param mixed $var Value to coerce.
   * @return mixed
   */
  private function checkInt($var)
  {
    if (is_numeric($var) && gettype($var) !== 'integer')
    {
       $var = intval($var);
    }
    return $var;

  }


  /**
   * Count media-library thumbnails, optionally scoped to optimized ones.
   *
   * Two very different queries under the hood:
   *   - `optimizedOnly = true`: precise — counts shortpixel_postmeta rows
   *     with FILE_STATUS_SUCCESS and image_type ∈ {THUMB, ORIGINAL}.
   *   - `optimizedOnly = false`: imprecise — extracts a two-character
   *     substring from each `_wp_attachment_metadata` blob at a fixed
   *     offset past the "sizes" key, which works for 0–99 thumbnails and
   *     tolerates the "original_image" marker as an extra +1. This is a
   *     deliberate perf trade-off — parsing every serialised blob would
   *     be too slow on large libraries.
   *
   * Both queries exclude attachments carrying the
   * `_shortpixel_prevent_optimize` post meta ("crashed items") — but note
   * the exclusion is only actually written for the imprecise branch;
   * the optimizedOnly branch trusts the shortpixel_postmeta row.
   *
   * When the raw query hits the `limit` row cap, `stats.media.isLimited`
   * is flipped so the UI can warn the number is a floor.
   *
   * @param array{optimizedOnly?: bool, limit?: int} $args Options.
   * @return int Thumbnail count.
   */
  private function countMediaThumbnails($args = array())
  {
     global $wpdb;

     $defaults = array(
       'optimizedOnly' => false,
			 'limit' => 50000,
     );

     $args = wp_parse_args($args,$defaults);
		 $prepare = array();

     if ($args['optimizedOnly'] == true)
     {
       $sql =  ' SELECT count(id) as thumbcount FROM ' . UtilHelper::getPostMetaTable() . ' WHERE status = %d AND (image_type = %d or image_type = %d)';
			 $prepare = array(ImageModel::FILE_STATUS_SUCCESS, MediaLibraryModel::IMAGE_TYPE_THUMB, MediaLibraryModel::IMAGE_TYPE_ORIGINAL);
     }
		 else {
			 // This query will return 2 positions after the thumbnail array declaration.  Value can be up to two positions ( 0-100 thumbnails) . If positions is 1-10 intval will filter out the string part.
	     $sql = "SELECT  meta_id, post_id, substr(meta_value, instr(meta_value,'sizes')+9,2) as thumbcount, LOCATE('original_image', meta_value) as originalImage FROM " . $wpdb->postmeta . " WHERE meta_key = '_wp_attachment_metadata' ";

	     $sql .= " AND post_id NOT IN ( SELECT post_id FROM " . $wpdb->postmeta . " where meta_key = '_shortpixel_prevent_optimize' )";  // exclude 'crashed items'

			 $sql .= " limit 0," . $args['limit'];
		 }


		 if (count($prepare) > 0)
		 {
			  $sql = $wpdb->prepare($sql, $prepare);
		 }

     $results = $wpdb->get_results($sql);

		 //og::addDebug('Limit and count results' . $args['limit'] . ' ' . count($results));
		 if ($args['limit'] <= count($results))
		 {
			 	$this->stats['media']['isLimited']= true;
		 }

     $thumbCount = 0;

     foreach($results as $row)
     {
        $count = intval($row->thumbcount);
        if ($count > 0)
           $thumbCount += $count;
        if (property_exists($row, 'originalImage') && $row->originalImage > 0) // add to count, return value is string pos
           $thumbCount++;
     }

     return intval($thumbCount);
  }

  /**
   * Count media-library attachments, optionally scoped to optimized ones.
   *
   * Two queries:
   *   - `optimizedOnly = true`: counts shortpixel_postmeta rows with
   *     FILE_STATUS_SUCCESS and image_type = IMAGE_TYPE_MAIN.
   *   - `optimizedOnly = false`: counts `_wp_attached_file` rows,
   *     excluding attachments flagged with `_shortpixel_prevent_optimize`.
   *
   * If the query fails because the shortpixel_postmeta table doesn't
   * exist, InstallHelper::checkTables() is invoked to self-heal, an
   * error is logged, and 0 is returned.
   *
   * @param array{optimizedOnly?: bool} $args Options.
   * @return int Item count.
   */
  private function countMediaItems($args = array())
  {
      global $wpdb;

      $defaults = array(
        'optimizedOnly' => false,
      );

      $args = wp_parse_args($args,$defaults);
			$prepare = array();

      if ($args['optimizedOnly'] == true)
      {
        //$sql .= ' AND post_id IN ( SELECT post_id FROM ' . $wpdb->postmeta . ' WHERE meta_key = "_shortpixel_optimized")';
				$sql = ' SELECT count(id) as count FROM ' . UtilHelper::getPostMetaTable() . ' WHERE status = %d AND parent = %d';
				$prepare = array(ImageModel::FILE_STATUS_SUCCESS, MediaLibraryModel::IMAGE_TYPE_MAIN);
      }
			else {
				$sql = 'SELECT count(meta_id) FROM ' . $wpdb->postmeta . ' WHERE meta_key = "_wp_attached_file"';
     		$sql .= " AND post_id NOT IN ( SELECT post_id FROM " . $wpdb->postmeta . " where meta_key = '_shortpixel_prevent_optimize' )";  // exclude 'crashed items'
			}

			if (count($prepare) > 0)
				$sql = $wpdb->prepare($sql, $prepare);

      $count = $wpdb->get_var($sql);


			if (is_null($count) && strpos($wpdb->last_error, 'exist') !== false)
			{
				 InstallHelper::checkTables();
         Log::addError('StatsModel WPDB error', $wpdb->last_error);
				 return 0;
			}

      return intval($count);
  }

  /**
   * Count optimizations completed during a given month bucket.
   *
   * Bucketing: `monthsAgo = 1` returns the count for the range
   * [now - 1 month, now]; `monthsAgo = N` returns [now - N months,
   * now - (N-1) months]. So `1`, `2`, `3`, `4` produce four
   * consecutive-monthly buckets stacked backwards from the current day.
   *
   * Counts both media-library optimizations (shortpixel_postmeta with
   * tsOptimized) and custom-image optimizations (shortpixel_meta with
   * ts_optimized), summed across both tables. Non-numeric wpdb results
   * are ignored so a table-missing error contributes 0 rather than
   * poisoning the total.
   *
   * @param int $monthsAgo Which month bucket to compute (1 = most recent).
   * @return int Total optimizations completed in that month.
   */
  private function countMonthlyOptimized($monthsAgo = 1)
  {
     global $wpdb;
     //$monthsAgo = 0 - $monthsAgo; // minus it for the sub.
     /*$sql = "select meta_id from wp_postmeta where meta_key = '_shortpixel_meta' HAVING substr(meta_value, instr(meta_value, 'tsOptimized')+15,10) as stamp >= %d and stamp <= %d"; */

		 $date = new \DateTime();
     $date->sub( new \DateInterval('P' . $monthsAgo . 'M'));

     $dateUntil = new \DateTime();
     $dateUntil->sub( new \DateInterval('P' . ($monthsAgo-1). 'M'));

     $sql = 'SELECT count(id) FROM '  . $wpdb->prefix . 'shortpixel_postmeta WHERE tsOptimized >= %s and tsOptimized <= %s';
     $sql = $wpdb->prepare($sql, $date->format('Y-m-d H:i:s'), $dateUntil->format('Y-m-d H:i:s') );
     $count_media = $wpdb->get_var($sql);

		 // Custom
		 $sql = 'SELECT count(id) FROM '  . $wpdb->prefix . 'shortpixel_meta WHERE ts_optimized >= %s and ts_optimized <= %s';
		 $sql = $wpdb->prepare($sql, $date->format('Y-m-d H:i:s'), $dateUntil->format('Y-m-d H:i:s') );
		 $count_custom = $wpdb->get_var($sql);

		 $count = 0;
		 if (! is_null($count_media) && is_numeric($count_media))
		 	$count += $count_media;

			if (! is_null($count_custom) && is_numeric($count_custom))
 		 	$count += $count_custom;


     return $count;
  }

  /**
   * Count custom-folder items, optionally scoped to optimized ones.
   *
   * Short-circuits to 0 in two cases: when OtherMediaController reports
   * no custom images are configured at all, or when no active folder ids
   * exist. Otherwise counts `shortpixel_meta` rows whose folder_id is in
   * the active set; when `optimizedOnly = true`, additionally filters by
   * status = FILE_STATUS_SUCCESS.
   *
   * @param array{optimizedOnly?: bool} $args Options.
   * @return int|string|null Item count from wpdb (raw scalar; caller is
   *                         expected to coerce via checkInt()).
   */
  private function customItems($args = array())
  {
       global $wpdb;

       $defaults = array(
         'optimizedOnly' => false,
       );

       $args = wp_parse_args($args,$defaults);

       $otherMediaController = OtherMediaController::getInstance();
       if (! $otherMediaController->hasCustomImages() )
       {
          return 0;
       }

			 $activeDirectories = $otherMediaController->getActiveDirectoryIDS();
      // $foldersids = implode(',', $activeDirectories );

			 if (count($activeDirectories) == 0)
			 	  return 0; // no active folders

					$in_str_arr = array_fill( 0, count( $activeDirectories ), '%s' );
 				 $in_str = join( ',', $in_str_arr );

       $sql = 'SELECT COUNT(id) as count FROM ' . $wpdb->prefix . 'shortpixel_meta WHERE folder_id in (' . $in_str . ')';
			 $sql = $wpdb->prepare($sql, $activeDirectories);

       if ($args['optimizedOnly'] == true)
       {
         $sql .= ' AND status = %d';
         $sql = $wpdb->prepare($sql, ImageModel::FILE_STATUS_SUCCESS);
       }

        $count = $wpdb->get_var($sql);
        return $count;

  }


} // class
