<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Mesh;

use Keel\Auth\Attribute\Authorize;
use Keel\Auth\Principal;
use Keel\Http\RequestContext;
use Keel\Routing\Attribute\Post;
use Psr\Http\Message\ServerRequestInterface;

/** Billing's side: who called, for which tenant, with which request id and time budget. */
#[Post('/invoices')]
#[Authorize(scopes: ['invoices:write'])]
final class CreateInvoice
{
    public function __invoke(InvoiceInput $in, Principal $caller, RequestContext $context, ServerRequestInterface $request): CallFacts
    {
        $timeout = $request->getHeaderLine('Keel-Timeout-Ms');

        return new CallFacts($caller->id, $caller->kind, $caller->tenantId, $context->requestId, $timeout === '' ? null : (int) $timeout, $in->orderId);
    }
}
