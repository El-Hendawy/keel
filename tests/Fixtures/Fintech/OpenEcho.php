<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Fintech;

use Keel\Crypto\MessageEncryptionMiddleware;
use Keel\Routing\Attribute\Post;
use Psr\Http\Message\ServerRequestInterface;

#[Post('/open/echo')]
final class OpenEcho
{
    public function __invoke(EchoInput $in, ServerRequestInterface $request): EchoOutput
    {
        return new EchoOutput($in->message, $request->getAttribute(MessageEncryptionMiddleware::ENCRYPTED) === true);
    }
}
