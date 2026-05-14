<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Helper\InstallHelper;
use ShortPixel\Helper\UtilHelper;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Handles the AI-generated metadata database table and WordPress post/meta synchronisation.
 *
 * Stores original, current and AI-generated values for alt text, caption,
 * description, post title and filename.  Provides processability checks and
 * CRUD operations against the custom `shortpixel_aipostmeta` table.
 *
 * @package ShortPixel\Model
 */
class AiDataModel
{
    /**
     * Primary key of the row in the AI post-meta table.
     *
     * @var int|null
     */
    protected $id;

    /**
     * WordPress attachment post ID this record belongs to.
     *
     * @var int
     */
    protected $attach_id;

    /**
     * Integer type constant (TYPE_MEDIA or TYPE_CUSTOM).
     *
     * @var int
     */
    protected $type;

    /**
     * Static in-memory cache keyed by attachment ID.
     *
     * @var array<int, AiDataModel>
     */
    protected static $models = [];

    /**
     * Field values as they existed before AI processing (loaded from DB).
     *
     * @var array<string, string|null>
     */
    protected $original = [
        'alt' => null,
        'caption' => null,
        'description' => null,
        'post_title' =>  null,
        'filebase' => null,
    ];

    /**
     * Field values currently live in WordPress (retrieved on demand).
     *
     * @var array<string, string|null>
     */
    protected $current = [
        'alt' => null,
        'caption' => null,
        'description' => null,
        'post_title' => null,
    ];

    /**
     * Field values produced by the AI API.
     *
     * @var array<string, string|null>
     */
    protected $generated = [
        'alt' => null,
        'caption' => null,
        'description' => null,
        'post_title' => null,
        'filebase' => null,
    ];

    /**
     * Overall AI status for this attachment (AI_STATUS_* constant).
     *
     * @var int
     */
    protected $status = 0;

    /**
     * Whether a row already exists in the AI post-meta table for this attachment.
     *
     * @var bool
     */
    private $has_record = false;

    /**
     * Whether AI-generated data has been produced and stored.
     *
     * @var bool
     */
    private $has_generated = false;

    /**
     * Whether $current has been populated from WordPress.
     *
     * @var bool
     */
    private $current_is_set = false;

    /**
     * Processability status code (P_* constant) set during isProcessable() checks.
     *
     * @var int
     */
    private $processable_status = 0;

    /** @var int Attachment is a media library item. */
    const TYPE_MEDIA = 1;

    /** @var int Attachment is a custom (non-media-library) item. */
    const TYPE_CUSTOM = 2;

    // Status for the whole image, in the main table.
    /** @var int No AI data has been generated yet. */
    const AI_STATUS_NOTHING = 0;

    /** @var int AI data has been generated and stored. */
    const AI_STATUS_GENERATED = 1;

    // IsProcessable statii
    /** @var int Item can be processed by AI. */
    const P_PROCESSABLE = 0;

    /** @var int AI data already exists for this item. */
    const P_ALREADYDONE = 1;

    /** @var int EXIF flag prevents AI processing. */
    const P_EXIFAI = 2;

    /** @var int File extension is not supported by AI. */
    const P_EXTENSION = 3;

    /** @var int No AI fields are configured to generate. */
    const P_NOJOB = 4;

    /** @var int No generatable fields remain after settings exclusions. */
    const P_NOFIELDS = 5;

    // Descriptive status if certain field is not generated / left alone.
    /** @var int Field was generated successfully. */
    const F_STATUS_OK = 1;

    /** @var int Field is excluded by the current AI settings. */
    const F_STATUS_EXCLUDESETTING = -3;

    /** @var int Field already has content and aiPreserve prevents overwriting. */
    const F_STATUS_PREVENTOVERRIDE = -4;


    /**
     * Load or initialise the AI data record for a given attachment.
     *
     * @param int    $attach_id WordPress attachment post ID.
     * @param string $type      Media type; currently only 'media' is supported.
     */
    public function __construct($attach_id, $type = 'media')
    {
          $this->attach_id = $attach_id;
          if ('media' == $type) // only this supported for now
          {
             $this->type = self::TYPE_MEDIA;
          }

          $this->fetchRecord($this->attach_id, $this->type);
    }

