<?php


namespace PhpBg\WatchTv\Pages\Epg;


use PhpBg\MiniHttpd\Middleware\ContextTrait;
use Psr\Http\Message\ServerRequestInterface;

class EpgHtml
{
    use ContextTrait;

    /**
     * Main homepage listing channels
     * @param ServerRequestInterface $request
     * @return array
     */
    public function __invoke(ServerRequestInterface $request)
    {
        $context = $this->getContext($request);
        $context->renderOptions['bottomScripts'] = [
            $this->isRelease ? "/vue-2.5.22.min.js" : "/vue-2.5.22.js",
            "/jquery-3.3.1.min.js",
            "/moment-2.24.0.min.js",
        ];
        $context->renderOptions['headCss'] = ['/w3css-4.12.css'];
    }
}