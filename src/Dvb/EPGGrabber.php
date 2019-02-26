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

use PhpBg\DvbPsi\Context\EitServiceAggregator;
use PhpBg\DvbPsi\Context\GlobalContext;
use PhpBg\DvbPsi\ParserFactory;
use PhpBg\DvbPsi\Tables\Eit;
use PhpBg\WatchTv\Server\MaxProcessReachedException;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;

class EPGGrabber
{
    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Channels
     */
    private $channels;

    /**
     * @var TSStreamFactory
     */
    private $tsStreamFactory;

    private $doneMultiplexes;

    /**
     * @var Eit
     */
    private $lastEit;

    private $lastEitAggregatorStat;

    /**
     * @var GlobalContext
     */
    private $globalContext;

    /**
     * @var TSStream
     */
    private $currentTsStream;

    private $running;

    const CHECK_INTERVAL = 3;

    public function __construct(LoopInterface $loop, LoggerInterface $logger, Channels $channels, TSStreamFactory $tsStreamFactory)
    {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->channels = $channels;
        $this->tsStreamFactory = $tsStreamFactory;
        $this->running = false;
        $this->globalContext = new GlobalContext();
    }

    /**
     * Start a full EPG scan from all multiplexes
     */
    public function grab()
    {
        $this->logger->notice("Begin EPG grabbing");
        if ($this->running) {
            throw new \RuntimeException("EPG grabber already running");
        }
        $this->running = true;
        $this->doneMultiplexes = [];
        $this->_resumeGrab();
    }

    /**
     * @return GlobalContext
     */
    public function getGlobalContext(): GlobalContext
    {
        return $this->globalContext;
    }

    public function _resumeGrab()
    {
        $this->lastEit = null;
        $this->lastEitAggregatorStat = null;

        if (!$this->running) {
            throw new \RuntimeException();
        }

        if (isset($this->currentTsStream)) {
            throw new \RuntimeException();
        }

        try {
            $channels = $this->channels->getChannelsByName();
        } catch (ChannelsNotFoundException $e) {
            $this->logger->notice("No channels configured");
            return;
        }

        foreach ($channels as $name => $channelDescriptor) {
            if (!isset($channelDescriptor['FREQUENCY'])) {
                $this->logger->info("Skipping descriptor from $name because it is missing a frequency");
                continue;
            }
            if (in_array($channelDescriptor['FREQUENCY'], $this->doneMultiplexes)) {
                continue;
            }
            $this->logger->info("Grabbing EPG for {$channelDescriptor['FREQUENCY']}");
            $this->doneMultiplexes[] = $channelDescriptor['FREQUENCY'];
            try {
                $this->currentTsStream = $this->tsStreamFactory->getTsStream($channelDescriptor['SERVICE_ID']);
                $this->currentTsStream->on('exit', [$this, '_handleTsStreamExit']);
            } catch (MaxProcessReachedException $e) {
                $this->logger->debug("Cannot start a new process for this multiplex, skipping");
                continue;
            }
            $psiParser = ParserFactory::create();
            $psiParser->on('eit', function ($eit) {
                $this->globalContext->addEit($eit);
                $this->lastEit = $eit;
            });

            $this->currentTsStream->registerPsiParser($psiParser);

            $this->loop->addTimer(static::CHECK_INTERVAL, [$this, '_checkGrabber']);

            //Break the foreach, it will be resumed later
            return;
        }

        $this->running = false;
        $this->logger->notice("EPG grabbing finished");
    }

    public function _checkGrabber()
    {
        $this->logger->debug("Checking EPG grabber status");

        if (!$this->running) {
            $this->logger->debug("Not running anymore");
            return;
        }

        if (!isset($this->lastEit)) {
            $this->logger->debug("No EIT yet");
            $this->loop->addTimer(static::CHECK_INTERVAL, [$this, '_checkGrabber']);
            return;
        }

        $stat = $this->computeGlobalStat();
        $roundedStat = round($stat);
        $this->logger->debug("Current aggregation status: {$roundedStat}%");
        if ($stat === $this->lastEitAggregatorStat) {
            $this->logger->debug("Moving to next multiplex");
            $this->currentTsStream->removeListener('exit', [$this, '_handleTsStreamExit']);
            $this->currentTsStream->unregisterPsiParser();
            unset($this->currentTsStream);
            // Don't grab too fast because the process may have not yet released
            $this->loop->addTimer(3, [$this, '_resumeGrab']);
            return;
        }
        $this->lastEitAggregatorStat = $stat;
        $this->loop->addTimer(static::CHECK_INTERVAL, [$this, '_checkGrabber']);
    }

    private function computeGlobalStat(): float
    {
        $events = $this->globalContext->getAllEvents();
        $total = 0;
        $iterations = 0;
        foreach ($events as $networkId => $transportStreams) {
            foreach ($transportStreams as $transportStream => $services) {
                foreach ($services as $service => $eitAggregator) {
                    /**
                     * @var EitServiceAggregator $eitAggregator
                     */
                    $stat = $eitAggregator->getStat();
                    $total += $stat;
                    $this->logger->debug(sprintf("(0x%x)/(0x%x)/(0x%x): %d%%", $networkId, $transportStream, $service, $stat));
                    $iterations++;
                }
            }
        }
        return $iterations === 0 ? 0 : (1.0 * $total / $iterations);
    }

    public function _handleTsStreamExit()
    {
        $this->logger->debug("Early EPG grabber exit, will restart soon");
        $this->running = false;
        unset($this->currentTsStream);
        $this->loop->addTimer(5, [$this, 'grab']);
    }
}