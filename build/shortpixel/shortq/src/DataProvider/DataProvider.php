<?php
namespace ShortPixel\ShortQ\DataProvider;
use ShortPixel\ShortQ\Item as Item;


/* DataProvider handles where the data is stored, and retrieval upon queue request
*
* DataProvider is responsible for creating it's own environment, and cleanup when uninstall is being called.
*
*/
interface DataProvider
{

  function __construct($pluginSlug, $queueName);

  //function add($items);
  function enqueue($items);
  function dequeue($args); // @return Items removed from queue and set to status. Returns Item Object
  function alterqueue($args); // @return Item Count / Boolean . Mass alteration of queue.
  function itemUpdate(Item $item, $new_status);
  function getItem($item_id);

  // Returns number of items left in Queue.
  function itemCount($mode = 'waiting');

  function install();
  function uninstall();
}
