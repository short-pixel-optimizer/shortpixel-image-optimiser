<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ResponseController as ResponseController;


/**
 * Data container carrying the outcome of a queue item's processing round.
 *
 * Populated by the queue / API layer and read by ResponseController to decide
 * what message and status to surface in the admin UI. Intentionally has no
 * behaviour — all fields are public and set directly by producers.
 *
 * @package ShortPixel\Model
 */
class ResponseModel
{

	// Identification for Item.
	/** @var int Attachment / custom-image ID this response describes. */
	public $item_id;
	/** @var string Item type; set by the queue — 'media' or 'custom'. */
	public $item_type;

	// General item variables
	/** @var string|null Filename of the item, when applicable. */
	public $fileName;
	/** @var bool|null True when the round terminated in an error state. */
	public $is_error;
	/** @var bool|null True when there is no further work for this item. */
	public $is_done;

	/** @var int|null API status code returned by the ShortPixel API. */
	public $apiStatus;
	/** @var int|null File-status code (see ImageModel::FILE_STATUS_*). */
	public $fileStatus;

	// Images being processed variables. From APIController
	/** @var int|null Number of retry attempts made for this item so far. */
	public $tries;
	/** @var int|null Count of images already optimized within this item. */
	public $images_done;
	/** @var int|null Count of images still waiting to be processed. */
	public $images_waiting;
	/** @var int|null Total image count for this item (main + thumbnails). */
	public $images_total;

	/** @var int|null Optional issue-type code (see ResponseController::ISSUE_*). */
	public $issue_type;
	/** @var string|null Base message text; final wording is decided by ResponseController. */
 	public $message;
	/** @var string|null Custom operation label (e.g. 'migrate'). */
  	public $action;


	/**
	 * Constructor.
	 *
	 * Assigns the two identity fields and leaves everything else at PHP's
	 * declared defaults (null / uninitialised) for producers to set.
	 *
	 * @param int    $item_id   Attachment or custom-image ID being processed.
	 * @param string $item_type Item type — 'media' or 'custom'.
	 */
	public function __construct($item_id, $item_type)
	{
			$this->item_id = $item_id;
			$this->item_type = $item_type;
	}
}
