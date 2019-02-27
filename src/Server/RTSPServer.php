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

use PhpBg\Rtsp\Message\Enum\RequestMethod;
use PhpBg\Rtsp\Message\Enum\RtspVersion;
use PhpBg\Rtsp\Message\MessageFactory;
use PhpBg\Rtsp\Message\Request;
use PhpBg\Rtsp\Message\Response;
use PhpBg\Rtsp\Middleware\AutoContentLength;
use PhpBg\Rtsp\Middleware\AutoCseq;
use PhpBg\Rtsp\Middleware\Log;
use PhpBg\Rtsp\Middleware\MiddlewareStack;
use PhpBg\Rtsp\Server;
use PhpBg\WatchTv\Dvb\MaxProcessReachedException;
use PhpBg\WatchTv\Dvb\TSStream;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;

class RTSPServer
{
    private $dvbContext;
    private $sessions;

    public function __construct(Context $dvbContext)
    {
        $socket = new \React\Socket\TcpServer("0.0.0.0:{$dvbContext->rtspPort}", $dvbContext->loop);

        $this->sessions = [];
        $this->dvbContext = $dvbContext;

        $stack = [
            new Log($this->dvbContext->logger),
            new AutoCseq(),
            new AutoContentLength(),
            [$this, 'routeHandler']
        ];
        $handler = new MiddlewareStack($stack);
        $rtspServer = new Server($handler);
        $rtspServer->on('error', function (\Exception $e) {
            $this->dvbContext->logger->error("Error", ['exception' => $e]);
        });
        $rtspServer->listen($socket);
    }

    /**
     * Route all RTSP requests
     * @param Request $request
     * @param ConnectionInterface $connection
     * @return Response|PromiseInterface
     */
    public function routeHandler(Request $request, ConnectionInterface $connection)
    {
        switch ($request->method) {
            case RequestMethod::OPTIONS:
                return $this->options($request);

            case RequestMethod::DESCRIBE:
                return $this->describe();

            case RequestMethod::SETUP:
                return $this->setup($request, $connection);

            case RequestMethod::PLAY:
                return $this->play($request);

            case RequestMethod::TEARDOWN:
                return $this->teardown($request);

            default:
                $response = MessageFactory::response();
                $response->statusCode = 500;
                $response->reasonPhrase = 'not supported';
                return $response;
        }
    }

    /**
     * Handle OPTIONS method
     *
     * @param Request $request
     * @return Response $response
     */
    private function options(Request $request): Response
    {
        $response = MessageFactory::response();
        if ($request->hasHeader('session')) {
            $sessionId = (int)$request->getHeader('session');
            if (!isset($this->sessions[$sessionId])) {
                $response->statusCode = 454;
                $response->reasonPhrase = 'Session Not Found';
                return $response;
            }
            $session = $this->sessions[$sessionId];
            $session->keepAlive();
        }
        $response->setHeader('public', 'SETUP, TEARDOWN, PLAY');
        return $response;
    }

    /**
     * Handle DESCRIBE method
     * @return Response
     */
    private function describe(): Response
    {
        $response = MessageFactory::response();
        $startTime = time() + 2208988800;
        $response->body = "v=0\r\no=- 0 $startTime IN IP4 0.0.0.0\r\ns=bar\r\nt=$startTime 0\r\nm=video 0 udp 33\r\n";
        $response->setHeader('content-type', 'application/sdp');
        return $response;
    }

