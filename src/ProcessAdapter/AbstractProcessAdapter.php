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


use Psr\Log\LoggerInterface;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

abstract class AbstractProcessAdapter implements ProcessAdapterInterface
{
    /**
     * Return a command to execute to check a process adapter works.
     * The command should have a 0 exit status
     *
     * @return string
     */
    abstract public function getCheckCmd(): string;

    abstract public function getLoop(): LoopInterface;

    abstract public function getLogger(): LoggerInterface;

    public function works(): PromiseInterface {
        $logger = $this->getLogger();
        $cmd = $this->getCheckCmd();
        $logger->debug("Running $cmd");
        $deferred = new Deferred();
        $promise = $deferred->promise();
        $process = new Process($this->getCheckCmd());
        $process->on('exit', function ($statusCode) use ($deferred, $process) {
            $process->removeAllListeners();
            $deferred->resolve($statusCode == 0);
        });
        $process->start($this->getLoop());
        if (isset($process->stderr)) {
            $process->stderr->on('data', function($data) use ($logger) {
                $logger->debug($data);
            });
        }
        if (isset($process->stdout)) {
            $process->stdout->on('data', function($data) use ($logger) {
                $logger->debug($data);
            });
        }
        return $promise;
    }
}