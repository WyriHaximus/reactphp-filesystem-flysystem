<?php

namespace WyriHaximus\React\Filesystem\Flysystem;

use League\Flysystem\FilesystemInterface as FlysystemInterface;
use React\EventLoop\LoopInterface;
use React\Filesystem\AdapterInterface;
use React\Filesystem\CallInvokerInterface;
use React\Filesystem\Node\NodeInterface;
use React\Filesystem\FilesystemInterface;
use React\Filesystem\MappedTypeDetector;
use React\Filesystem\ObjectStream;
use React\Filesystem\OpenFileLimiter;
use React\Filesystem\NotSupportedException;
use React\Filesystem\TypeDetectorInterface;
use React\Promise\FulfilledPromise;
use React\Promise\RejectedPromise;
use React\Stream\BufferedSink;
use React\Stream\Util;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Factory;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use WyriHaximus\React\ChildProcess\Pool\Factory\Flexible;
use WyriHaximus\React\ChildProcess\Pool\PoolInterface;
use WyriHaximus\React\ChildProcess\Pool\WorkerInterface;

class FlysystemAdapter implements AdapterInterface
{
    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var FilesystemInterface
     */
    protected $filesystem;

    /**
     * @var PoolInterface
     */
    protected $pool;

    /**
     * @var array
     */
    protected $fileDescriptors = [];

    /**
     * @var TypeDetectorInterface[]
     */
    protected $typeDetectors;

    /**
     * @var FlysystemInterface
     */
    protected $flysystem;

    /**
     * @var CallInvokerInterface
     */
    protected $invoker;

    /**
     * @var array
     */
    protected $options = [
        'lsFlags' => SCANDIR_SORT_NONE,
    ];

    /**
     * Adapter constructor.
     * @param LoopInterface $loop
     * @param array $options
     */
    public function __construct(LoopInterface $loop, array $options = [], FlysystemInterface $flysystem = null)
    {
        $this->loop = $loop;

        $this->invoker = \React\Filesystem\getInvoker($this, $options, 'invoker', 'React\Filesystem\InstantInvoker');
        $this->openFileLimiter = new OpenFileLimiter(\React\Filesystem\getOpenFileLimit($options));

        Flexible::createFromClass(Worker::class, $loop, [
            'min_size' => 0,
            'max_size' => 50,
        ])->then(function (PoolInterface $pool) {
            $this->pool = $pool;
        });

        Util::forwardEvents($this->pool, $this, ['error']);

        $this->pool->on('worker', function (WorkerInterface $worker) {
            $worker->rpc(Factory::rpc('setFlysystem', [
                'flysystem' => serialize($this->flysystem),
            ]));
        });

        $this->options = array_merge_recursive($this->options, $options);

        $this->flysystem = $flysystem;
    }

    /**
     * @return LoopInterface
     */
    public function getLoop()
    {
        return $this->loop;
    }

    /**
     * {@inheritDoc}
     */
    public function setFilesystem(FilesystemInterface $filesystem)
    {
        $this->filesystem = $filesystem;

        $this->typeDetectors = [
            MappedTypeDetector::createDefault($this->filesystem),
        ];
    }

