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

namespace PhpBg\WatchTv\Api;

use PhpBg\DvbPsi\Tables\EitEvent;
use PhpBg\MiniHttpd\Middleware\ContextTrait;
use PhpBg\MiniHttpd\Renderer\Json;
use PhpBg\WatchTv\Dvb\EPGGrabber;
use Psr\Http\Message\ServerRequestInterface;

class Epg
{
    use ContextTrait;

    private $epgGrabber;

    public function __construct(EPGGrabber $epgGrabber)
    {
        $this->epgGrabber = $epgGrabber;
    }

    /**
     * Return all eit events currently running
     *
     * @return EitEvent[]
     */
    public function getRunning(ServerRequestInterface $request)
    {
        // Allow for partial output because epg data may contain non-utf8
        // TODO: remove those non utf-8 char (control codes? e.g. character emphasis?)
        $context = $this->getContext($request);
        $context->renderOptions[Json::JSON_OPTIONS_KEY] = JSON_PARTIAL_OUTPUT_ON_ERROR;

        // Try to get running event using all events, not following events
        // This allows us to scan EPG less frequently
        $eitAggregators = $this->epgGrabber->getEitAggregators();
        $now = time();
        $runningEvents = [];
        foreach ($eitAggregators as $eitAggregator) {
            $runningEvent = $eitAggregator->getRunningEvent($now);
            if (! isset($runningEvent)) {
                continue;
            }

            // Set fields as public fields so they'll be json encoded
            $runningEvent->serviceId = $eitAggregator->serviceId;
            $runningEvent->transportStreamId = $eitAggregator->transportStreamId;
            $runningEvent->networkId = $eitAggregator->originalNetworkId;
            foreach ($runningEvent->descriptors as &$descriptor) {
                $descriptor->_descriptorName = get_class($descriptor);
            }
            unset($descriptor);
            $runningEvents[] = $runningEvent;
        }
        return $runningEvents;
    }
}