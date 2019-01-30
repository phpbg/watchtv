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

use PhpBg\MiniHttpd\Model\ApplicationContext;
use React\ChildProcess\Process;
use React\Stream\ReadableStreamInterface;

class Context extends ApplicationContext
{
    public $httpPort;
    public $rtspPort;

    /**
     * @var Channels
     */
    public $channels;

    /**
     * Application root path
     * @var string
     */
    public $rootPath;

    private $streamsByChannelId;
    private $streamsCountByChannelId;
    private $processesByChannelId;
    private $maxProcessAllowed;

    public function __construct()
    {
        $this->streamsByChannelId = [];
        $this->streamsCountByChannelId = [];
        $this->processesByChannelId = [];
        $this->maxProcessAllowed = 1;
    }

    /**
     * @param int $channelServiceId
     * @return ReadableStreamInterface
     * @throws DvbException
     */
    public function getStreamForChannelServiceId(int $channelServiceId): ReadableStreamInterface
    {
        if (! isset($this->streamsByChannelId[$channelServiceId])) {
            $channelDescriptor = $this->channels->getChannelByServiceId($channelServiceId);
            $channelsFile = $this->channels->getChannelsFilePath();

            if (count($this->processesByChannelId) >= $this->maxProcessAllowed) {
                throw new DvbException("Can't start a new process: maximum number of running process reached ({$this->maxProcessAllowed})");
            }

            $processLine = "exec dvbv5-zap -c {$channelsFile} -v --lna=-1 '{$channelDescriptor[0]}' -o -";
            $this->logger->debug($processLine);

            $this->processesByChannelId[$channelServiceId] = new Process($processLine);
            $that = $this;
            $this->processesByChannelId[$channelServiceId]->on('exit', function() use ($channelServiceId, $that) {
                if (isset($that->streamsByChannelId[$channelServiceId])) {
                    $this->logger->debug("Process exit before cleanup: {$that->processesByChannelId[$channelServiceId]->getCommand()}");
                    unset($that->streamsByChannelId[$channelServiceId]);
                    unset($that->streamsCountByChannelId[$channelServiceId]);
                    $that->processesByChannelId[$channelServiceId]->removeAllListeners();
                    unset($that->processesByChannelId[$channelServiceId]);
                } else {
                    $this->logger->debug("Process exit after cleanup");
                }
            });
            $this->processesByChannelId[$channelServiceId]->start($this->loop);

            $this->processesByChannelId[$channelServiceId]->stderr->on('data', function ($chunk) {
                if (empty($chunk)) {
                    return;
                }
                $this->logger->warning($chunk);
            });

            $this->streamsByChannelId[$channelServiceId] = $this->processesByChannelId[$channelServiceId]->stdout;
            $this->streamsCountByChannelId[$channelServiceId] = 0;
        }
        $this->streamsCountByChannelId[$channelServiceId]++;
        return $this->streamsByChannelId[$channelServiceId];
    }

    public function releaseStreamForChannelServiceId(int $channelServiceId) {
        if (isset($this->streamsByChannelId[$channelServiceId])) {
            $this->streamsCountByChannelId[$channelServiceId]--;
            if ($this->streamsCountByChannelId[$channelServiceId] <= 0) {
                //Release process
                if (isset($this->processesByChannelId[$channelServiceId]) && $this->processesByChannelId[$channelServiceId]->isRunning()) {
                    if (isset($this->processesByChannelId[$channelServiceId]->stdin)) {
                        $this->processesByChannelId[$channelServiceId]->stdin->close();
                    }
                    if (isset($this->processesByChannelId[$channelServiceId]->stout)) {
                        $this->processesByChannelId[$channelServiceId]->stout->close();
                    }
                    if (isset($this->processesByChannelId[$channelServiceId]->stderr)) {
                        $this->processesByChannelId[$channelServiceId]->stderr->close();
                    }
                    $this->processesByChannelId[$channelServiceId]->terminate(SIGKILL);
                }
                unset($this->streamsByChannelId[$channelServiceId]);
                unset($this->streamsCountByChannelId[$channelServiceId]);
                unset($this->processesByChannelId[$channelServiceId]);
            }
        }
    }
}