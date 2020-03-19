<?php

/**
 * MIT License
 *
 * Copyright (c) 2019 Samuel CHEMLA
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace PhpBg\WatchTv\Server;

use PhpBg\WatchTv\Api\CheckConfiguration;
use PhpBg\WatchTv\Api\InitialScanFiles;
use PhpBg\WatchTv\Pages\Channels\Channels;
use PhpBg\WatchTv\Pages\Channels\M3u8;
use PhpBg\WatchTv\Pages\About\About;
use PhpBg\WatchTv\Pages\Configure\Configure;
use PhpBg\MiniHttpd\Model\Route;
use PhpBg\MiniHttpd\Renderer\Json;
use PhpBg\MiniHttpd\Renderer\Phtml\Phtml;
use PhpBg\MiniHttpd\ServerFactory;
use PhpBg\WatchTv\Pages\Epg\Epg;
use PhpBg\WatchTv\Pages\Guide\Guide;
use React\Socket\Server;

class HTTPServer
{
    /**
     * HTTPServer constructor.
     * @param Context $dvbContext
     * @throws \PhpBg\MiniHttpd\MimeDb\MimeDbException
     */
    public function __construct(Context $dvbContext)
    {
        $defaultRenderer = new Phtml($dvbContext->rootPath . '/src/Pages/layout.phtml', $dvbContext->logger);
        $routes = [
            '/' => new Route(new Channels($dvbContext->rtspPort, $dvbContext->channels, $dvbContext->dvbGlobalContext, $dvbContext->isRelease), $defaultRenderer),
            '/about' => new Route(new About(), $defaultRenderer),
            '/channels/m3u8' => new Route(new M3u8($dvbContext->rtspPort, $dvbContext->channels, $dvbContext->dvbGlobalContext, $dvbContext->isRelease), $defaultRenderer),
            '/configure' => new Route(new Configure($dvbContext->channels, $dvbContext->isRelease), $defaultRenderer),
            '/epg' => new Route(new Epg($dvbContext->epgGrabber, $dvbContext->channels), $defaultRenderer),
            '/guide' => new Route(new Guide($dvbContext->isRelease), $defaultRenderer),
            '/api/check-configuration' => new Route(new CheckConfiguration($dvbContext->loop, $dvbContext->logger), new Json()),
            '/api/initial-scan-files' => new Route(new InitialScanFiles($dvbContext->channels), new Json()),
            '/api/channels/get-all' => new Route([new \PhpBg\WatchTv\Api\Channels($dvbContext->channels, $dvbContext->dvbGlobalContext), 'getAll'], new Json()),
            '/api/channels/logical-numbers' => new Route([new \PhpBg\WatchTv\Api\Channels($dvbContext->channels, $dvbContext->dvbGlobalContext), 'logicalNumbers'], new Json()),
            '/api/channels/reload' => new Route([new \PhpBg\WatchTv\Api\Channels($dvbContext->channels, $dvbContext->dvbGlobalContext), 'reload'], new Json()),
            '/api/epg/get-running' => new Route([new \PhpBg\WatchTv\Api\Epg($dvbContext->epgGrabber), 'getRunning'], new Json()),
            '/api/epg/get-all' => new Route([new \PhpBg\WatchTv\Api\Epg($dvbContext->epgGrabber), 'getAll'], new Json())
        ];
        $dvbContext->routes = $routes;
        $dvbContext->publicPath = $dvbContext->rootPath . '/public';
        $dvbContext->defaultRenderer = $defaultRenderer;
        $httpServer = ServerFactory::create($dvbContext);
        $socket = new Server("tcp://0.0.0.0:{$dvbContext->httpPort}", $dvbContext->loop);
        $httpServer->listen($socket);
        $dvbContext->logger->notice("HTTP server started");
    }
}