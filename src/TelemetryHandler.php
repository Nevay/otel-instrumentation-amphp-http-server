<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

interface TelemetryHandler {

    public function handleRequest(Request $request, ContextInterface $context): ContextInterface;

    public function handleResponse(Response $response, Request $request, ContextInterface $context): void;

    public function handleError(Throwable $e, Request $request, ContextInterface $context): void;
}
