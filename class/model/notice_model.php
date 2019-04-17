<?php

namespace ShortPixel;


class NoticeModel // extends Model
{
  protected $message;
  protected $code;

  public function __construct()
  {

  }

  public function add($msg)
  {
      $this->message = $msg;
  }

  // @todo Transient save, since that is used in some parts. 
  // save
  // load


}
