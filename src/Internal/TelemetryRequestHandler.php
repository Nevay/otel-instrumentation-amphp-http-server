<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\Internal;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

/**
 * @internal
 */
final class TelemetryRequestHandler implements RequestHandler {

    /**
     * @param RequestHandler $requestHandler
     * @param list<TelemetryHandler> $handlers
     */
    public function __construct(
        private readonly RequestHandler $requestHandler,
        private readonly array $handlers,
    ) {}

    public function handleRequest(Request $request): Response {
        $context = Context::getCurrent();
        foreach ($this->handlers as $handler) {
            $context = $handler->handleRequest($request, $context);
        }

        $request->setAttribute(ContextInterface::class, $context);

        $scope = $context->activate();
        try {
            $response = $this->requestHandler->handleRequest($request);
        } finally {
            $scope->detach();
        }

        foreach ($this->handlers as $handler) {
            $handler->handleResponse($response, $request, $context);
        }

        return $response;
    }
}