<?php


namespace PhpBg\WatchTv\Pages\Guide;


use PhpBg\MiniHttpd\Middleware\ContextTrait;
use Psr\Http\Message\ServerRequestInterface;

class Guide
{
    use ContextTrait;

    private $isRelease;

    public function __construct(bool $isRelease)
    {
        $this->isRelease = $isRelease;
    }

    /**
     * Main homepage listing channels
     * @param ServerRequestInterface $request
     * @return array
     */
    public function __invoke(ServerRequestInterface $request)
    {
        $context = $this->getContext($request);
        $context->renderOptions['bottomScripts'] = [
            $this->isRelease ? "/vue-2.6.11.min.js" : "/vue-2.6.11.js",
            "/jquery-3.3.1.min.js",
            "/moment-2.24.0.min.js",
        ];
        $context->renderOptions['headCss'] = ['/w3css-4.12.css'];
    }
}