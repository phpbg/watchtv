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

use PhpBg\DvbPsi\Context\GlobalContext;
use PhpBg\DvbPsi\Exception;
use PhpBg\MiniHttpd\HttpException\HttpException;
use PhpBg\WatchTv\Dvb\LogicalChannelsNumbers;

class Channels
{
    use LogicalChannelsNumbers;

    private $channels;
    private $dvbGlobalContext;

    public function __construct(\PhpBg\WatchTv\Dvb\Channels $channels, GlobalContext $dvbGlobalContext)
    {
        $this->channels = $channels;
        $this->dvbGlobalContext = $dvbGlobalContext;
    }

    /**
     * Return configured channels
     *
     * @return array
     * @throws \PhpBg\WatchTv\Dvb\ChannelsNotFoundException
     */
    public function getAll()
    {
        return array_values($this->channels->getChannelsByName());
    }

    /**
     * Re-read channels from configuration file and return them
     *
     * @return array
     * @throws \PhpBg\WatchTv\Dvb\ChannelsNotFoundException
     */
    public function reload()
    {
        return array_values($this->channels->getChannelsByName(true));
    }

    /**
     * Return logical channel numbers (if any)
     * @return array|null
     * @throws HttpException
     */
    public function logicalNumbers()
    {
        try {
            return $this->getLogicalChannelsNumbers($this->dvbGlobalContext);
        } catch (Exception $e) {
            throw new HttpException($e->getMessage(), 501);
        }
    }
}