    /**
     * Load the AI post-meta row from the database into object properties.
     *
     * If the table does not yet exist, triggers a table-creation check via
     * InstallHelper.  Sets $has_record and populates $original/$generated on
     * success.
     *
     * @param int $attach_id WordPress attachment post ID.
     * @param int $type      TYPE_MEDIA or TYPE_CUSTOM constant.
     * @return void
     */
    protected function fetchRecord($attach_id, $type)
    {
           global $wpdb;
           $tableName = self::getTableName();

           $sql = ' SELECT * FROM ' . $tableName . ' where attach_id = %d and post_type = %d';
           $sql = $wpdb->prepare($sql, $attach_id, $type);

           $row = $wpdb->get_row($sql);

        if (false === $row && strpos($wpdb->last_error, 'exist') !== false) {
			InstallHelper::checkTables();
            $this->has_record = false;
			return false;
        }
        if (false == $row)
        {
            $this->has_record = false;
            return;
        }

        $originalData = $this->checkRowData($row->original_data);
        $generatedData = $this->checkRowData($row->generated_data);


        $this->id = $row->id;
        $this->has_record = true;
        $this->status = $row->status;
        $this->original = array_merge($this->original, $originalData);
        $this->generated = array_merge($this->generated, $generatedData);

    }

    /**
     * Validate and decode a JSON string from a database column.
     *
     * @param string $json Raw JSON string from the database.
     * @return array Decoded associative array, or empty array on invalid JSON.
     */
    private function checkRowData($json)
    {
        $bool = UtilHelper::ValidateJSON($json);
        if (false === $bool)
        {
             return [];
        }

        $data = json_decode($json);

        return (array) $data;

    }

    /** Get all data needed to send API for generating AI texts, depending on settings. This includes all settings minus URL
     *
     * @param array $params Optional override parameters for AI settings.
     * @return array{paramlist: array<string, array{context: mixed, chars: mixed}>, returndatalist: array<string, array<string, int>>}
     *         'paramlist' contains the API request payload; 'returndatalist' contains per-field status codes.
     */
    public function getOptimizeData($params = [])
    {
        $settings = (object) UtilHelper::getAiSettings($params);

        $ignore_fields = []; 
        $preview_only = isset($params['preview_only']) ? $params['preview_only'] : false; 

        // Ignore this on preview only (settings), otherwise we might get empty preview, which is not the point.
        if (true === $settings->aiPreserve && false === $preview_only)
        {
            $currentData = $this->getCurrentData(); 
            $ignore_fields = array_diff(array_keys( array_filter($currentData) ), []);

            $fs = \wpSPIO()->filesystem(); 
            $mediaItem = $fs->getMediaImage($this->attach_id); 

            if (false !== $mediaItem && true === $mediaItem->hasOriginal())
            {
                $mediaItem = $mediaItem->getOriginalFile(); 
            }

            $fileName = $mediaItem->getFileName(); 
            $extension = $mediaItem->getExtension(); 
            
            $fileName = str_replace('.' . $extension, '', $fileName);

            if ($currentData['post_title'] == $fileName)
            {
                if (in_array('post_title', $ignore_fields))
                {
                    $ignore_fields = array_diff($ignore_fields, ['post_title']);
                }
            }
            
            // Exception via array_diff :: post_title always overwrite because it is always filled
        }

       // $fields = ['ai_gen_alt', 'ai_gen_caption', 'ai_gen_description', 'ai_gen_filename'];
        $fields = ['alt', 'caption', 'description', 'filename', 'post_title'];

        $paramlist = [
            'languages' => $settings->ai_language,
            'context' => $settings->ai_general_context,
        ];

        if (true === $settings->ai_use_post)
        {
            $parent_title = $this->getConnectedPostTitle();
            if (false !== $parent_title && false === is_null($parent_title))
            {
                $paramlist['use_parent_post_title'] = true;
                $paramlist['parent_post_title'] = $parent_title;
            }
        }

        $returnDataList = [];
        $field_status = false; // check if there are any fields to process / not all excluded.

        foreach($fields as $field_name)
        {
            $api_name = $field_name;

            switch($api_name)
            {
                case 'description':
                    $api_name = 'image_description';
                break;
                case 'filename':
                    $api_name = 'file';
                break;
                case 'post_title':
                    $api_name = 'title';
                break;
            }


            if (false === $settings->{'ai_gen_' . $field_name})
            {
                $returnDataList[$field_name]['status'] = self::F_STATUS_EXCLUDESETTING;
                continue;
            }
            elseif (true === in_array($field_name, $ignore_fields))
            {
                $returnDataList[$field_name]['status'] = self::F_STATUS_PREVENTOVERRIDE;
            }
            else
            {
                $paramlist[$api_name] = [
                        'context' => $settings->{'ai_' . $field_name . '_context'},
                        'chars' => $settings->{'ai_limit_' . $field_name . '_chars'},
                ];
                $returnDataList[$field_name]['status']  = self::F_STATUS_OK;
                $field_status = true;
            }


        }

        if (false === $field_status)
        {
            $this->processable_status = self::P_NOJOB;
        }

        $paramlist = apply_filters('shortpixel/aidatamodel/paramlist', $paramlist, $this->attach_id);

        return ['paramlist' => $paramlist, 'returndatalist' => $returnDataList];

    }

