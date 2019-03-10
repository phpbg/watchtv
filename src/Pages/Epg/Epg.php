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

use GuzzleHttp\Psr7\Response;
use PhpBg\DvbPsi\Context\EitServiceAggregator;
use PhpBg\DvbPsi\Descriptors\ShortEvent;
use PhpBg\WatchTv\Dvb\Channels;
use PhpBg\MiniHttpd\Controller\AbstractController;
use PhpBg\MiniHttpd\Middleware\ContextTrait;
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

    /**
     * @param ServerRequestInterface $request
     * @return Response
     * @throws \PhpBg\WatchTv\Dvb\ChannelsNotFoundException
     */
    public function __invoke(ServerRequestInterface $request)
    {
        $content = '<?xml version="1.0" encoding="utf-8" ?>';
        $content .= '<tv>';

        foreach ($this->channels->getChannelsByName() as $name => $descriptor) {
            $content .= '
              <channel id="' . $descriptor['SERVICE_ID'] . '">
                <display-name>' . htmlspecialchars($name) . '</display-name>
              </channel>
            ';
        }

        foreach ($this->epgGrabber->getEitAggregators() as $eitAggregator) {
            /**
             * @var EitServiceAggregator $eitAggregator
             */
            $eitAggregatorEvents = $eitAggregator->getAllEvents();
            foreach ($eitAggregatorEvents as $eitEvent) {
                /**
                 * @var \PhpBg\DvbPsi\Tables\EitEvent $eitEvent
                 */
                $start = date('YmdHis O', $eitEvent->startTimestamp);
                $stop = date('YmdHis O', $eitEvent->startTimestamp + $eitEvent->duration);

                $shortEvent = null;
                foreach ($eitEvent->descriptors as $descriptor) {
                    if ($descriptor instanceof ShortEvent) {
                        $shortEvent = $descriptor;
                        break;
                    }
                }

                $content .= '<programme start="' . $start . '" stop="' . $stop . '" channel="' . $eitAggregator->serviceId . '">';
                $content .= "\n";
                if (isset($shortEvent)) {
                    $content .= '<title>' . htmlspecialchars($shortEvent->eventName) . '</title>';
                    $content .= "\n";
                    $content .= '<desc>' . htmlspecialchars($shortEvent->text) . '</desc>';
                    $content .= "\n";
                }
                //TODO category
                //$content .= '      <category>Category</category>';
                $content .= '</programme>';
                $content .= "\n";
            }
        }

        $content .= '</tv>';

        return new Response(200, [
            'Content-type' => 'application/xml; charset="UTF-8"',
            'Content-Disposition' => 'inline; filename=epg.xml'
        ], $content);
    }
}