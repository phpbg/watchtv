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

namespace PhpBg\WatchTv\ProcessAdapter;

use PhpBg\WatchTv\Dvb\Channels;
use Psr\Log\LoggerInterface;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;

class DvbzapProcessAdapter extends AbstractProcessAdapter implements TunerProcessAdapterInterface
{
    private $loop;
    private $logger;
    private $channels;

    public function __construct(LoopInterface $loop, LoggerInterface $logger, Channels $channels = null)
    {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->channels = $channels;
    }

    public function getCheckCmd(): string
    {
        return 'dvbv5-zap --version';
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function start(array $channelDescriptor): Process
    {
        if (! isset($this->channels)) {
            throw new \RuntimeException();
        }
        $channelsFilePath = $this->channels->getChannelsFilePath();
        $channelName = $channelDescriptor['NAME'];
        $processLine = "exec dvbv5-zap -c {$channelsFilePath} -v --lna=-1 '{$channelName}' -P -o -";
        $this->logger->debug("Starting $processLine");
        return new Process($processLine);
    }

    public function getSetupHint(): array
    {
        return [
            // TODO arch and fedora
            'raspbian/ubuntu' => '$ sudo apt install dvb-tools',
            'debian' => '# apt install dvb-tools'
        ];
    }
}