    /**
     * Persist AI-generated data returned from the API into the database and WordPress.
     *
     * Merges the response into $generated, saves original values on first run,
     * then writes caption/description/post_title to the WP post and alt text to
     * post meta.
     *
     * @param array<string, string> $data Key/value pairs from the API response.
     * @return void
     */
    public function handleNewData($data)
    {
        // Save to Database
        foreach($data as $name => $value)
        {
             if ('original_filebase' == $name)
             {
                 $this->current['filebase'] = $value;
             }
             else
             {
                $this->generated[$name] = $value;
             }

        }

        $this->setCurrentData();

        // New Data.
        if (false === $this->has_record)
        {
            $this->original = $this->current;

            $this->status = self::AI_STATUS_GENERATED;
            $this->updateRecord();
        }
        else
        {
            // Not sure if  just categorically deny this, or some smart updater ( with more risks )
            Log::addError('New AI Data already has an entry');
        }

        // Save to WordPress
        $this->updateWPPost($this->generated);
        $this->updateWpMeta($this->generated);

    }

    /**
     * Update caption, description and post_title fields on the WordPress post object.
     *
     * Only fields that are non-empty and non-numeric are written; wp_update_post()
     * is called at most once per invocation.
     *
     * @param array<string, string|false> $data Generated field values to apply.
     * @return void
     */
    protected function updateWPPost($data)
    {
        $post = get_post($this->attach_id);
        $post_updated = false;

        if (isset($data['caption']) && false !== $data['caption'] && false === is_numeric($data['caption']))
        {
            $post->post_excerpt = $data['caption'];
            $post_updated = true;
        }

        if (isset($data['description']) && false !== $data['description'] && false === is_numeric($data['description']))
        {
            $post->post_content = $data['description'];
            $post_updated = true;
        }

        if (isset($data['post_title']) && false !== $data['post_title'] && false === is_numeric($data['post_title']))
        {
             $post->post_title = $data['post_title'];
             $post_updated = true;
        }

        if (true === $post_updated)
        {
            wp_update_post($post);
        }
    }

    /**
     * Write AI-generated alt text to the `_wp_attachment_image_alt` post meta field.
     *
     * @param array<string, string|false> $data Generated field values; uses 'alt' key.
     * @return void
     */
    protected function updateWpMeta($data)
    {
        if (isset($data['alt']) && false !== $data['alt'] && false === is_int($data['alt']))
        {
            $bool = update_post_meta($this->attach_id, '_wp_attachment_image_alt', $data['alt']);
        }
    }

