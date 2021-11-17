<?php
namespace ShortPixel\Model;

use ShortPixel\Controller\ResponseController as ResponseController;


class ResponseModel
{
    public $message;
    public $code = 1;
    public $priority = 1;
    public $id;
		public $filename; // image or codefile that the result of this annoyance.

//    protected $actions; // might be tricky to generate

    const RESPONSE_ACTION = 1; // when an action has been performed
    const RESPONSE_SUCCESS = 2; // not sure this one is needed
    const RESPONSE_ERROR = 10;
    const RESPONSE_WARNING = 11;
    const RESPONSE_ERROR_DELAY = 12; // when an error is serious enough to delay things.

    public function __construct()
    {

    }

    public function __get($prop)
    {
       if (property_exists($this, $prop))
        return $this->$prop;
       else
        return false;
    }

    public function is($name)
    {
        switch($name)
        {
          case 'error':
            return ($this->code == self::RESPONSE_ERROR);
          break;
          case 'warning':
            return ($this->code == self::RESPONSE_ERROR);
          case 'success':
          default:
            return ($this->code == self::RESPONSE_SUCCESS);
          break;
        }
    }

    public function withMessage($message)
    {
       $this->message = $message;
       return $this->chainIt();
    }
		public function inFile($filename)
		{
			 $this->filename = $filename;
			 return $this->chainIt();
		}
    //public function withCode // asImportant? asMessage ? asSuccess?
    public function asMediaItem($id)
    {
        $this->id = $id;
        return $this->chainIt();
    }
    public function asCustomItem($id)
    {
       $this->id = $id;
       return $this->chainIt();
    }

		public function asItem($id, $type = 'media')
		{
			  if ($type == 'media')
					 return $this->asMediaItem($id);
				else
					 return $this->asCustomItem($id);
		}
    public function asSuccess()
    {
        $this->code = self::RESPONSE_SUCCESS;
        return $this->chainIt();
    }
    public function asImportant()
    {
        $this->priority = 10;
        return $this->chainIt();
    }

    public function asError()
    {
        $this->code = self::RESPONSE_ERROR;
        return $this->chainIt();
    }

    private function chainIt()
    {
        return ResponseController::updateModel($this);
    }


}
