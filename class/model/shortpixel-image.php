<?php
// NOT FOR COMMIT 
namespace shortPixel;

/* Image class.
*
*
* - Represents a -single- image.
* - Can handle any type
* - Usually controllers would use a collection of images
* -
*
*/
class shortPixelImage
{

  protected $meta; // MetaFacade

  public function __construct($path)
  {

  }


}

/*
// do this before putting the meta down, since maybeDump check for last timestamp
$URLsAndPATHs = $itemHandler->getURLsAndPATHs(false);
$this->maybeDumpFromProcessedOnServer($itemHandler, $URLsAndPATHs);

*/
