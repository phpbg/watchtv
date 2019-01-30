<?php

// Composer autoload
$autoloaderFile = __DIR__ . '/vendor/autoload.php';
if (! is_file($autoloaderFile)) {
    echo "Please install 'composer' and run 'composer install'\r\n";
    exit(1);
}

// Custom bootstrap
ini_set('display_errors', 1);
error_reporting(E_ALL ^ E_NOTICE);
require_once $autoloaderFile;

// Create application context
$loop = React\EventLoop\Factory::create();
$dvbContext = new \PhpBg\WatchTv\Server\Context();
$dvbContext->loop = $loop;
$dvbContext->httpPort = 8080;
$dvbContext->rtspPort = 8554;
$dvbContext->logger = new \PhpBg\MiniHttpd\Logger\Console(\Psr\Log\LogLevel::DEBUG);
$dvbContext->rootPath = __DIR__;
$dvbContext->channels = new \PhpBg\WatchTv\Dvb\Channels(__DIR__.'/channels.conf', __DIR__.'/data/dtv-scan-tables');



// Setup HTTP Server
$httpServer = new \PhpBg\WatchTv\Server\HTTPServer($dvbContext);



// Setup RTSP Server
$rtspServer = new \PhpBg\WatchTv\Server\RTSPServer($dvbContext);


// Start loop
if (extension_loaded('xdebug')) {
    $dvbContext->logger->warning('The "xdebug" extension is loaded, this has a major impact on performance.');
}
$dvbContext->logger->notice("Now just open your browser and browse http://localhost:{$dvbContext->httpPort} Replace localhost with your server ip address if browsing from local network");
$loop->run();