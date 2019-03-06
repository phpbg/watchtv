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

namespace PhpBg\WatchTv\Dvb;

use Evenement\EventEmitter;
use Evenement\EventEmitterTrait;
use PhpBg\DvbPsi\Context\StreamContext;
use PhpBg\DvbPsi\Parser as PsiParser;
use PhpBg\DvbPsi\TableParsers\Pat;
use PhpBg\DvbPsi\TableParsers\Pmt;
use PhpBg\MpegTs\Packetizer;
use PhpBg\MpegTs\Parser as TsParser;
use PhpBg\MpegTs\Pid;
use Psr\Log\LoggerInterface;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Stream\WritableStreamInterface;

/**
 * Class TSStream
 * Write process output (TS) to clients
 *
 * Events
 *   exit: will be emitted when the underlying process is terminated
 */
class TSStream extends EventEmitter
{
    use EventEmitterTrait;

    /**
     * @var Process
     */
    private $process;

    /**
     * @var PsiParser
     */
    private $psiParser;

    /**
     * @var TsParser
     */
    private $tsParser;

    /**
     * @var Packetizer
     */
    private $tsPacketizer;

    /**
     * @var [<pid> => [<client>, <client>, ...]]
     */
    private $tsClients;

    /**
     * @var LoggerInterface
     */
    private $logger;

    private $exited = false;

    /**
     * @var StreamContext
     */
    private $streamContext;

    /**
     * @var Pmt
     */
    private $pmtParser;

    private $clients = [];

    private $epgGrabbing = false;

    public function __construct(Process $process, LoggerInterface $logger, LoopInterface $loop)
    {
        $this->process = $process;
        $this->logger = $logger;
        $this->tsClients = [];
        $this->psiParserRegistrations = [];

        $this->process->on('exit', [$this, '_handleExit']);

        $this->process->start($loop);

        $this->process->stderr->on('data', function ($chunk) {
            if (empty($chunk)) {
                return;
            }
            $this->logger->warning($chunk);
        });

        $this->tsParser = new TsParser();
        $this->tsParser->on('error', [$this, '_handleDataStreamErrors']);
        $this->tsParser->on('ts', [$this, '_handleTs']);

        $this->tsPacketizer = new Packetizer();
        $this->tsPacketizer->on('error', [$this, '_handleDataStreamErrors']);
        $this->tsPacketizer->on('data', [$this->tsParser, 'write']);

        $this->psiParser = new PsiParser();
        $this->psiParser->registerTableParser(new Pat());
        $this->psiParser->on('error', [$this, '_handleDataStreamErrors']);
        $this->tsParser->on('pes', function ($pid, $data) {
            $this->psiParser->write($pid, $data);
        });
        foreach ($this->psiParser->getRegisteredPids() as $pid) {
            $this->tsParser->addPidFilter(new Pid($pid));
        }
        $this->psiParser->on('parserAdd', function ($parser, $pidsAdded) {
            foreach ($pidsAdded as $pid) {
                $this->tsParser->addPidFilter(new Pid($pid));
            }
        });
        $this->psiParser->on('parserRemove', function ($parser, $pidsRemoved) {
            foreach ($pidsRemoved as $pid) {
                $this->tsParser->removePidFilter(new Pid($pid));
            }
        });

        $this->streamContext = new StreamContext();
        $this->psiParser->on('pat', function ($pat) {
            $this->streamContext->addPat($pat);
        });
        $this->psiParser->on('pmt', function ($pmt) {
            $this->streamContext->addPmt($pmt);
        });

        // Register PMT on PAT updates
        $this->streamContext->on('pat-update', function ($newPat) {
            if (isset($this->pmtParser)) {
                $this->psiParser->unregisterTableParser($this->pmtParser);
            }
            $this->pmtParser = new \PhpBg\DvbPsi\TableParsers\Pmt();
            $newPids = array_values($newPat->programs);
            $this->pmtParser->setPids($newPids);
            $this->psiParser->registerTableParser($this->pmtParser);
        });

        // We don't listen to stdout end or close event because we listen on exit on process and that should be enough
        $this->process->stdout->on('error', [$this, '_handleDataStreamErrors']);
        $this->process->stdout->on('data', [$this->tsPacketizer, 'write']);
    }

    public function _handleDataStreamErrors(\Exception $exception)
    {
        $this->logger->debug("Parser or stream error", ['exception' => $exception]);
    }

    public function _handleTs($pid, $data)
    {
        if (!isset($this->tsClients[$pid])) {
            return;
        }
        foreach ($this->tsClients[$pid] as $client) {
            /**
             * @var WritableStreamInterface $client
             */
            $client->write($data);
        }
    }