    /**
     * Returns only the AI-generated field values.
     *
     * @return array<string, string|null>
     */
    public function getGeneratedData()
    {
        return $this->generated;
    }

    /**
     * Check whether the current WordPress field values differ from the generated ones.
     *
     * Compares alt, caption and description only (fields that exist in WP).
     *
     * @return bool True when at least one live value differs from its generated counterpart.
     */
    public function currentIsDifferent()
    {
         $this->setCurrentData();

         $generated = array_filter($this->generated, [$this, 'mapWPVars'], ARRAY_FILTER_USE_KEY);
         $current = array_filter($this->current, [$this, 'mapWPVars'], ARRAY_FILTER_USE_KEY);

         $diff = array_diff($generated, $current);

         if (count($diff) > 0)
         {
             return true;
         }
         return false;
    }

    /**
     * Array filter callback: returns true only for field keys that exist in WordPress.
     *
     * Used to restrict comparisons to alt, caption and description.
     *
     * @param string $key Array key to evaluate.
     * @return bool True if the key is a supported WP field, false otherwise.
     */
    private function mapWPVars($key)
    {
         $fields = ['alt', 'caption', 'description'];

         if (false === in_array($key, $fields))
         {
            return false;
         }
         return true;

    }

    /**
     * Fetch the post title of the parent post the attachment is attached to, if any.
     *
     * @return string|false The parent post's title, or false if no parent exists.
     */
    protected function getConnectedPostTitle()
    {
         $attach_id = $this->attach_id;
         $post_parent = get_post_parent($attach_id);
         if (! is_null($post_parent))
         {
            $post = get_post($post_parent);
            if (false === is_null($post))
            {
                $post_title = $post->post_title;
                return $post_title;
            }
         }

         return false;

    }

    /**
     * Populate $current with the live WordPress field values for this attachment.
     *
     * Reads alt text from post meta and caption/description/post_title from the
     * WP_Post object.  Sets $current_is_set to true when done.
     *
     * @return void
     */
    protected function setCurrentData()
    {
        $attach_id = $this->attach_id;
        $current_alt = get_post_meta($attach_id, '_wp_attachment_image_alt', true);
        $post = get_post($attach_id);

        $current_description = $post->post_content;
        $current_caption = $post->post_excerpt;
        $current_post_title = $post->post_title;

        /*$this->current = [
             'alt' => $current_alt,
             'description' => $current_description,
             'caption' => $current_caption,

        ]; */

        $this->current['alt'] = $current_alt;
        $this->current['description'] = $current_description;
        $this->current['caption'] = $current_caption;
        $this->current['post_title'] = $current_post_title;

        $this->current_is_set = true;

    }

    /**
     * Return the live WordPress field values, loading them first if not yet cached.
     *
     * @return array<string, string|null>
     */
    public function getCurrentData()
    {
          if (false === $this->current_is_set)
          {
             $this->setCurrentData();
          }

          return $this->current;
    }

    /**
     * Returns the original field values captured before AI processing.
     *
     * @return array<string, string|null>
     */
    public function getOriginalData()
    {
        return $this->original;
    }

    /**
     * Verify that any stored AI data still matches the live WordPress values.
     *
     * @return bool True if no record exists (nothing to validate), void otherwise.
     */
    public function checkStoredData()
    {
        if (false === $this->has_record)
        {
            return true;
        }

        $this->setCurrentData();

    }

    /** Function to check if on this item there is something to AI
     *
     * @return bool True if all processability conditions pass, false if any block processing.
     */
    public function isProcessable()
    {
        if (true === $this->has_record)
        {
             $this->processable_status = SELF::P_ALREADYDONE;
             return false;
        }

        // Stash here other conditions on top with && to build a big processable function
        $processable = ( $this->isExifProcesssable() && $this->isExtensionIncluded() && $this->hasSomethingGeneratable() ) ? true : false;
        return $processable;
    }


