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

namespace PhpBg\WatchTv\Pages\Epg;

use PhpBg\DvbPsi\Context\EitServiceAggregator;
use PhpBg\WatchTv\Dvb\Channels;
use PhpBg\MiniHttpd\Controller\AbstractController;
use PhpBg\MiniHttpd\Middleware\ContextTrait;
use PhpBg\WatchTv\Dvb\ChannelsNotFoundException;
use PhpBg\WatchTv\Dvb\EPGGrabber;
use Psr\Http\Message\ServerRequestInterface;

class Epg extends AbstractController
{
    use ContextTrait;

    private $epgGrabber;
    private $channels;

    public function __construct(EPGGrabber $epgGrabber, Channels $channels)
    {
        $this->epgGrabber = $epgGrabber;
        $this->channels = $channels;
    }

    public function __invoke(ServerRequestInterface $request)
    {
        $globalContext = $this->epgGrabber->getGlobalContext();
        $events = $globalContext->getAllEvents();
        $runningEvents = [];
        foreach ($events as $networkId => $transportStreams) {
            foreach ($transportStreams as $transportStream => $services) {
                foreach ($services as $service => $eitAggregator) {
                    /**
                     * @var EitServiceAggregator $eitAggregator
                     */
                    $runningEvent = $eitAggregator->getRunningEvent();
                    if (! isset($runningEvent)) {
                        continue;
                    }
                    try {
                        $channel = $this->channels->getChannelByServiceId($service);
                        $name = $channel[0];
                    } catch (ChannelsNotFoundException $e) {
                        $name = "Unknown channel: $service";
                    }
                    $runningEvents[$name] = $runningEvent->getShortEventText();
                }
            }
        }
        return ['runningEvents' => $runningEvents];
    }
}