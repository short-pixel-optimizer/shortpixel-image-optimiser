<?php
namespace ShortPixel\Queue;

abstract class Queue
{

    protected $q;

    public function __construct()
    {
       $this->loadQueue();
    }

    abstract public function isRunning();
    abstract public function hasItems();
    abstract public function loadQueue();

    


} // class
