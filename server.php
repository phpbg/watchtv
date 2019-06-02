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

// TODO ADJUST log level for release
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


// Check and select tuner
$promises = [];
$dvbScan = new \PhpBg\WatchTv\ProcessAdapter\DvbscanProcessAdapter($dvbContext->loop, $dvbContext->logger, $dvbContext->channels);
$promises[] = $dvbScan->works()->then(function($works) use ($dvbScan) {
    if ($works) {
        $dvbScan->getLogger()->notice("dvb-scan is present");
    } else {
        $dvbScan->getLogger()->notice("dvb-scan is **not** present");
        $dvbScan->getLogger()->notice("dvb-scan is only required you you want to scan for channels");
        $dvbScan->getLogger()->notice("Setup hints (if necessary):", $dvbScan->getSetupHint());
    }
});

$dvbZap = new \PhpBg\WatchTv\ProcessAdapter\DvbzapProcessAdapter($dvbContext->loop, $dvbContext->logger, $dvbContext->channels);
$promises[] = $dvbZap->works()->then(function($works) use ($dvbZap, $dvbContext) {
    if ($works) {
        $dvbZap->getLogger()->notice("dvb-zap is present");
        $dvbContext->tunerProcessAdapter = $dvbZap;
        $dvbContext->tsStreamFactory->setTunerProcessAdapter($dvbZap);
    } else {
        $dvbZap->getLogger()->notice("dvb-zap is **not** present");
        $dvbZap->getLogger()->notice("dvb-zap is a tuner, it is required to play channels. Install it if you don't have another one");
        $dvbZap->getLogger()->notice("Setup hints (if necessary):", $dvbZap->getSetupHint());
    }
});

$dvbJet = new \PhpBg\WatchTv\ProcessAdapter\DvbjetProcessAdapter($dvbContext->loop, $dvbContext->logger);
$promises[] = $dvbJet->works()->then(function($works) use ($dvbJet, $dvbContext) {
    if ($works) {
        $dvbJet->getLogger()->notice("dvbjet is present");
        $dvbContext->tunerProcessAdapter = $dvbJet;
        $dvbContext->tsStreamFactory->setTunerProcessAdapter($dvbJet);
    } else {
        $dvbJet->getLogger()->notice("dvbjet is **not** present");
        $dvbJet->getLogger()->notice("dvbjet is a tuner, it is required to play channels. Install it if you don't have another one");
        $dvbJet->getLogger()->notice("Setup hints (if necessary):", $dvbJet->getSetupHint());
    }
});

\React\Promise\all($promises)->then(function() use($dvbContext) {
    if (! isset($dvbContext->tunerProcessAdapter)) {
        $dvbContext->logger->error("No tuner is installed on your system. Please install one and restart");
    } else {
        // Start EPG Grabber
        $dvbContext->epgGrabber->grab();
    }
});


// Start loop
if (extension_loaded('xdebug')) {
    $dvbContext->logger->warning('The "xdebug" extension is loaded, this has a major impact on performance.');
}
$dvbContext->logger->notice("Now just open your browser and browse http://localhost:{$dvbContext->httpPort} Replace localhost with your server ip address if browsing from local network");
$loop->run();