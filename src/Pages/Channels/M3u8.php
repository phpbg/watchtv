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
use PhpBg\DvbPsi\Exception;
use PhpBg\MiniHttpd\HttpException\RedirectException;
use PhpBg\WatchTv\Dvb\ChannelsNotFoundException;
use PhpBg\WatchTv\Dvb\LogicalChannelsNumbers;
use Psr\Http\Message\ServerRequestInterface;

class M3u8 extends AbstractChannelsController
{
    use LogicalChannelsNumbers;

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

        $channels = $this->channels->getChannelsByName();
        try {
            $lcn = $this->getLogicalChannelsNumbers($this->dvbGlobalContext);
            if (! empty($lcn)) {
                usort($channels, function($a, $b) use ($lcn) {
                    // Channels without logical number should go to the end
                    $aNumber = $lcn[$a["SERVICE_ID"]] ?? 1000;
                    $bNumber = $lcn[$b["SERVICE_ID"]] ?? 1000;
                    return $aNumber <=> $bNumber;
                });
            }
        } catch (Exception $e) {
            // Unable to retrieve LCN, who cares ???
        }

        foreach ($channels as $channelDescriptor) {
            $content .= "#EXTINF:-1 tvg-id=\"{$channelDescriptor['SERVICE_ID']}\",{$channelDescriptor['NAME']}\r\n";
            $content .= "rtsp://{$host}:{$this->rtspPort}/{$channelDescriptor['SERVICE_ID']}\r\n";
        }

        return new Response(200, [
            'Content-type' => 'application/mpegurl',
            'Content-Disposition' => 'inline; filename=watchtv.m3u8'
        ], $content);
    }
}