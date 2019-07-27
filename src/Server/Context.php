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

namespace PhpBg\WatchTv\Server;

use PhpBg\DvbPsi\Context\GlobalContext;
use PhpBg\MiniHttpd\Model\ApplicationContext;
use PhpBg\WatchTv\Dvb\Channels;
use PhpBg\WatchTv\Dvb\EPGGrabber;
use PhpBg\WatchTv\Dvb\TSStreamFactory;
use PhpBg\WatchTv\ProcessAdapter\TunerProcessAdapterInterface;


class Context extends ApplicationContext
{
    /**
     * @var int
     */
    public $httpPort;

    /**
     * @var int
     */
    public $rtspPort;

    /**
     * @var Channels
     */
    public $channels;

    /**
     * Application root path
     * @var string
     */
    public $rootPath;

    /**
     * @var TSStreamFactory
     */
    public $tsStreamFactory;

    /**
     * @var EPGGrabber
     */
    public $epgGrabber;

    /**
     * @var GlobalContext
     */
    public $dvbGlobalContext;

    /**
     * @var TunerProcessAdapterInterface
     */
    public $tunerProcessAdapter;

    /**
     * @var bool
     */
    public $isRelease;
}