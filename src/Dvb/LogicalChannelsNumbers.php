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

use PhpBg\DvbPsi\Context\GlobalContext;
use PhpBg\DvbPsi\Descriptors\PrivateDescriptors\EACEM\LogicalChannel;
use PhpBg\DvbPsi\Exception;

trait LogicalChannelsNumbers
{
    /**
     * Return logical channels numbers, if any
     *
     * @param GlobalContext $dvbGlobalContext
     * @return array|null Array of <service id> => <logical channel number>
     * @throws Exception
     */
    protected function getLogicalChannelsNumbers(GlobalContext $dvbGlobalContext)
    {
        $nitAggregators = $dvbGlobalContext->getNitAggregators();
        if (empty($nitAggregators)) {
            return null;
        }
        if (count($nitAggregators) > 1) {
            throw new Exception("Only one DVB network is supported");
        }
        $nitAggregator = current($nitAggregators);
        if (!$nitAggregator->isComplete()) {
            return null;
        }
        $logicalChannelNumbers = [];
        foreach ($nitAggregator->segments as $nit) {
            foreach ($nit->transportStreams as $ts) {
                $lcnDescriptor = null;
                foreach ($ts->descriptors as $descriptor) {
                    if ($descriptor instanceof LogicalChannel) {
                        $lcnDescriptor = $descriptor;
                        break;
                    }
                }
                if (isset($lcnDescriptor)) {
                    $logicalChannelNumbers += $lcnDescriptor->services;
                }
            }
        }
        return $logicalChannelNumbers;
    }
}