    public function _handleExit()
    {
        $this->logger->debug("Process exited : {$this->process->getCommand()}");

        $this->exited = true;

        if ($this->process->stdout != null) {
            $this->process->stdout->removeAllListeners();
        }

        if ($this->process->stderr != null) {
            $this->process->stderr->removeAllListeners();
        }

        $this->process->removeAllListeners();

        $this->psiParser->removeAllListeners();

        $this->tsParser->removeAllListeners();

        $endedClients = [];
        foreach ($this->tsClients as $clients) {
            foreach ($clients as $client) {
                if (in_array($client, $endedClients)) {
                    continue;
                }
                /**
                 * @var WritableStreamInterface $client
                 */
                $client->close();
                $endedClients[] = $client;
            }
        }
        $this->tsClients = [];

        $this->streamContext->removeAllListeners();

        $this->clients = [];

        $this->emit('exit');
        $this->removeAllListeners();
    }

    /**
     * Register a client for given PIDs
     *
     * @param WritableStreamInterface $client
     * @param array $pids
     */
    public function addClient(WritableStreamInterface $client, array $pids, $serviceId)
    {
        if ($this->exited) {
            throw new \RuntimeException();
        }
        if (empty($pids)) {
            throw new \RuntimeException("You must specify at least one PID to listen to");
        }
        $this->clients[] = $client;

        // Add PAT and PMT to pids
        if (!in_array(0, $pids)) {
            $pids[] = 0;
        }
        if (isset($this->streamContext->pat)) {
            if (isset($this->streamContext->pat->programs[$serviceId])) {
                if (!in_array($this->streamContext->pat->programs[$serviceId], $pids)) {
                    $pids[] = $this->streamContext->pat->programs[$serviceId];
                }
            }
        }
        if (!isset($this->streamContext->pat)) {
            $this->streamContext->once('pat-update', function () use ($client, $serviceId) {
                if (!in_array($client, $this->clients)) {
                    return;
                }
                if (isset($this->streamContext->pat->programs[$serviceId])) {
                    $pid = $this->streamContext->pat->programs[$serviceId];
                    if (!isset($this->tsClients[$pid])) {
                        $this->tsClients[$pid] = [];
                        $this->tsParser->addPidPassthrough(new Pid($pid));
                    }
                    $this->tsClients[$pid][] = $client;
                }
            });
        }
        foreach ($pids as $pid) {
            if (!isset($this->tsClients[$pid])) {
                $this->tsClients[$pid] = [];
                $this->tsParser->addPidPassthrough(new Pid($pid));
            }
            $this->tsClients[$pid][] = $client;
        }
        $client->on('error', function ($e) {
            $this->logger->warning("Client error", ['exception' => $e]);
        });
        $client->on('close', function () use ($client) {
            $this->removeClient($client);
        });
    }

    /**
     * Remove a client
     *
     * @param WritableStreamInterface $client
     */
    public function removeClient(WritableStreamInterface $client)
    {
        if ($this->exited) {
            return;
        }
        $this->logger->debug("Remove client");
        $pos = array_search($client, $this->clients);
        if ($pos !== false) {
            unset($this->clients[$pos]);
        }
        foreach ($this->tsClients as $pid => $clients) {
            $pos = array_search($client, $clients);
            if ($pos === false) {
                continue;
            }
            unset($clients[$pos]);
            if (empty($clients)) {
                unset($this->tsClients[$pid]);
                $this->tsParser->removePidPassthrough(new Pid($pid));
            } else {
                $this->tsClients[$pid] = array_values($clients);
            }
        }
        $this->autoKillIfKillable();
    }

    public function terminate()
    {
        if ($this->exited) {
            return;
        }
        $this->logger->debug("Terminate process " . $this->process->getCommand());
        if (!$this->process->terminate(SIGKILL)) {
            throw new \RuntimeException("Unable to signal process");
        }
    }

    protected function autoKillIfKillable()
    {
        if (empty($this->tsClients) && !$this->epgGrabbing) {
            $this->terminate();
        }
    }

    /**
     * Return the list of clients
     *
     * @return array
     */
    public function getClients(): array
    {
        $clients = [];
        foreach ($this->tsClients as $client) {
            if (in_array($client, $clients)) {
                continue;
            }
            $clients[] = $client;
        }
        return $clients;
    }

    /**
     * @return PsiParser
     */
    public function getPsiParser(): PsiParser
    {
        return $this->psiParser;
    }

    /**
     * Maintain stream for EPG grabbing, event if there are no clients
     */
    public function setEpgGrabbing()
    {
        $this->epgGrabbing = true;
    }

    /**
     * @see TSStream::setEpgGrabbing()
     */
    public function releaseEpgGrabbing()
    {
        $this->epgGrabbing = false;
        $this->autoKillIfKillable();
    }
}