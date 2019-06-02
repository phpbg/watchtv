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

use PhpBg\DvbPsi\Context\GlobalContext;
use PhpBg\DvbPsi\Exception;
use PhpBg\WatchTv\ProcessAdapter\TunerProcessAdapterInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\Promise;

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
    private $dvbGlobalContext;

    /**
     * @var TunerProcessAdapterInterface
     */
    private $tunerProcessAdapter;

    public function __construct(LoggerInterface $logger, LoopInterface $loop, Channels $channels, GlobalContext $globalContext)
    {
        $this->logger = $logger;
        $this->loop = $loop;
        $this->channels = $channels;
        $this->streamsByChannelFrequency = [];
        $this->maxProcessAllowed = 1;
        $this->dvbGlobalContext = $globalContext;
    }

    public function setTunerProcessAdapter(TunerProcessAdapterInterface $tunerProcessAdapter) {
        $this->tunerProcessAdapter = $tunerProcessAdapter;
    }

    /**
     * Return a Promise that will resolve in a TSStream valid for the requested channelServiceId
     *
     * @param int $channelServiceId
     * @return ExtendedPromiseInterface that will resolve in a TSStream
     * @reject ChannelsNotFoundException
     * @reject MaxProcessReachedException
     */
    public function getTsStream(int $channelServiceId): ExtendedPromiseInterface
    {
        $this->logger->debug("getTsStream() for $channelServiceId");
        return new Promise(function (callable $resolver) use ($channelServiceId) {
            $channelDescriptor = $this->channels->getChannelByServiceId($channelServiceId);
            $channelFrequency = $channelDescriptor['FREQUENCY'] ?? null;
            if (empty($channelFrequency)) {
                throw new ChannelsNotFoundException("Unable to find channel frequency for channel $channelServiceId");
            }

            if (!isset($this->streamsByChannelFrequency[$channelFrequency])) {
                if (count($this->streamsByChannelFrequency) >= $this->maxProcessAllowed) {
                    $terminatingStream = $this->terminateTsStream();
                    if (!isset($terminatingStream)) {
                        throw new MaxProcessReachedException("Can't start a new process: maximum number of running process reached ({$this->maxProcessAllowed})");
                    }
                    $exitPromise = new Promise(function (callable $exitResolver) use ($terminatingStream) {
                        $terminatingStream->on('exit', function () use ($exitResolver) {
                            return $exitResolver();
                        });
                    });
                    return $exitPromise->then(function () use ($resolver, $channelDescriptor, $channelFrequency) {
                        return $resolver($this->doCreateTsStream($channelDescriptor));
                    });
                }
                $this->doCreateTsStream($channelDescriptor);
            }
            return $resolver($this->streamsByChannelFrequency[$channelFrequency]);
        });
    }

    /**
     * @param array $channelDescriptor
     * @return TSStream
     * @throws \PhpBg\DvbPsi\Exception
     */
    protected function doCreateTsStream(array $channelDescriptor)
    {
        if (! isset($this->tunerProcessAdapter)) {
            throw new Exception("No tuner process available");
        }
        $process = $this->tunerProcessAdapter->start($channelDescriptor);
        $tsStream = new TSStream($process, $this->logger, $this->loop, $this->dvbGlobalContext, $this->channels);
        $channelFrequency = $channelDescriptor['FREQUENCY'];
        $this->streamsByChannelFrequency[$channelFrequency] = $tsStream;
        $tsStream->on('exit', function () use ($channelFrequency) {
            unset($this->streamsByChannelFrequency[$channelFrequency]);
        });
        return $tsStream;
    }

    /**
     * Try to terminate a TsStream that has no clients (but may be doing EPG grabbing)
     * @return TSStream|null Return null if no TSStream were therminated, the terminated TSStream otherwise
     */
    public function terminateTsStream()
    {
        foreach ($this->streamsByChannelFrequency as $tsStream) {
            /**
             * @var TSStream $tsStream
             */
            if (empty($tsStream->getClients())) {
                $tsStream->terminate();
                return $tsStream;
            }
        }
        return null;
    }
}