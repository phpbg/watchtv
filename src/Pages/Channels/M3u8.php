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

namespace PhpBg\WatchTv\Pages\Channels;

use GuzzleHttp\Psr7\Response;
use PhpBg\MiniHttpd\HttpException\RedirectException;
use PhpBg\WatchTv\Dvb\ChannelsNotFoundException;
use Psr\Http\Message\ServerRequestInterface;

class M3u8 extends AbstractChannelsController
{
    /**
     * Download all channels as a m3u8 playlist
     * @param ServerRequestInterface $request
     * @return Response
     * @throws ChannelsNotFoundException
     * @throws RedirectException
     */
    public function __invoke(ServerRequestInterface $request)
    {
        try {
            $this->channels->getChannelsByName();
        } catch (ChannelsNotFoundException $e) {
            // Channels not yet configured, redirect to configuration page
            throw new RedirectException('/configure');
        }

        $content = "#EXTM3U\r\n";
        $host = $this->getHost($request);
        foreach ($this->channels->getChannelsByName() as $channelName => $channelDescriptor) {
            $content .= "#EXTINF:-1 tvg-id=\"{$channelDescriptor['SERVICE_ID']}\",{$channelName}\r\n";
            $content .= "rtsp://{$host}:{$this->rtspPort}/{$channelDescriptor['SERVICE_ID']}\r\n";
        }

        return new Response(200, [
            'Content-type' => 'application/mpegurl',
            'Content-Disposition' => 'inline; filename=watchtv.m3u8'
        ], $content);
    }
}