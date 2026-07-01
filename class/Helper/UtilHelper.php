<?php

namespace ShortPixel\Helper;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


if (! defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

/**
 * Static utility class for miscellaneous helpers used across the plugin.
 *
 * Bundles small, dependency-light helpers for database table naming, plugin
 * activation checks, timestamp conversion, image-size discovery, path
 * normalisation, JSON validation, exclusion-pattern handling, and .htaccess
 * rule management for WebP/AVIF delivery.
 *
 * @package ShortPixel\Helper
 */
class UtilHelper
{

  /**
   * Returns the fully-prefixed name of the plugin's postmeta table.
   *
   * @return string Table name including the WordPress table prefix.
   */
  public static function getPostMetaTable()
  {
    global $wpdb;

    return $wpdb->prefix . 'shortpixel_postmeta';
  }

  /**
   * Checks whether a given plugin is currently active on this site.
   *
   * On multisite installs, network-active (sitewide) plugins are considered as
   * well as the per-site active list.
   *
   * @param string $plugin Plugin file identifier, e.g. "folder/plugin.php".
   * @return bool True if the plugin is active, false otherwise.
   */
  public static function shortPixelIsPluginActive($plugin)
  {
    $activePlugins = apply_filters('active_plugins', get_option('active_plugins', array()));
    if (is_multisite()) {
      $activePlugins = array_merge($activePlugins, get_site_option('active_sitewide_plugins'));
    }
    return in_array($plugin, $activePlugins);
  }

  /**
   * Formats a Unix timestamp into a MySQL DATETIME string ("Y-m-d H:i:s").
   *
   * @param int $timestamp Unix timestamp in seconds.
   * @return string MySQL-compatible datetime string.
   */
  public static function timestampToDB($timestamp)
  {
    return date("Y-m-d H:i:s", $timestamp);
  }

  /**
   * Parses a MySQL DATETIME string back into a Unix timestamp.
   *
   * @param string $date MySQL datetime string.
   * @return int|false Unix timestamp on success, false on parse failure.
   */
  public static function DBtoTimestamp($date)
  {
    return strtotime($date);
  }

  /**
   * Returns all registered WordPress image sizes with their dimensions.
   *
   * Merges the built-in intermediate sizes (thumbnail/medium/large etc.) with
   * any sizes added via add_image_size(). The result is filterable through the
   * "shortpixel/settings/image_sizes" filter so integrations can extend the list.
   *
   * @return array<string, array{width:int,height:int,crop:mixed,nice-name?:string}>
   *         Associative array keyed by size name.
   */
  public static function getWordPressImageSizes()
  {
    global $_wp_additional_image_sizes;

    $sizes_names = get_intermediate_image_sizes();
    $sizes = array();
    foreach ($sizes_names as $size) {
      $sizes[$size]['width'] = intval(get_option("{$size}_size_w"));
      $sizes[$size]['height'] = intval(get_option("{$size}_size_h"));
      $sizes[$size]['crop'] = get_option("{$size}_crop") ? get_option("{$size}_crop") : false;
      $sizes[$size]['nice-name'] = ucfirst($size);
    }
    if (function_exists('wp_get_additional_image_sizes')) {
      $sizes = array_merge($sizes, wp_get_additional_image_sizes());
    } elseif (is_array($_wp_additional_image_sizes)) {
      $sizes = array_merge($sizes, $_wp_additional_image_sizes);
    }

    $sizes = apply_filters('shortpixel/settings/image_sizes', $sizes);
    return $sizes;
  }



  /**
   * Collapses runs of forward slashes in a path to a single slash.
   *
   * Preserves a leading "//" (used by UNC-style network paths) by only matching
   * duplicated slashes preceded by another character. Used as a lightweight
   * alternative to wp_normalize_path(), which behaves inconsistently on some
   * Windows installations.
   *
   * @param string $path Filesystem path to normalise.
   * @return string Normalised path with collapsed internal slashes.
   */
  public static function spNormalizePath($path)
  {
    $path = preg_replace('|(?<=.)/+|', '/', $path);
    return $path;
  }

  /**
   * Returns the combined EXIF setting value used by the optimisation API.
   *
   * Sums the "exif" (keep/remove) and "exif_ai" (AI allowed) setting flags into
   * a single integer that encodes both behaviours.
   *
   * @return int Combined EXIF parameter value.
   */
  public static function getExifParameter()
  {
    return (\wpSPIO()->settings()->exif + \wpSPIO()->settings()->exif_ai);
  }

  /**
   * Converts an absolute path under the uploads directory into a path relative
   * to that directory.
   *
   * Mirrors WordPress core's private _wp_relative_upload_path() so we can call
   * it from any context. Paths that are not under wp_get_upload_dir()['basedir']
   * are returned unchanged.
   *
   * @see https://developer.wordpress.org/reference/functions/_wp_relative_upload_path/
   *
   * @param string $path Absolute filesystem path.
   * @return string Relative path if under the uploads dir, otherwise the input path.
   */
  public static function getRelativeUploadPath($path)
  {
    $new_path = $path;
    $uploads = wp_get_upload_dir();
    if (0 === strpos($new_path, $uploads['basedir'])) {
      $new_path = str_replace($uploads['basedir'], '', $new_path);
      $new_path = ltrim($new_path, '/');
    }
    return $new_path;
  }

  /** Usage: Plug this into array_filter method. 
   * 
   * @return bool 
   */
  public static function arrayFilterNullValues($val)
  {
    return $val !== null;
  }

  /**
   * Checks whether a string contains syntactically valid JSON.
   *
   * Short-circuits to false for non-strings and for strings that contain
   * neither "{" nor ":", so trivial inputs never hit the parser. Uses PHP 8.3's
   * native json_validate() where available, otherwise falls back to a
   * json_decode() + json_last_error() check.
   *
   * @param string $json Candidate JSON string.
   * @return bool True if the input is a non-empty valid JSON string.
   */
  public static function validateJSON($json)
  {
    if (!is_string($json)) {
      return false;
    }

    // Try to simpler bail out without checking for the decode.
		if (strpos($json, '{' ) === false && strpos($json, ':') === false)
		{
			return false; 
		}

    if (function_exists('json_validate')) {
      return \json_validate($json);
    }

    json_decode($json);
    return json_last_error() === JSON_ERROR_NONE;
  }

  /**
   * Returns the configured exclusion patterns, optionally filtered by context.
   *
   * When $args['filter'] is true, only the patterns that apply to the given
   * context (thumbnail vs. custom image, optional thumbnail name) are returned.
   * Otherwise the full pattern list from settings is returned.
   *
   * @param array $args {
   *     Optional filtering context.
   *
   *     @type bool        $filter       Whether to filter patterns by the other args. Default false.
   *     @type string|null $thumbname    Thumbnail name to match against a pattern's thumblist. Default null.
   *     @type bool        $is_thumbnail True when evaluating a thumbnail. Default false.
   *     @type bool        $is_custom    True when evaluating a Custom Media image. Default false.
   * }
   * @return array<int, array> List of exclusion pattern definitions.
   */
  public static function getExclusions($args = array())
  {
    $defaults = array(
      'filter' => false,
      'thumbname' => null,
      'is_thumbnail' => false,
      'is_custom' => false,
    );

    $args = wp_parse_args($args, $defaults);

    $patterns = \wpSPIO()->settings()->excludePatterns;
    $matches = array();

    if (false === is_array($patterns)) {
      return array();
    }

    foreach ($patterns as $index => $pattern) {
      if (! isset($pattern['apply'])) {
        $patterns[$index]['apply'] = 'all';
      }

      if (true === $args['filter']) {
        if (true === self::matchExclusion($patterns[$index], $args)) {
          $matches[] = $pattern;
        }
      }
    }

    if (true === $args['filter']) {
      return $matches;
    } else
      return $patterns;
  }

  /**
   * Evaluates whether a single exclusion pattern applies in the given context.
   *
   * Handles the four "apply" scopes ("all", "only-thumbs", "only-custom", and
   * per-thumbnail via the pattern's thumblist).
   *
   * @param array $pattern Exclusion pattern definition (must include an 'apply' key).
   * @param array $options Context flags: 'is_thumbnail', 'is_custom', 'thumbname'.
   * @return bool True if the pattern matches the context.
   */
  protected static function matchExclusion($pattern, $options)
  {
    $apply = $pattern['apply'];
    $thumblist = isset($pattern['thumblist']) ? $pattern['thumblist'] : array();
    $bool = false;

    if ($apply === 'all') {
      $bool = true;
    } elseif ($apply == 'only-thumbs' && true === $options['is_thumbnail']) {
      $bool = true;
    } elseif ($apply == 'only-custom' && true === $options['is_custom']) {
      $bool = true;
    } elseif (count($thumblist) > 0 && ! is_null($options['thumbname'])) {
      $thumbname = $options['thumbname'];
      if (in_array($thumbname, $thumblist)) {
        $bool = true;
      }
    }
    return $bool;
  }

  public static function testSymlink() : bool 
  {
     $fs = \wpSPIO()->filesystem(); 

     $base = $fs->getWPUploadBase();

     $test_file = $base . 'test_file.txt'; 
     $symlink_file = $base . 'test_symlink.txt'; 

     if (file_exists($test_file))
     {
         unlink($test_file); 
     }
     if (file_exists($symlink_file))
     {
         unlink($symlink_file); 
     }


     touch($test_file);

     symlink($test_file, $symlink_file); 

     if (false === file_exists($symlink_file))
     {
        unlink($test_file);
        return false;    
     }

     $content_check = '12345';  // Check if symlink is linked. 
     file_put_contents($symlink_file, $content_check); 

     if (file_get_contents($test_file) != file_get_contents($symlink_file))
     {
        $bool = false; 
     }
     else 
     { 
        $bool = true; 
     }

     
     unlink($test_file);
     unlink($symlink_file); 

     
     return $bool;
  }

  /**
   * Builds the AI-feature settings payload, merged with caller-provided overrides.
   *
   * Reads every AI-related field from the plugin settings (generation flags,
   * per-field character limits, context strings, language, EXIF handling) and
   * merges them with any keys supplied in $params so callers can override
   * individual values without repeating the full list.
   *
   * @param array $params Optional overrides keyed by setting name.
   * @return array Merged AI settings array.
   */
  public static function getAiSettings($params = [])
  {
    $settings = \wpSPIO()->settings();

    $defaults = [
    'ai_general_context' => $settings->ai_general_context, 
    'ai_use_post' => $settings->ai_use_post, 
    'ai_gen_alt' => $settings->ai_gen_alt, 
    'ai_gen_caption' => $settings->ai_gen_caption, 
    'ai_gen_description' => $settings->ai_gen_description, 
    'ai_gen_post_title' => $settings->ai_gen_post_title, 
    'ai_filename_prefercurrent' => $settings->ai_filename_prefercurrent,
    'ai_limit_alt_chars' => $settings->ai_limit_alt_chars, 
    'ai_alt_context' => $settings->ai_alt_context, 
    'ai_limit_description_chars' => $settings->ai_limit_description_chars, 
    'ai_description_context' => $settings->ai_description_context, 
    'ai_limit_caption_chars' => $settings->ai_limit_caption_chars, 
    'ai_caption_context' => $settings->ai_caption_context, 
    'ai_limit_post_title_chars' => $settings->ai_limit_post_title_chars, 
    'ai_post_title_context' => $settings->ai_post_title_context, 
    'ai_gen_filename' => $settings->ai_gen_filename, 
    'ai_limit_filename_chars' => $settings->ai_limit_filename_chars, 
    'ai_filename_context' => $settings->ai_filename_context, 
    'ai_use_exif' => $settings->ai_use_exif, 
    'ai_language' => $settings->ai_language,
    'aiPreserve' => $settings->aiPreserve, 
    ];

    $params = wp_parse_args($params, $defaults);

    return $params; 
  }

  /**
   * Converts a human-readable file-size string (e.g. "5k", "2MB", "1g") into
   * a plain byte count.
   *
   * Accepts optional whitespace, an optional decimal-less integer prefix, and
   * an optional case-insensitive suffix (K, M, G, T, each optionally followed
   * by "B"). The 1024-based multipliers cascade via fall-through, so "1t"
   * becomes 1024^4 bytes. Input that does not match the pattern is returned
   * unchanged.
   *
   * @param string $value Size string to parse.
   * @return string Numeric string representing the size in bytes, or the
   *                original input if the pattern did not match.
   */
  public static function convertExclusionFileSizeToBytes($value)
  {
    return preg_replace_callback('/^\s*(\d+)\s*(?:([kmgt]?)b?)?\s*$/i', function ($m) {
      switch (strtolower($m[2])) {
        case 't': $m[1] *= 1024;
        case 'g': $m[1] *= 1024;
        case 'm': $m[1] *= 1024;
        case 'k': $m[1] *= 1024;
      }
      return $m[1];
    }, $value);

  }

  /**
   * Writes or removes the ShortPixelWebp rewrite rules in the site's .htaccess files.
   *
   * When both $webp and $avif are false the rules are cleared from the root,
   * uploads, and wp-content .htaccess files. Otherwise the combined AVIF + WebP
   * rewrite block is written to the root .htaccess, and (unless disabled via the
   * "shortpixel/install/write_deep_htaccess" filter) also to the uploads and
   * wp-content .htaccess files with an inherited-rules preamble. The rules
   * serve pre-generated .avif or .webp files to browsers that advertise
   * support, and set appropriate Vary and Cache-Control headers.
   *
   * Both flags are passed together (rather than one-at-a-time) because previous
   * versions of the plugin may have generated files of either format, so both
   * rule sets are always written when any next-gen delivery is enabled.
   *
   * @param bool $webp Whether WebP delivery is enabled. Default false.
   * @param bool $avif Whether AVIF delivery is enabled. Default false.
   * @return void
   */
  public static function alterHtaccess($webp = false, $avif = false)
  {
    // [BS] Backward compat. 11/03/2019 - remove possible settings from root .htaccess
    /* Plugin init is before loading these admin scripts. So it can happen misc.php is not yet loaded */
    if (! function_exists('insert_with_markers')) {
      Log::addWarn('AlterHtaccess Called before WP init');
      return;
      //require_once( ABSPATH . 'wp-admin/includes/misc.php' );
    }
    $upload_dir = wp_upload_dir();
    $upload_base = trailingslashit($upload_dir['basedir']);

    if (false === $webp && false === $avif) {
      insert_with_markers(get_home_path() . '.htaccess', 'ShortPixelWebp', '');

      // Only empty these tags if the file actually exist, they are created by SPIO.
      if (file_exists($upload_base . '.htaccess')) {
        insert_with_markers($upload_base . '.htaccess', 'ShortPixelWebp', '');
      }


      if (file_exists(trailingslashit(WP_CONTENT_DIR) . '.htaccess')) {
        insert_with_markers(trailingslashit(WP_CONTENT_DIR) . '.htaccess', 'ShortPixelWebp', '');
      }
    } else {

      $avif_rules = '
           <IfModule mod_rewrite.c>
           RewriteEngine On
           ##### Directives for delivering AVIF files, if they exist #####
           # Does the browser support avif?
           RewriteCond %{HTTP_ACCEPT} image/avif
           # AND is the request a JPG, PNG, or WebP? (no GIFs because the animation is sometimes lost in AVIF);
		   # (also grab the basepath %1 to match in the next rule)
           RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png|webp)$
           # AND does a .avif image exist?
           RewriteCond %{DOCUMENT_ROOT}/%1.avif -f
           # THEN send the avif image and set the env var avif
           RewriteRule (.+)\.(?:jpe?g|png|webp)$ $1.avif [NC,T=image/avif,E=avif,L]

           # Does the browser support avif?
           RewriteCond %{HTTP_ACCEPT} image/avif
           # AND is the request a JPG, PNG, or WebP? (no GIFs because the animation is sometimes lost in AVIF);
		   # (also grab the basepath %1 to match in the next rule)
           RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png|webp)$
           # AND does a .jpg.avif image exist?
           RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.avif -f
           # THEN send the avif image and set the env var avif
           RewriteRule ^(.+)$ $1.avif [NC,T=image/avif,E=avif,L]

           </IfModule>
           <IfModule mod_headers.c>
           # If REDIRECT_avif env var exists, append Accept to the Vary header
           Header append Vary Accept env=REDIRECT_avif

           <FilesMatch ".(webp)$">
               Header set Cache-Control "max-age=31536000, public"
           </FilesMatch>
           </IfModule>
           <IfModule mod_mime.c>
               AddType image/avif .avif
           </IfModule>
                 ';

      $webp_rules = '
           <IfModule mod_rewrite.c>
             RewriteEngine On
             ##### TRY FIRST the file appended with .webp (ex. test.jpg.webp) #####
             # Is the browser Chrome?
             RewriteCond %{HTTP_USER_AGENT} Chrome [OR]
             # OR Is this request from Page Speed
             RewriteCond %{HTTP_USER_AGENT} "Google Page Speed Insights" [OR]
             # OR does this browser explicitly support webp
             RewriteCond %{HTTP_ACCEPT} image/webp
             # AND NOT MS EDGE 42/17 - doesnt work.
             RewriteCond %{HTTP_USER_AGENT} !Edge/17
             # AND is the request a jpg, png, or gif?
             RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png|gif)$
             # AND does a .ext.webp image exist?
             RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.webp -f
             # THEN send the webp image and set the env var webp
             RewriteRule ^(.+)$ $1.webp [NC,T=image/webp,E=webp,L]
             ##### IF NOT, try the file with replaced extension (test.webp) #####
             RewriteCond %{HTTP_USER_AGENT} Chrome [OR]
             RewriteCond %{HTTP_USER_AGENT} "Google Page Speed Insights" [OR]
             RewriteCond %{HTTP_ACCEPT} image/webp
             RewriteCond %{HTTP_USER_AGENT} !Edge/17
             # AND is the request a jpg, png, or gif? (also grab the basepath %1 to match in the next rule)
             RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png|gif)$
             # AND does a .webp image exist?
             RewriteCond %{DOCUMENT_ROOT}/%1.webp -f
             # THEN send the webp image and set the env var webp
             RewriteRule (.+)\.(?:jpe?g|png|gif)$ $1.webp [NC,T=image/webp,E=webp,L]
           </IfModule>
           <IfModule mod_headers.c>
             # If REDIRECT_webp env var exists, append Accept to the Vary header
             Header append Vary Accept env=REDIRECT_webp
             <FilesMatch ".(avif)$">
               Header set Cache-Control "max-age=31536000, public"
             </FilesMatch>
           </IfModule>
           <IfModule mod_mime.c>
             AddType image/webp .webp
           </IfModule>
           ';

      $rules = '';
      //    if ($avif)
      $rules .= $avif_rules;
      //  if ($webp)
      $rules .= $webp_rules;

      insert_with_markers(get_home_path() . '.htaccess', 'ShortPixelWebp', $rules);

      /** In uploads and on, it needs Inherit. Otherwise things such as the 404 error page will not be loaded properly
       * since the WP rewrite will not be active at that point (overruled) **/
      $deepOptions = array(
        'uploads' => array('useInherit' => true),
        'wp_content' => array('useInherit' => true)
      );
      $deepOptionsFiltered = apply_filters('shortpixel/install/write_deep_htaccess', $deepOptions);

      // Previous filter used a boolean. This is backward compat.
      if (true === $deepOptionsFiltered) {
        $deepOptionsFiltered = $deepOptions;
      } elseif (false === $deepOptionsFiltered) {
        return;
      }

      if (is_array($deepOptionsFiltered)) {
        foreach ($deepOptionsFiltered as $name => $options) {
          $inherit = isset($options['useInherit'])  ? $options['useInherit'] : true; // default to true.


          if (true === $inherit) {
            $deepRules = str_replace('RewriteEngine On', 'RewriteEngine On' . PHP_EOL . 'RewriteOptions Inherit', $rules);
          } else {
            $deepRules = $rules;
          }

          if ('uploads' === $name) {
            insert_with_markers($upload_base . '.htaccess', 'ShortPixelWebp', $deepRules);
          } elseif ('wp_content' === $name) {
            insert_with_markers(trailingslashit(WP_CONTENT_DIR) . '.htaccess', 'ShortPixelWebp', $deepRules);
          }
        }
      }
    }
  } // alter htaccess
} // class
