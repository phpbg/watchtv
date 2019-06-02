<?php
/**
 * Created by PhpStorm.
 * User: sam
 * Date: 28/03/19
 * Time: 10:11
 */

namespace PhpBg\WatchTv\ProcessAdapter;

use React\ChildProcess\Process;

interface TunerProcessAdapterInterface extends ProcessAdapterInterface
{
    /**
     * Start a new process that must output TS stream to its stdout
     *
     * @param array $channelDescriptor
     * @return Process
     */
    public function start(array $channelDescriptor): Process;
}