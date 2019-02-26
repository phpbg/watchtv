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

namespace PhpBg\WatchTv\Dvb;

use Psr\Log\LoggerInterface;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;

/**
 * Class TSStreamFactory: produce TSStreams
 */
class TSStreamFactory
{
    private $logger;
    private $loop;
    private $channels;
    private $streamsByChannelFrequency;
    private $maxProcessAllowed;

    public function __construct(LoggerInterface $logger, LoopInterface $loop, Channels $channels)
    {
        $this->logger = $logger;
        $this->loop = $loop;
        $this->channels = $channels;
        $this->streamsByChannelFrequency = [];
        $this->maxProcessAllowed = 1;
    }

    /**
     * Return a TSStream valid for the requested channelServiceId
     *
     * @param int $channelServiceId
     * @return TSStream
     * @throws ChannelsNotFoundException
     * @throws MaxProcessReachedException
     */
    public function getTsStream(int $channelServiceId): TSStream
    {
        $this->logger->debug("getTsStream() for $channelServiceId");
        $channelDescriptor = $this->channels->getChannelByServiceId($channelServiceId);
        $channelFrequency = $channelDescriptor[1]['FREQUENCY'] ?? null;
        if (empty($channelFrequency)) {
            throw new ChannelsNotFoundException("Unable to find channel frequency for channel $channelServiceId");
        }

        if (!isset($this->streamsByChannelFrequency[$channelFrequency])) {
            $channelsFile = $this->channels->getChannelsFilePath();
            if (count($this->streamsByChannelFrequency) >= $this->maxProcessAllowed) {
                throw new MaxProcessReachedException("Can't start a new process: maximum number of running process reached ({$this->maxProcessAllowed})");
            }
            $processLine = "exec dvbv5-zap -c {$channelsFile} -v --lna=-1 '{$channelDescriptor[0]}' -P -o -";
            $this->logger->debug($processLine);
            $process = new Process($processLine);
            $tsStream = new TSStream($process, $this->logger, $this->loop);
            $this->streamsByChannelFrequency[$channelFrequency] = $tsStream;
            $tsStream->on('exit', function () use ($channelFrequency) {
                unset($this->streamsByChannelFrequency[$channelFrequency]);
            });
        }
        return $this->streamsByChannelFrequency[$channelFrequency];
    }
}