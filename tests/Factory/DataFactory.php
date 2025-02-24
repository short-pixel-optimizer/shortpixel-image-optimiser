<?php
namespace Tests\Factory;

/*
abstract class CreateFactory
{

    abstract public function create();
  //  abstract

    public function get()
    {
        $data = $this->create();
    }


}
*/

interface DataFactory
{

    public function set($name, $value);
    public function returnData($format) ;
    public function reset();
}
