<?php

namespace WyriHaximus\React\Filesystem\Flysystem;

use League\Flysystem\Filesystem;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use WyriHaximus\React\ChildProcess\Messenger\ChildInterface;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;

class Worker implements ChildInterface
{
    /**
     * @var Filesystem
     */
    protected $flysystem;

    /**
     * @param Messenger $messenger
     * @param LoopInterface $loop
     * @return static
     */
    public static function create(Messenger $messenger, LoopInterface $loop)
    {
        return new static($messenger);
    }


    /**
     * Process constructor.
     * @param Messenger $messenger
     */
    protected function __construct(Messenger $messenger)
    {
        $messenger->registerRpc('setFlysystem', [$this, 'setFlysystem']);
        $messenger->registerRpc('unlink', [$this, 'unlink']);
        $messenger->registerRpc('stat', [$this, 'stat']);
        $messenger->registerRpc('readdir', [$this, 'readdir']);
        $messenger->registerRpc('read', [$this, 'read']);
        $messenger->registerRpc('write', [$this, 'write']);
        $messenger->registerRpc('rename', [$this, 'rename']);
    }

    /**
     * @param Payload $payload
     * @param Messenger $messenger
     * @return PromiseInterface
     */
    public function setFlysystem(Payload $payload, Messenger $messenger)
    {
        $this->flysystem = unserialize($payload['flysystem']);

        return \React\Promise\resolve([]);
    }

    /**
     * @param Payload $payload
     * @param Messenger $messenger
     * @return PromiseInterface
     */
    public function unlink(Payload $payload, Messenger $messenger)
    {
        return \React\Promise\resolve([
            'deleted' => $this->flysystem->delete($payload['path']),
        ]);
    }

    /**
     * @param Payload $payload
     * @param Messenger $messenger
     * @return PromiseInterface
     */
    public function stat(Payload $payload, Messenger $messenger)
    {
        $stat = $this->flysystem->getMetadata($payload['path']);
        return \React\Promise\resolve([
            'size' => $stat['size'],
            'atime' => $stat['timestamp'],
            'mtime' => $stat['timestamp'],
            'ctime' => $stat['timestamp'],
        ]);
    }

    /**
     * @param Payload $payload
     * @param Messenger $messenger
     * @return PromiseInterface
     */
    public function readdir(Payload $payload, Messenger $messenger)
    {
        return \React\Promise\resolve($this->flysystem->listContents($payload['path']));
    }

    /**
     * @param Payload $payload
     * @param Messenger $messenger
     * @return PromiseInterface
     */
    public function read(Payload $payload, Messenger $messenger)
    {
        return \React\Promise\resolve([
            'contents' => base64_encode(serialize($this->flysystem->read($payload['path']))),
        ]);
    }

    /**
     * @param Payload $payload
     * @param Messenger $messenger
     * @return PromiseInterface
     */
    public function write(Payload $payload, Messenger $messenger)
    {
        return \React\Promise\resolve([
            'written' => $this->flysystem->write($payload['path'], base64_decode($payload['contents'])),
        ]);
    }

    /**
     * @param Payload $payload
     * @param Messenger $messenger
     * @return PromiseInterface
     */
    public function rename(Payload $payload, Messenger $messenger)
    {
        return \React\Promise\resolve([
            'renamed' => $this->flysystem->rename($payload['from'], $payload['to']),
        ]);
    }

}
