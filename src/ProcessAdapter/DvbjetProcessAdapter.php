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

/**
 * Adapter for dvbjet from https://github.com/lightful/DVBdirect
 * Enums from linux kernel sources ./include/uapi/linux/dvb/frontend.h
 */
class DvbjetProcessAdapter extends AbstractProcessAdapter implements TunerProcessAdapterInterface
{
    private $loop;
    private $logger;

    private $deliverySystems = [
        'UNDEFINED' => 0,
        'DVBC_ANNEX_A' => 1,
        'DVBC_ANNEX_B' => 2,
        'DVBT' => 3,
        'DSS' => 4,
        'DVBS' => 5,
        'DVBS2' => 6,
        'DVBH' => 7,
        'ISDBT' => 8,
        'ISDBS' => 9,
        'ISDBC' => 10,
        'ATSC' => 11,
        'ATSCMH' => 12,
        'DTMB' => 13,
        'CMMB' => 14,
        'DAB' => 15,
        'DVBT2' => 16,
        'TURBO' => 17,
        'DVBC_ANNEX_C' => 18
    ];

    private $modulations = [
        'QPSK' => 0,
        'QAM/16' => 1,
        'QAM/32' => 2,
        'QAM/64' => 3,
        'QAM/128' => 4,
        'QAM/256' => 5,
        'QAM/AUTO' => 6,
        'VSB/8' => 7,
        'VSB/16' => 8,
        'PSK/8' => 9,
        'APSK/16' => 10,
        'APSK/32' => 11,
        'DQPSK' => 12,
        'QAM/4/NR' => 13
    ];

    private $inversion = [
        'OFF' => 0,
        'ON' => 1,
        'AUTO' => 2
    ];

    private $transmissionMode = [
        '2K' => 0,
        '8K' => 1,
        'AUTO' => 2,
        '4K' => 3,
        '1K' => 4,
        '16K' => 5,
        '32K' => 6,
        'C1' => 7,
        'C3780' => 8,
    ];

    private $codeRate = [
        'NONE' => 0,
        '1/2' => 1,
        '2/3' => 2,
        '3/4' => 3,
        '4/5' => 4,
        '5/6' => 5,
        '6/7' => 6,
        '7/8' => 7,
        '8/9' => 8,
        'AUTO' => 9,
        '3/5' => 10,
        '9/10' => 11,
        '2/5' => 12,
    ];

    private $guardInterval = [
        '1/32' => 0,
        '1/16' => 1,
        '1/8' => 2,
        '1/4' => 3,
        'AUTO' => 4,
        '1/128' => 5,
        '19/128' => 6,
        '19/256' => 7,
        'PN420' => 8,
        'PN595' => 9,
        'PN945' => 10,
    ];

    private $hierarchy = [
        'NONE' => 0,
        '1' => 1,
        '2' => 2,
        '4' => 3,
        'AUTO' => 4
    ];

    public function __construct(LoopInterface $loop, LoggerInterface $logger)
    {
        $this->loop = $loop;
        $this->logger = $logger;
    }

    public function getCheckCmd(): string
    {
        return 'dvbjet';
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
        $args = [];
        if (! empty($channelDescriptor['DELIVERY_SYSTEM'])) {
            if (isset($this->deliverySystems[$channelDescriptor['DELIVERY_SYSTEM']])) {
                $args[] = "17={$this->deliverySystems[$channelDescriptor['DELIVERY_SYSTEM']]}";
            } else {
                $this->logger->warning("No DELIVERY_SYSTEM mapping for {$channelDescriptor['DELIVERY_SYSTEM']}");
            }
        }
        if (! empty($channelDescriptor['FREQUENCY'])) {
            $args[] = "3={$channelDescriptor['FREQUENCY']}";
        }
        if (! empty($channelDescriptor['MODULATION'])) {
            if (isset($this->modulations[$channelDescriptor['MODULATION']])) {
                $args[] = "4={$this->modulations[$channelDescriptor['MODULATION']]}";
            } else {
                $this->logger->warning("No MODULATION mapping for {$channelDescriptor['MODULATION']}");
            }
        }
        if (! empty($channelDescriptor['BANDWIDTH_HZ'])) {
            $args[] = "5={$channelDescriptor['BANDWIDTH_HZ']}";
        }
        if (! empty($channelDescriptor['INVERSION'])) {
            if (isset($this->inversion[$channelDescriptor['INVERSION']])) {
                $args[] = "6={$this->inversion[$channelDescriptor['INVERSION']]}";
            } else {
                $this->logger->warning("No INVERSION mapping for {$channelDescriptor['INVERSION']}");
            }
        }
        if (! empty($channelDescriptor['TRANSMISSION_MODE'])) {
            if (isset($this->transmissionMode[$channelDescriptor['TRANSMISSION_MODE']])) {
                $args[] = "39={$this->transmissionMode[$channelDescriptor['TRANSMISSION_MODE']]}";
            } else {
                $this->logger->warning("No TRANSMISSION_MODE mapping for {$channelDescriptor['TRANSMISSION_MODE']}");
            }
        }
        if (! empty($channelDescriptor['CODE_RATE_HP'])) {
            if (isset($this->codeRate[$channelDescriptor['CODE_RATE_HP']])) {
                $args[] = "36={$this->codeRate[$channelDescriptor['CODE_RATE_HP']]}";
            } else {
                $this->logger->warning("No CODE_RATE_HP mode mapping for {$channelDescriptor['CODE_RATE_HP']}");
            }
        }
        if (! empty($channelDescriptor['CODE_RATE_LP'])) {
            if (isset($this->codeRate[$channelDescriptor['CODE_RATE_LP']])) {
                $args[] = "37={$this->codeRate[$channelDescriptor['CODE_RATE_LP']]}";
            } else {
                $this->logger->warning("No CODE_RATE_LP mode mapping for {$channelDescriptor['CODE_RATE_LP']}");
            }
        }
        if (! empty($channelDescriptor['GUARD_INTERVAL'])) {
            if (isset($this->guardInterval[$channelDescriptor['GUARD_INTERVAL']])) {
                $args[] = "38={$this->guardInterval[$channelDescriptor['GUARD_INTERVAL']]}";
            } else {
                $this->logger->warning("No GUARD_INTERVAL mode mapping for {$channelDescriptor['GUARD_INTERVAL']}");
            }
        }
        if (! empty($channelDescriptor['HIERARCHY'])) {
            if (isset($this->hierarchy[$channelDescriptor['HIERARCHY']])) {
                $args[] = "40={$this->hierarchy[$channelDescriptor['HIERARCHY']]}";
            } else {
                $this->logger->warning("No HIERARCHY mode mapping for {$channelDescriptor['HIERARCHY']}");
            }
        }
        $processLine = "exec dvbjet /dev/stdout ".implode(" ", $args);
        $this->logger->debug("Starting $processLine");
        return new Process($processLine);
    }

    public function getSetupHint(): array
    {
        return [
            'All os' => 'See https://github.com/lightful/DVBdirect',
        ];
    }

    public function works(): PromiseInterface {
        $logger = $this->getLogger();
        $cmd = $this->getCheckCmd();
        $logger->debug("Running $cmd");
        $deferred = new Deferred();
        $promise = $deferred->promise();
        $process = new Process($this->getCheckCmd());
        $process->on('exit', function ($statusCode) use ($deferred, $process) {
            $process->removeAllListeners();
            // dvbjet return 1 when invoked with no args
            $deferred->resolve($statusCode == 1);
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