    /**
     * Handle SETUP method
     *
     * @param Request $request
     * @param ConnectionInterface $connection
     * @return PromiseInterface
     */
    private function setup(Request $request, ConnectionInterface $connection): PromiseInterface
    {
        return new Promise(function(callable $resolve, callable $reject) use ($request, $connection) {
            $response = MessageFactory::response();
            if (!$request->hasHeader('transport')) {
                $response->statusCode = 400;
                $response->reasonPhrase = 'Missing transport header';
                return $resolve($response);
            }
            $transportHeader = $request->getHeader('transport');
            $transports = explode(',', $transportHeader);
            $selectedTransport = null;
            foreach ($transports as $transport) {
                if ($selectedTransport !== null) {
                    break;
                }
                $transportParameters = explode(';', $transport);
                if (!in_array('unicast', $transportParameters)) {
                    continue;
                }
                foreach ($transportParameters as $transportParameter) {
                    if (strpos($transportParameter, 'interleaved') === 0) {
                        // Accept tcp interleaved streaming
                        $selectedTransport = $transport;
                        $isTcpInterleaved = true;
                        break;
                    }
                    if (strpos($transportParameter, 'client_port') === 0) {
                        /**
                         * This code block allows UDP streaming
                         * Normally this would probably require TS to RTP conversion, plus opening as many sockets as streams
                         * VLC seems to be happy with this, and does not work with TCP, so let's accept it as a fallback
                         */
                        $selectedTransport = $transport;
                        $portRange = substr($transportParameter, 12);
                        $ports = explode('-', $portRange);
                        $rtpPort = $ports[0];
                        $rtcpPort = $ports[1];
                        break;
                    }
                }
            }
            if ($selectedTransport === null) {
                $response->statusCode = 461;
                $response->reasonPhrase = 'No compatible transport founds';
                return $resolve($response);
            }
            $response->setHeader('transport', $selectedTransport);
            $sessionId = rand(10000000, 99999999);
            $response->setHeader('session', $sessionId);
            $session = new Session($sessionId, $this->dvbContext->loop, $connection, $this->dvbContext->logger);
            $session->proto = $isTcpInterleaved ? 'TCP' : 'UDP';
            $session->ports = [$rtpPort, $rtcpPort];
            $address = $connection->getRemoteAddress();
            $session->clientIp = trim(parse_url($address, PHP_URL_HOST), '[]');
            $session->isInterleaved = $isTcpInterleaved;
            $session->transport = $selectedTransport;

            $uri = trim($request->uri, '/');
            $resourcePath = explode('/', $uri);
            $channelServiceId = array_pop($resourcePath);
            $session->uri = $uri;
            $pids = $this->dvbContext->channels->getPidsByServiceId($channelServiceId);
            $tsStreamPromise = $this->dvbContext->tsStreamFactory->getTsStream($channelServiceId);
            $tsStreamPromise
                ->otherwise(function (MaxProcessReachedException $e) use ($resolve) {
                    $this->dvbContext->logger->info("Cannot start a new process");
                    $response = MessageFactory::response(500, [], null, "Internal server error");
                    return $resolve($response);
                })
                ->otherwise(function (\Throwable $e) use ($reject) {
                    return $reject($e);
                });
            return $tsStreamPromise->then(function(TSStream $tsstream) use ($session, $pids, $connection, $resolve, $response) {
                $tsstream->addClient($session, $pids);
                $this->sessions[$session->id] = $session;
                $session->on('close', function ($serverTeardown) use ($session, $connection) {
                    $origin = $serverTeardown ? 'server' : 'client';
                    $this->dvbContext->logger->info("Session {$session->id} teardown originated by {$origin}");
                    unset($this->sessions[$session->id]);
                    if ($serverTeardown && $connection->isWritable()) {
                        //Emitting teardown originated from server is RTSP 2.0 only, but whatever... :-)
                        $response = new Request();
                        $response->method = RequestMethod::TEARDOWN;
                        $response->rtspVersion = RtspVersion::RTSP10;
                        $response->uri = $session->uri;
                        $response->setHeader('session', $session->id);
                        $this->dvbContext->logger->debug("Response:\r\n$response");
                        $connection->end($response->toTransport());
                    }
                });
                $session->on('error', function (\Exception $e) {
                    $this->dvbContext->logger->error("Error", ['exception' => $e]);
                });

                return $resolve($response);
            });
        });
    }

    /**
     * Handle PLAY method
     *
     * @param Request $request
     */
    private function play(Request $request): Response
    {
        $response = MessageFactory::response();
        if (!$request->hasHeader('session')) {
            $response->statusCode = 454;
            $response->reasonPhrase = 'Missing session header';
            return $response;
        }
        $sessionId = (int)$request->getHeader('session');
        if (!isset($this->sessions[$sessionId])) {
            $response->statusCode = 454;
            $response->reasonPhrase = 'Session Not Found';
            return $response;
        }
        $session = $this->sessions[$sessionId];
        $session->play();
        return $response;
    }

    /**
     * Handle TEARDOWN method
     *
     * @param Request $request
     * @return Response
     */
    private function teardown(Request $request): Response
    {
        $response = MessageFactory::response();
        if (!$request->hasHeader('session')) {
            $response->statusCode = 454;
            $response->reasonPhrase = 'Missing session header';
            return $response;
        }
        $sessionId = (int)$request->getHeader('session');
        if (!isset($this->sessions[$sessionId])) {
            $response->statusCode = 454;
            $response->reasonPhrase = 'Session Not Found';
            return $response;
        }
        /**
         * @var Session $session
         */
        $session = $this->sessions[$sessionId];
        $session->teardown();
        return $response;
    }
}