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

class Channels
{
    private $channelsByName;
    private $filepath;
    private $scanTablesPath;

    /**
     * Channels constructor.
     * @param string $filePath Path to dvbv5-scan output file
     * @param string $scanTablesPath Path to dtv-scan-tables (initial scan files)
     */
    public function __construct(string $filePath, string $scanTablesPath)
    {
        $this->filepath = $filePath;
        $this->scanTablesPath = $scanTablesPath;
    }

    /**
     * Return path to dvbv5-scan output file
     * @return string
     */
    public function getChannelsFilePath(): string
    {
        return $this->filepath;
    }

    /**
     * Return path to dtv-scan-tables (initial scan files)
     * @return string
     */
    public function getScanTablesPath(): string {
        return $this->scanTablesPath;
    }

    /**
     * Return all channels descriptors, indexed by channel name (as of dvbv5 native channels config file)
     * @see https://www.linuxtv.org/wiki/index.php/Dvbv5-scan
     *
     * @param bool Optional Force re-reading channels descriptors. Default: false
     * @return array
     * @throws ChannelsNotFoundException
     */
    public function getChannelsByName(bool $forceReload = false): array
    {
        if (!isset($this->channelsByName) || $forceReload) {
            if (!is_readable($this->filepath)) {
                throw new ChannelsNotFoundException("{$this->filepath} is not a file");
            }

            $this->channelsByName = parse_ini_file($this->filepath, true);
            if ($this->channelsByName === false) {
                throw new ChannelsNotFoundException("{$this->filepath} is not a valid channel file");
            }
            foreach ($this->channelsByName as $name => &$descriptor) {
                $descriptor['NAME'] = $name;
            }
            unset($descriptor);
        }

        return $this->channelsByName;
    }

    /**
     * Return channel descriptor
     * @param int $channelId
     * @return array
     * @throws ChannelsNotFoundException
     */
    public function getChannelByServiceId(int $channelId): array
    {
        $channelsByName = $this->getChannelsByName();
        $requestedChannels = array_filter($channelsByName, function ($descriptor) use ($channelId) {
            return $channelId == @$descriptor['SERVICE_ID'];
        });
        if (count($requestedChannels) !== 1) {
            throw new ChannelsNotFoundException("Invalid channel : $channelId");
        }

        return current($requestedChannels);
    }

    /**
     * Return the first video PID and the first audio PID (if any)
     *
     * @param array $channelDescriptor
     * @return array Array of PIDs
     */
    public function getMainVideoAudioPids(array $channelDescriptor) {
        $pids = [];
        if (!empty($channelDescriptor['VIDEO_PID'])) {
            $pidsStr = $channelDescriptor['VIDEO_PID'];
            $ret = explode(' ', trim($pidsStr));
            if (!empty($ret)) {
                $pids[] = $ret[0];
            }
        }

        if (!empty($channelDescriptor['AUDIO_PID'])) {
            $pidsStr = $channelDescriptor['AUDIO_PID'];
            $ret = explode(' ', trim($pidsStr));
            if (!empty($ret)) {
                $pids[] = $ret[0];
            }
        }
        return $pids;
    }
}