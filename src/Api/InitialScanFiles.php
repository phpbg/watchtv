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

use PhpBg\MiniHttpd\Controller\AbstractController;
use PhpBg\MiniHttpd\Middleware\ContextTrait;
use PhpBg\WatchTv\Dvb\Channels;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Filter\StringTrim;
use Zend\Validator\InArray;

/**
 * API that return available initial scan files for a given network
 */
class InitialScanFiles extends AbstractController
{
    use ContextTrait;

    private $channels;

    public function __construct(Channels $channels)
    {
        $this->channels = $channels;
    }

    public function __invoke(ServerRequestInterface $request)
    {
        $network = $this->getFromQuery($request, 'network', null, new InArray(['haystack' => [
            'atsc', 'dvb-c', 'dvb-s', 'dvb-t', 'isdb-t'
        ]]), new StringTrim());

        $initialScanFiles = [];
        $scanPath = $this->channels->getScanTablesPath();
        $files = array_diff(scandir($scanPath . DIRECTORY_SEPARATOR . $network), array('.', '..'));
        foreach ($files as $file) {
            $initialScanFiles[$scanPath . DIRECTORY_SEPARATOR . $network . DIRECTORY_SEPARATOR . $file] = $file;
        }
        return $initialScanFiles;
    }
}