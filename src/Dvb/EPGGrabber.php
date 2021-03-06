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
use PhpBg\DvbPsi\Tables\Eit;
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

    private $eitParser;

    private $noEitCounter;

    const CHECK_INTERVAL = 3;

    const RETRY_ON_FAILURE_INTERVAL = 60;

    const DEFAULT_GRAB_INTERVAL = 3600;

    const GRAB_GUARD_INTERVAL = 30;

    public function __construct(LoopInterface $loop, LoggerInterface $logger, Channels $channels, TSStreamFactory $tsStreamFactory, GlobalContext $globalContext)
    {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->channels = $channels;
        $this->tsStreamFactory = $tsStreamFactory;
        $this->running = false;
        $this->globalContext = $globalContext;
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
     * Return an array of all EitServiceAggregator
     * There is one EitServiceAggregator per Network/TS stream/Service
     *
     * @return EitServiceAggregator[]
     */
    public function getEitAggregators()
    {
        $events = $this->globalContext->getAllEvents();
        $aggregators = [];
        foreach ($events as $transportStreams) {
            foreach ($transportStreams as $services) {
                foreach ($services as $eitAggregator) {
                    $aggregators[] = $eitAggregator;
                }
            }
        }
        return $aggregators;
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
            $this->_handleTsStreamExit();
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
            $this->logger->debug("Grabbing EPG for {$channelDescriptor['FREQUENCY']}");
            $this->doneMultiplexes[] = $channelDescriptor['FREQUENCY'];

            $tsStreamPromise = $this->tsStreamFactory->getTsStream($channelDescriptor['SERVICE_ID']);
            $tsStreamPromise->then(function (TSStream $tsStream) {
                $this->logger->debug("Received a new TSStream");
                $this->currentTsStream = $tsStream;
                $this->currentTsStream->on('exit', [$this, '_handleTsStreamExit']);
                $this->currentTsStream->setEpgGrabbing();

                $psiParser = $tsStream->getPsiParser();
                $this->eitParser = new \PhpBg\DvbPsi\TableParsers\Eit();
                $psiParser->registerTableParser($this->eitParser);
                $psiParser->on('eit', function ($eit) {
                    $this->globalContext->addEit($eit);
                    $this->lastEit = $eit;
                });

                $this->noEitCounter = 0;
                $this->loop->addTimer(static::CHECK_INTERVAL, [$this, '_checkGrabber']);
            });
            $tsStreamPromise
                ->otherwise(function (/** @noinspection PhpUnusedParameterInspection */ MaxProcessReachedException $e) {
                    $this->logger->debug("Cannot start a new process for this multiplex, skipping");
                    $this->loop->futureTick([$this, '_resumeGrab']);
                })
                ->otherwise(function (\Throwable $e) {
                    $this->logger->error("Unexpected error", ['exception' => $e]);
                    $this->loop->futureTick([$this, '_resumeGrab']);
                });


            //Explicitly break the foreach, it will be resumed later
            return;
        }

        $this->running = false;
        $this->logger->notice("EPG grabbing finished");

        $nextUpdateTimestamp = $this->getNextUpdateTimestamp();
        $interval = max(static::GRAB_GUARD_INTERVAL, $nextUpdateTimestamp - time());
        $this->logger->notice("Next grab in {$interval}s");
        $this->loop->addTimer($interval, [$this, 'grab']);
    }

    public function _checkGrabber()
    {
        $this->logger->debug("Checking EPG grabber status");

        if (!$this->running) {
            $this->logger->debug("EPG grabber not running anymore");
            return;
        }

        if (!isset($this->lastEit)) {
            $this->logger->debug("No EIT yet");
            $this->noEitCounter++;
            if ($this->noEitCounter > 3) {
                $this->logger->debug("It seems we will never receive EIT for this multiplex. Abort and try moving (later) to next multiplex to see if we're more lucky");
                $this->stopGrabbing();
                // Plan later resume
                $this->loop->addTimer(static::RETRY_ON_FAILURE_INTERVAL, [$this, '_resumeGrab']);
                return;
            }
            $this->loop->addTimer(static::CHECK_INTERVAL, [$this, '_checkGrabber']);
            return;
        }

        $stat = $this->computeGlobalStat();
        $roundedStat = round($stat);
        $this->logger->debug("Current aggregation status: {$roundedStat}%");
        if ($stat === $this->lastEitAggregatorStat) {
            $this->logger->debug("Moving to next multiplex");
            $this->stopGrabbing();
            // Plan immediate resume
            $this->loop->futureTick([$this, '_resumeGrab']);
            return;
        }
        $this->lastEitAggregatorStat = $stat;
        $this->loop->addTimer(static::CHECK_INTERVAL, [$this, '_checkGrabber']);
    }

    private function stopGrabbing()
    {
        $this->currentTsStream->removeListener('exit', [$this, '_handleTsStreamExit']);
        $this->currentTsStream->getPsiParser()->removeAllListeners('eit');
        $this->currentTsStream->getPsiParser()->unregisterTableParser($this->eitParser);
        $this->currentTsStream->releaseEpgGrabbing();
        unset($this->currentTsStream);
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
        $this->logger->debug("Early EPG grabber exit, will restart in " . static::RETRY_ON_FAILURE_INTERVAL . "s");
        $this->running = false;
        unset($this->currentTsStream);
        $this->loop->addTimer(static::RETRY_ON_FAILURE_INTERVAL, [$this, 'grab']);
    }

    /**
     * Return the next timestamp we will re grab channels
     * Is is currently based on next event ending, we could certainly grab less frequently, but whatever...
     *
     * @return int
     */
    public function getNextUpdateTimestamp(): int
    {
        $nextGrabTimestamp = time() + static::DEFAULT_GRAB_INTERVAL;

        // Try to get running event using all events, not following events
        // This allows us to scan EPG less frequently
        $now = time();
        foreach ($this->getEitAggregators() as $eitAggregator) {
            $runningEvent = $eitAggregator->getRunningEvent($now);
            if (! isset($runningEvent)) {
                continue;
            }
            $stopTimestamp = $runningEvent->startTimestamp + $runningEvent->duration;
            $nextGrabTimestamp = min($nextGrabTimestamp, $stopTimestamp);
        }
        return $nextGrabTimestamp;
    }
}