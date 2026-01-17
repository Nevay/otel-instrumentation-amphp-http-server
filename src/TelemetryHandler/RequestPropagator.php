<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Nevay\OTelInstrumentation\AmphpHttpServer\Internal\HttpMessagePropagationGetter;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Throwable;

final class RequestPropagator implements TelemetryHandler {

    private readonly TextMapPropagatorInterface $propagator;
    private readonly PropagationGetterInterface $propagationGetter;

    public function __construct(TextMapPropagatorInterface $propagator) {
        $this->propagator = $propagator;
        $this->propagationGetter = new HttpMessagePropagationGetter();
    }

    public function handleRequest(Request $request, ContextInterface $context): ContextInterface {
        return $this->propagator->extract($request, $this->propagationGetter, $context);
    }

    public function handleResponse(Response $response, Request $request, ContextInterface $context): void {
        // no-op
    }

    public function handleError(Throwable $e, Request $request, ContextInterface $context): void {
        // no-op
    }
}
