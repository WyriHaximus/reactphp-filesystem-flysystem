<?php

/**
 * The package league/flysystem-webdav is required for this example
 */

use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\WebDAV\WebDAVAdapter;
use React\EventLoop\Factory;
use React\Filesystem\Filesystem;
use Sabre\DAV\Client;
use WyriHaximus\React\Filesystem\Flysystem\FlysystemAdapter;

require dirname(__DIR__) . '/vendor/autoload.php';

$loop = Factory::create();

$loop->futureTick(function () use ($loop) {
    $adapter = new WebDAVAdapter(new Client([
        'baseUri' => 'https://YOURSTACK.stackstorage.com/remote.php/webdav',
        'userName' => 'USERNAME',
        'password' => 'PASSWORD',
    ]));
    $adapter->setPathPrefix('/remote.php/webdav');
    $flysystem = new Flysystem($adapter);
    $filesystem = Filesystem::createFromAdapter(new FlysystemAdapter($loop, [], $flysystem));
    $filesystem->dir('')->ls()->then(function ($nodes) {
        foreach ($nodes as $node) {
            echo $node->getPath(), PHP_EOL;
        }
    }, function ($e) {
        var_export($e);
    });
});

$loop->run();