    /**
     * Check whether EXIF settings allow AI processing on this attachment.
     *
     * If the image has already been optimised, the stored keepExif value is
     * inspected; values 0-3 permit AI, while higher values (combined exif_ai
     * mode) set P_EXIFAI and return false.
     *
     * @return bool True if EXIF settings do not block AI, false otherwise.
     */
    private function isExifProcesssable()
    {
        // Change: Exif processing changed on API, allowing this - https://app.asana.com/1/18694759100379/project/1200110778640816/task/1213564895578597 
       return true; 

        /*$fs = \wpSPIO()->filesystem(); 
        $imageModel = $fs->getMediaImage($this->attach_id); 

        if (false === $imageModel->isSomethingOptimized())
        {
            return true;
        }

        $imageObj = $imageModel->getSomethingOptimized();


        $keepExif = $imageObj->getMeta('did_keepExif');

        // 2-3 are exif_ai combined settings with keep-exif. 0-1 are when default settings are used and unset / unused
        if (in_array($keepExif, [0,1,2,3]))
        {
            return true;
        }

        $this->processable_status = self::P_EXIFAI;
        return false;  */

    }

    /**
     * Return a human-readable reason why the item is not processable, or the status code.
     *
     * @param bool $returnStatus When true, returns the raw P_* integer constant instead of a string.
     * @return string|int Translated message string, or integer status code when $returnStatus is true.
     */
    public function getProcessableReason($returnStatus = false )
    {
        $message = false;

        if (true === $returnStatus)
        {
            return $this->processable_status;
        }

        switch($this->processable_status)
        {
            case self::P_PROCESSABLE:
                $message = __('AI is processable', 'shortpixel-image-optimiser');
            break;
            case self::P_ALREADYDONE:
                $message = __('This image already has generated data', 'shortpixel-image-optimiser');
            break;
            case self::P_EXIFAI:
                $message = __('Image Exif settings restrict AI usage', 'shortpixel-image-optimiser');
            break;
            case self::P_EXTENSION:
                 $message = __('File Extension not supported', 'shortpixel-image-optimiser');
            break;
            case self::P_NOJOB:
                $message = __('No fields to generate', 'shortpixel-image-optimiser');
            break;
            default:
                 $message = sprintf(__('Status %s unknown', 'shortpixel-image-optimiser'), $this->processable_status);
            break;
        }

        return $message;
    }

    /**
     * Check whether the attachment's file extension is supported by the AI feature.
     *
     * Supported extensions: png, jpeg, webp, jpg.  Sets P_EXTENSION on failure.
     *
     * @return bool True if the extension is in the supported list, false otherwise.
     */
    protected function isExtensionIncluded()
    {
        $fs = \wpSPIO()->filesystem();
        $imageModel = $fs->getMediaImage($this->attach_id);

        // Gif removed here, since we (temporarily don't support it)
        $extensions = ['png', 'jpeg', 'webp', 'jpg'];

        if (in_array($imageModel->getExtension(), $extensions))
        {
            return true;
        }

        $this->processable_status = self::P_EXTENSION;
        return false;
    }

    /**
     * Determine whether there is at least one AI field enabled for generation.
     *
     * Calls getOptimizeData() as a side-effect; if that sets P_NOJOB the method
     * returns false.
     *
     * @return bool True when at least one field can be generated, false otherwise.
     */
    protected function hasSomethingGeneratable()
    {
        $optimizeData = $this->getOptimizeData();

        if (self::P_NOJOB === $this->processable_status)
        {
             return false;

        }
        return true;
    }

    /**
     * Check whether AI data has actually been generated and stored for this attachment.
     *
     * @return bool True if a record exists and at least one generated field is non-empty.
     */
    public function isSomeThingGenerated()
    {
        if (false === $this->has_record)
        {
             return false;
        }

        if (count(array_keys(array_filter($this->generated))) > 0)
        {
             return true;
        }
        return false;
    }

    /**
     * Returns the fully qualified name of the AI post-meta database table.
     *
     * @return string Table name including the WordPress table prefix.
     */
    private static function getTableName()
    {
         global $wpdb;
         return $wpdb->prefix . 'shortpixel_aipostmeta';
    }

