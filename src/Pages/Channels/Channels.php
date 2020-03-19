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

use PhpBg\MiniHttpd\HttpException\RedirectException;
use PhpBg\MiniHttpd\Middleware\ContextTrait;
use PhpBg\WatchTv\Dvb\ChannelsNotFoundException;
use Psr\Http\Message\ServerRequestInterface;

class Channels extends AbstractChannelsController
{
    use ContextTrait;

    /**
     * Main homepage listing channels
     * @param ServerRequestInterface $request
     * @return array
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

        $context = $this->getContext($request);
        $context->renderOptions['bottomScripts'] = [
            $this->isRelease ? "/vue-2.6.11.min.js" : "/vue-2.6.11.js",
            "/jquery-3.3.1.min.js"
        ];
        $context->renderOptions['headCss'] = ['/w3css-4.12.css'];

        return [
            'publicHostname' => $this->getHost($request),
            'rtspPort' => $this->rtspPort
        ];
    }
}