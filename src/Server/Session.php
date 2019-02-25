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

use Evenement\EventEmitterTrait;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;

/**
 * Class Session
 * Events implemented:
 *   close
 *   error
 */
class Session implements WritableStreamInterface
{
    use EventEmitterTrait;

    /**
     * @var int Session id
     */
    public $id;

    /**
     * @var string Transport protocol UDP|TCP
     */
    public $proto;

    /**
     * @var array Ports (if transport is not interleaved)
     */
    public $ports;

    /**
     * @var string remote client IP
     */
    public $clientIp;

    /**
     * @var string Transport string as negociated during setup
     */
    public $transport;

    /**
     * @var bool Is transport interleaved
     */
    public $isInterleaved;

    public $uri;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var ConnectionInterface
     */
    private $connection;

    /**
     * @var \React\Datagram\Socket
     */
    private $udpClient;

    /**
     * How long the server is prepared to wait between RTSP commands before closing the session due to lack of activity
     * @var int Number of seconds
     */
    public $timeout = 60;

    /**
     * Unix timestamp updated each time there is activity on the session
     * @var int
     */
    protected $lastActivityTimestamp;

    /**
     * @var \React\EventLoop\TimerInterface
     */
    protected $lastActivityTimer;

    /**
     * Internal send buffer
     * @var array
     */
    private $rawData = [];

    /**
     * Number of packets in buffer before flush
     * @var int
     */
    private $bufferCountFlush;

    /**
     * @var LoggerInterface
     */
    private $log;

    private $playRequested = false;

    private $isWritable = true;

    public function __construct(int $id, LoopInterface $loop, ConnectionInterface $connection, LoggerInterface $logger)
    {
        $this->log = $logger;
        $this->id = $id;
        $this->loop = $loop;
        $this->connection = $connection;
        $this->keepAlive();
        $this->lastActivityTimer = $this->loop->addPeriodicTimer($this->timeout, [$this, '_checkActivity']);
    }

    /**
     * Update session activity timestamp
     */
    public function keepAlive()
    {
        $this->log->debug("Session {$this->id} keepalive");
        $this->lastActivityTimestamp = time();
    }

    public function _checkActivity()
    {
        $this->log->debug("Checking session {$this->id} activity");
        if (abs(time() - $this->lastActivityTimestamp) > $this->timeout) {
            $this->log->debug("Tearing down due to inactivity");
            $this->serverTeardown();
        }
    }

    public function play()
    {
        $this->playRequested = true;
        $this->keepAlive();
        $remoteIp = $this->clientIp;
        $remotePort = $this->ports[0];
        $this->log->info("Requesting PLAY to {$this->proto}://{$remoteIp}:{$remotePort}");

        if ($this->proto === 'UDP') {
            //UDP packet size must be less than 65,507 bytes = 65,535 − 8 bytes UDP header − 20 bytes IP header
            //Max useable size would then be 348 mpegts packets @ 188 bytes = 65424
            //After testing, UDP shows it is better to avoid fragmentation
            //That gives 1500 - 8 bytes UDP header - 20 bytes IP header = 1472 / 188 = 7 MPEG TS packets @ 188 bytes
            $this->bufferCountFlush = 7;
        } else if ($this->proto === 'TCP') {
            //TCP buffer size must be less than 65531 = 65535 -'$' - <1 byte id> - <2byte length>
            //Max useable size is 348 mpegts packets @ 188 bytes = 65424
            $this->bufferCountFlush = 348;
        }

        if ($this->isInterleaved) {
            //Interleaved mode : watch for connection events
            $this->connection->once('end', [$this, 'teardown']);
            $this->connection->once('error', [$this, 'teardown']);
            $this->connection->once('close', [$this, 'teardown']);
            return;
        }

        // Non interleaved mode : send data to specified transport
        if ($this->proto !== 'UDP') {
            $this->emit('error', [new \Exception('Protocol not supported')]);
            $this->serverTeardown();
            return;
        }

        $factory = new \React\Datagram\Factory($this->loop);
        $factory->createClient("{$remoteIp}:{$remotePort}")->then(function (\React\Datagram\Socket $client) {
            $this->log->debug("UDP connected");
            $this->udpClient = $client;
            $client->once('error', function ($e) {
                $this->log->debug("UDP client error", ['exception' => $e]);
                $this->teardown();
            });
            $client->once('close', function () {
                $this->log->debug("UDP client close");
                unset($this->udpClient);
                $this->teardown();
            });
        });
    }

    public function isWritable() {
        return $this->isWritable;
    }

    public function write($data)
    {
        if (! $this->playRequested || ! $this->isWritable) {
            return;
        }
        $this->rawData[] = $data;
        $packets = count($this->rawData);
        if ($packets >= $this->bufferCountFlush) {
            $buffer = implode('', $this->rawData);
            $this->rawData = [];

            if (isset($this->udpClient)) {
                $this->udpClient->send($buffer);
                return;
            }

            if ($this->isInterleaved) {
                //TODO use real id from session setup
                $id = pack('C', 0);
                $packetLen = pack('n', 188 * $packets);
                $ret = $this->connection->write('$' . $id . $packetLen . $buffer);
                if ($ret === false) {
                    $this->log->warning("TCP connection buffer is full but live stream cannot be paused, this is bad news...");
                }
                return;
            }

        }
    }

    public function end($data = null) {
        if (! empty($data)) {
            $this->write($data);
        }
        $this->teardown(true);
    }

    public function close() {
        $this->teardown(true);
    }

    public function serverTeardown()
    {
        $this->teardown(true);
    }

    /**
     * @param bool $serverTeardown Whether the server originated the teardown (and therefore should inform the client)
     */
    public function teardown($serverTeardown = false)
    {
        $this->isWritable = false;
        if (isset($this->udpClient)) {
            $this->udpClient->close();
            unset($this->udpClient);
        }
        if (isset($this->connection)) {
            $this->connection->removeListener('end', [$this, 'teardown']);
            $this->connection->removeListener('error', [$this, 'teardown']);
            $this->connection->removeListener('close', [$this, 'teardown']);
            unset($this->connection);
        }
        if (isset($this->lastActivityTimer)) {
            $this->loop->cancelTimer($this->lastActivityTimer);
        }
        if (!empty($this->listeners)) {
            $this->emit('close', [$serverTeardown]);
            $this->removeAllListeners();
            $this->log->debug("Used: " . (memory_get_usage(false) / 1024 / 1024) . " MiB");
            $this->log->debug("Allocated: " . (memory_get_usage(true) / 1024 / 1024) . " MiB");
        }
    }
}