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
$logLevel = \Psr\Log\LogLevel::DEBUG;
$logFormatter = new \PhpBg\MiniHttpd\Logger\ConsoleFormatter(false);
$dvbContext->logger = new \PhpBg\MiniHttpd\Logger\Console($logLevel, 'php://stderr', $logFormatter);
$dvbContext->rootPath = __DIR__;
$dvbContext->channels = new \PhpBg\WatchTv\Dvb\Channels(__DIR__.'/channels.conf', __DIR__.'/data/dtv-scan-tables');
$dvbContext->dvbGlobalContext = new \PhpBg\DvbPsi\Context\GlobalContext();
$dvbContext->tsStreamFactory = new \PhpBg\WatchTv\Dvb\TSStreamFactory($dvbContext->logger, $dvbContext->loop, $dvbContext->channels, $dvbContext->dvbGlobalContext);
$dvbContext->epgGrabber = new \PhpBg\WatchTv\Dvb\EPGGrabber($dvbContext->loop, $dvbContext->logger, $dvbContext->channels, $dvbContext->tsStreamFactory, $dvbContext->dvbGlobalContext);


// Setup HTTP Server
$httpServer = new \PhpBg\WatchTv\Server\HTTPServer($dvbContext);


// Setup RTSP Server
$rtspServer = new \PhpBg\WatchTv\Server\RTSPServer($dvbContext);


// Start loop
if (extension_loaded('xdebug')) {
    $dvbContext->logger->warning('The "xdebug" extension is loaded, this has a major impact on performance.');
}
$dvbContext->logger->notice("Now just open your browser and browse http://localhost:{$dvbContext->httpPort} Replace localhost with your server ip address if browsing from local network");


// Start EPG Grabber
$loop->futureTick(function() use ($dvbContext) {
    $dvbContext->epgGrabber->grab();
});


$loop->run();