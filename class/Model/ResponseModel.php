<?php
namespace ShortPixel\Model;

use ShortPixel\Controller\ResponseController as ResponseController;


class ResponseModel
{

	// Identification for Item.
	public $item_id;
	public $item_type;

	// General item variables
	public $fileName;
	public $is_error;
	public $is_done;

	// Images being processed variables. From APIController
	public $tries;
	public $images_done;
	public $images_waiting;
	public $images_total;

//	public $queueName;


	/**
	*
	* @param $item_id int  The attachment_id of the item in process
	*	@param $item_type string  item type: media or custom.
	*
	**/
	public function __construct($item_id, $item_type)
	{
			$this->item_id = $item_id;
			$this->item_type = $item_type; // media or custum
	}




}