    /**
     * Insert or update the AI post-meta row in the database.
     *
     * Performs an INSERT when no row exists ($has_record is false) and an UPDATE
     * otherwise.  Encodes $original and $generated as JSON columns.
     *
     * @param array $data Currently unused; reserved for future partial updates.
     * @return void
     */
    protected function updateRecord($data = [])
    {
        global $wpdb;
        $is_new = is_null($this->id) ? true : false;

        $fields = [
            'attach_id' => $this->attach_id,
            'status' => $this->status,
            'generated_data' => json_encode($this->generated),
            'original_data' => json_encode($this->original),
            'tsUpdated' => UtilHelper::timestampToDB(time()),
        ];

        $format = ['%d', '%d', '%s', '%s', '%s'];

        if (false === $this->has_record)
        {
            $this->id = $wpdb->insert(self::getTableName(), $fields, $format);
            $this->has_record = true;
        }
        else
        {
            $wpdb->update(self::getTableName(),$fields, ['id' => $this->id],$format);
        }

    }

    /**
     * Migrate legacy alt-text data (from an older storage format) into this model.
     *
     * Only writes fields that are currently null so existing data is never overwritten.
     * Saves the record and marks status as AI_STATUS_GENERATED when any field is updated.
     *
     * @param array<string, string> $data Legacy data array with keys 'original_alt' and 'result_alt'.
     * @return bool True on success, false if $data is not an array.
     */
    public function migrate($data)
    {
        $updated = false;
        if (false === is_array($data))
        {
            return false;
        }

        if (is_null($this->original['alt']))
        {
            $this->original['alt'] = $data['original_alt'];
            $updated = true;
        }
        if (is_null($this->generated['alt']))
        {
            $this->generated['alt'] = $data['result_alt'];
            $updated = true;
        }

        if (true === $updated)
        {
            $this->status = self::AI_STATUS_GENERATED;
            $this->updateRecord();

        }

        return true;
    }

    /**
     * Revert an attachment's fields to their pre-AI values and remove the database record.
     *
     * Deletes the row from the AI post-meta table and restores the original values
     * to the WordPress post and post meta.
     *
     * @return void
     */
    public function revert()
    {   
        $this->onDelete(); 

        $this->updateWPPost($this->original);
        $this->updateWpMeta($this->original);
    }

    public function onDelete()
    {
        if (true === $this->has_record)
        {
            global $wpdb;
            $wpdb->delete(self::getTableName(), ['id' => $this->id], ['%s']);
        }

        $this->has_record = false; 
        self::flushModelCache($this->id);
    }

    /**
     * Return an AiDataModel instance for the most recently updated AI record.
     *
     * @return AiDataModel|false Model for the most recent attachment, or false if the table is empty.
     */
    public static function getMostRecent()
    {
        global $wpdb;
         $sql = 'SELECT attach_id FROM ' . self::getTableName() . ' order by tsUpdated desc limit 1';
         $attach_id = $wpdb->get_var($sql);

        if (false === $attach_id)
        {
             return false;
        }

        return new AiDataModel($attach_id);
    }

    /**
     * Return a (possibly cached) AiDataModel instance for a given attachment.
     *
     * @param int    $attach_id WordPress attachment post ID.
     * @param string $type      Media type; currently only 'media' is supported.
     * @return AiDataModel
     */
    public static function getModelByAttachment($attach_id, $type = 'media')
    {
        if (false === isset(self::$models[$attach_id]))
        {
             self::$models[$attach_id]  = new AiDataModel($attach_id, $type);
        }

        return self::$models[$attach_id];

    }

    /**
     * Remove a cached AiDataModel instance so the next call fetches a fresh copy.
     *
     * @param int    $attach_id WordPress attachment post ID to evict from cache.
     * @param string $type      Media type (currently unused, reserved for future use).
     * @return void
     */
    public static function flushModelCache($attach_id, $type = 'media')
    {
        if (isset(self::$models[$attach_id]))
        {
             unset(self::$models[$attach_id]);
        }
        else
        {
        }

    }


} // class
