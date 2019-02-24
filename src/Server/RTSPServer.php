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
use PhpBg\Rtsp\Message\Request;
use PhpBg\Rtsp\Message\Response;
use PhpBg\Rtsp\Server;
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

        $rtspServer = new Server(function (Request $request, ConnectionInterface $connection) {
            $this->dvbContext->logger->debug("Request:\r\n$request");

            $response = \PhpBg\Rtsp\Message\MessageFactory::response();
            $response->setHeader('cseq', $request->getHeader('cseq'));
            switch ($request->method) {
                case \PhpBg\Rtsp\Message\Enum\RequestMethod::OPTIONS:
                    $this->options($request, $connection, $response);
                    break;

                case \PhpBg\Rtsp\Message\Enum\RequestMethod::DESCRIBE:
                    $this->describe($request, $connection, $response);
                    break;

                case \PhpBg\Rtsp\Message\Enum\RequestMethod::SETUP:
                    $this->setup($request, $connection, $response);
                    break;

                case \PhpBg\Rtsp\Message\Enum\RequestMethod::PLAY:
                    $this->play($request, $connection, $response);
                    break;

                case \PhpBg\Rtsp\Message\Enum\RequestMethod::TEARDOWN:
                    $this->teardown($request, $connection, $response);
                    break;

                default:
                    $response->statusCode = 500;
                    $response->reasonPhrase = 'not supported';
            }

            if (isset($response->body)) {
                $response->setHeader('content-length', strlen($response->body));
            }
            $this->dvbContext->logger->debug("Response:\r\n$response");
            return $response;

        });
        $rtspServer->on('error', function (\Exception $e) {
            $this->dvbContext->logger->error("Error", ['exception' => $e]);
        });
        $rtspServer->listen($socket);
    }

    /**
     * Handle OPTIONS method
     *
     * @param Request $request
     * @param ConnectionInterface $connection
     * @param Response $response
     */
    private function options(Request $request, ConnectionInterface $connection, Response $response)
    {
        if ($request->hasHeader('session')) {
            $sessionId = (int)$request->getHeader('session');
            if (!isset($this->sessions[$sessionId])) {
                $response->statusCode = 454;
                $response->reasonPhrase = 'Session Not Found';
                return;
            }
            $session = $this->sessions[$sessionId];
            $session->keepAlive();
        }
        $response->setHeader('public', 'SETUP, TEARDOWN, PLAY');
    }

    /**
     * Handle DESCRIBE method
     *
     * @param Request $request
     * @param ConnectionInterface $connection
     * @param Response $response
     */
    private function describe(Request $request, ConnectionInterface $connection, Response $response)
    {
        $startTime = time() + 2208988800;
        //$response->body = "v=0\r\no=- 0 $startTime IN IP4 0.0.0.0\r\ns=bar\r\nt=$startTime 0\r\nm=video 0 RTP/AVP 33\r\n";
        $response->body = "v=0\r\no=- 0 $startTime IN IP4 0.0.0.0\r\ns=bar\r\nt=$startTime 0\r\nm=video 0 udp 33\r\n";
        $response->setHeader('content-type', 'application/sdp');
    }

    /**
     * Handle SETUP method
     *
     * @param Request $request
     * @param ConnectionInterface $connection
     * @param Response $response
     */
    private function setup(Request $request, ConnectionInterface $connection, Response $response)
    {
        if (!$request->hasHeader('transport')) {
            $response->statusCode = 400;
            $response->reasonPhrase = 'Missing transport header';
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
        } else {
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
            try {
                $pids = $this->dvbContext->channels->getPidsByServiceId($channelServiceId);
                $tsstream = $this->dvbContext->getTsStream($channelServiceId);
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

            } catch (DvbException $e) {
                $this->dvbContext->logger->error("", ['exception' => $e]);
                $response->statusCode = 500;
                $response->body = $e->getMessage();
            } catch (\Exception $e) {
                $this->dvbContext->logger->error("", ['exception' => $e]);
                $response->statusCode = 500;
            }
        }
    }

    /**
     * Handle PLAY method
     *
     * @param Request $request
     * @param ConnectionInterface $connection
     * @param Response $response
     */
    private function play(Request $request, ConnectionInterface $connection, Response $response)
    {
        if (!$request->hasHeader('session')) {
            $response->statusCode = 454;
            $response->reasonPhrase = 'Missing session header';
            return;
        }
        $sessionId = (int)$request->getHeader('session');
        if (!isset($this->sessions[$sessionId])) {
            $response->statusCode = 454;
            $response->reasonPhrase = 'Session Not Found';
            return;
        }
        $session = $this->sessions[$sessionId];
        $session->play();
    }

    private function teardown(Request $request, ConnectionInterface $connection, Response $response)
    {
        if (!$request->hasHeader('session')) {
            $response->statusCode = 454;
            $response->reasonPhrase = 'Missing session header';
            return;
        }
        $sessionId = (int)$request->getHeader('session');
        if (!isset($this->sessions[$sessionId])) {
            $response->statusCode = 454;
            $response->reasonPhrase = 'Session Not Found';
            return;
        }
        $session = $this->sessions[$sessionId];
        $session->teardown();
    }
}