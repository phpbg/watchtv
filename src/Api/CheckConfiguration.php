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

use Psr\Http\Message\ServerRequestInterface;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use function React\Promise\all;
use React\Promise\Deferred;

class CheckConfiguration
{
    private $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function __invoke(ServerRequestInterface $request)
    {
        $checks = [
            [
                'cmd' => 'dvbv5-scan --version',
                'status' => null,
                'resolution' => [
                    127 => [
                        'raspbian/ubuntu' => '$ sudo apt install dvb-tools',
                        'debian' => '# apt install dvb-tools'
                    ]
                ]
            ],
            [
                'cmd' => 'dvbv5-zap --version',
                'status' => null,
                'resolution' => [
                    127 => [
                        'raspbian/ubuntu' => '$ sudo apt install dvb-tools',
                        'debian' => '# apt install dvb-tools'
                    ]
                ]
            ]
        ];

        $promises = [];
        foreach ($checks as $check) {
            $deferred = new Deferred();
            $promises[] = $deferred->promise();
            $process = new Process($check['cmd']);
            $process->on('exit', function ($statusCode) use ($check, $deferred) {
                $check['status'] = $statusCode;
                $deferred->resolve($check);
            });
            $process->start($this->loop);
        }

        return all($promises);
    }
}