    /**
     * @param CallInvokerInterface $invoker
     * @return void
     */
    public function setInvoker(CallInvokerInterface $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * @param string $function
     * @param array $args
     * @param int $errorResultCode
     * @return \React\Promise\Promise
     */
    public function callFilesystem($function, $args, $errorResultCode = -1)
    {
        return $this->pool->rpc(Factory::rpc($function, $args))->then(function (Payload $payload) {
            return \React\Promise\resolve($payload->getPayload());
        });
    }

    /**
     * @param string $path
     * @param $mode
     * @return \React\Promise\PromiseInterface
     */
    public function mkdir($path, $mode = self::CREATION_MODE)
    {
        return new RejectedPromise(new NotSupportedException());
    }

    /**
     * @param string $path
     * @return \React\Promise\PromiseInterface
     */
    public function rmdir($path)
    {
        return new RejectedPromise(new NotSupportedException());
    }

    /**
     * @param string $filename
     * @return \React\Promise\PromiseInterface
     */
    public function unlink($filename)
    {
        return new RejectedPromise(new NotSupportedException());
    }

    /**
     * @param string $path
     * @param int $mode
     * @return \React\Promise\PromiseInterface
     */
    public function chmod($path, $mode)
    {
        return new RejectedPromise(new NotSupportedException());
    }

    /**
     * @param string $path
     * @param int $uid
     * @param int $gid
     * @return \React\Promise\PromiseInterface
     */
    public function chown($path, $uid, $gid)
    {
        return new RejectedPromise(new NotSupportedException());
    }

    /**
     * @param string $filename
     * @return \React\Promise\PromiseInterface
     */
    public function stat($filename)
    {
        return $this->invoker->invokeCall('stat', [
            'path' => $filename,
        ])->then(function ($stat) {
            $stat['atime'] = new \DateTime('@' .$stat['atime']);
            $stat['mtime'] = new \DateTime('@' .$stat['mtime']);
            $stat['ctime'] = new \DateTime('@' .$stat['ctime']);
            return \React\Promise\resolve($stat);
        });
    }

    /**
     * @param string $path
     * @return \React\Promise\PromiseInterface
     */
    public function ls($path)
    {
        $stream = new ObjectStream();

        $this->invoker->invokeCall('readdir', [
            'flysystem' => serialize($this->flysystem),
            'path' => $path,
            'flags' => $this->options['lsFlags'],
        ])->then(function ($result) use ($stream) {
            $this->processLsContents($result, $stream);
        });

        return $stream;
    }

    protected function processLsContents($result, ObjectStream $stream)
    {
        $promises = [];
        foreach ($result as $entry) {
            $node = [
                'path' => $entry['path'],
                'type' => $entry['type'],
            ];
            $promises[] = \React\Filesystem\detectType($this->typeDetectors, $node)->then(function (NodeInterface $node) use ($stream) {
                $stream->write($node);

                return new FulfilledPromise();
            });
        }

        \React\Promise\all($promises)->then(function () use ($stream) {
            $stream->close();
        });
    }

    /**
     * @param string $path
     * @param $mode
     * @return \React\Promise\PromiseInterface
     */
    public function touch($path, $mode = self::CREATION_MODE)
    {
        return new RejectedPromise(new NotSupportedException());
    }

    /**
     * @param string $path
     * @param string $flags
     * @param $mode
     * @return \React\Promise\PromiseInterface
     */
    public function open($path, $flags, $mode = self::CREATION_MODE)
    {
        if (strpos($flags, 'r') !== false) {
            return $this->openRead($path);
        }

        if (strpos($flags, 'w') !== false) {
            return $this->openWrite($path);
        }

        throw new \InvalidArgumentException('Open must be used with read or write flag');
    }

    protected function openRead($path)
    {
        $stream = new ThroughStream();
        $this->invoker->invokeCall('read', [
            'flysystem' => serialize($this->flysystem),
            'path' => $path,
        ])->then(function ($result) use ($stream) {
            $stream->end(unserialize(base64_decode($result['contents'])));
        });
        return \React\Promise\resolve($stream);
    }

    protected function openWrite($path)
    {
        $stream = new ThroughStream();
        BufferedSink::createPromise($stream)->then(function ($contents) use ($path) {
            $this->invoker->invokeCall('write', [
                'flysystem' => serialize($this->flysystem),
                'path' => $path,
                'contents' => base64_encode($contents),
            ]);
        });
        return \React\Promise\resolve($stream);
    }

    /**
     * @param $fileDescriptor
     * @param int $length
     * @param int $offset
     * @return \React\Promise\PromiseInterface
     */
    public function read($fileDescriptor, $length, $offset)
    {
        return new RejectedPromise(new NotSupportedException());
    }

    /**
     * @param $fileDescriptor
     * @param string $data
     * @param int $length
     * @param int $offset
     * @return \React\Promise\PromiseInterface
     */
    public function write($fileDescriptor, $data, $length, $offset)
    {
        return new RejectedPromise(new NotSupportedException());
    }

    /**
     * @param resource $fd
     * @return \React\Promise\PromiseInterface
     */
    public function close($fd)
    {
        return new FulfilledPromise(new NotSupportedException());
    }

    /**
     * @param string $fromPath
     * @param string $toPath
     * @return \React\Promise\PromiseInterface
     */
    public function rename($fromPath, $toPath)
    {
        return $this->invoker->invokeCall('rename', [
            'from' => $fromPath,
            'to' => $toPath,
        ]);
    }

    /**
     * @param string $path
     * @return \React\Promise\PromiseInterface
     */
    public function readlink($path)
    {
        return new RejectedPromise(new NotSupportedException());
    }

    /**
     * @param string $fromPath
     * @param string $toPath
     * @return \React\Promise\PromiseInterface
     */
    public function symlink($fromPath, $toPath)
    {
        return new RejectedPromise(new NotSupportedException());
    }

    /**
     * @inheritDoc
     */
    public function detectType($path)
    {
        return \React\Filesystem\detectType($this->typeDetectors, [
            'path' => $path,
        ]);
    }
}
