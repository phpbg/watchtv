<?php


namespace PhpBg\WatchTv\Api;


use PhpBg\DvbPsi\Descriptors\Values\ContentNibble;
use Psr\Http\Message\ServerRequestInterface;

class ContentDescriptors
{
    public function __invoke(ServerRequestInterface $request)
    {
        return ContentNibble::NIBBLES;
    }
}