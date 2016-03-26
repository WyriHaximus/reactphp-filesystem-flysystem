<?php

namespace WyriHaximus\React\Filesystem\Flysystem;

use React\Filesystem\Stream\GenericStreamInterface;
use React\Stream\ThroughStream as ReactThroughStream;

class ThroughStream extends ReactThroughStream implements GenericStreamInterface
{
    protected $fd;

    public function __construct()
    {
        $this->fd = uniqid();
        parent::__construct();
    }

    public function getFiledescriptor()
    {
        return $this->fd;
    }
}
