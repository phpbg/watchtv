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

use PhpBg\WatchTv\ProcessAdapter\AbstractProcessAdapter;
use PhpBg\WatchTv\ProcessAdapter\DvbjetProcessAdapter;
use PhpBg\WatchTv\ProcessAdapter\DvbscanProcessAdapter;
use PhpBg\WatchTv\ProcessAdapter\DvbzapProcessAdapter;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use function React\Promise\all;

class CheckConfiguration
{
    private $loop;
    private $logger;

    public function __construct(LoopInterface $loop, LoggerInterface $logger)
    {
        $this->loop = $loop;
        $this->logger = $logger;
    }

    public function __invoke(ServerRequestInterface $request)
    {
        /**
         * @var AbstractProcessAdapter[] $adapters
         */
        $adapters = [
            [
                'instance' => new DvbscanProcessAdapter($this->loop, $this->logger),
                'isTuner' => false,
                'isScanner' => true,
            ],
            [
                'instance' => new DvbzapProcessAdapter($this->loop, $this->logger),
                'isTuner' => true,
                'isScanner' => false,
            ],
            [
                'instance' => new DvbjetProcessAdapter($this->loop, $this->logger),
                'isTuner' => true,
                'isScanner' => false,
            ],
        ];

        $promises = [];
        foreach ($adapters as $adapter) {
            $promises[] = $adapter['instance']->works()->then(function($works) use ($adapter) {
                return [
                    'cmd' => $adapter['instance']->getCheckCmd(),
                    'works' => $works,
                    'resolution' => $adapter['instance']->getSetupHint(),
                    'isTuner' => $adapter['isTuner'],
                    'isScanner' => $adapter['isScanner'],
                    ];
            });
        }

        return all($promises);
    }
}