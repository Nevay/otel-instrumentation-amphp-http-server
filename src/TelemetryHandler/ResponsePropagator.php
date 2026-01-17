<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Nevay\OTelInstrumentation\AmphpHttpServer\Internal\HttpMessagePropagationSetter;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;
use Throwable;

final class ResponsePropagator implements TelemetryHandler {

    private readonly ResponsePropagatorInterface $responsePropagator;
    private readonly PropagationSetterInterface $propagationSetter;

    public function __construct(ResponsePropagatorInterface $responsePropagator) {
        $this->responsePropagator = $responsePropagator;
        $this->propagationSetter = new HttpMessagePropagationSetter();
    }

    public function handleRequest(Request $request, ContextInterface $context): ContextInterface {
        return $context;
    }

    public function handleResponse(Response $response, Request $request, ContextInterface $context): void {
        $this->responsePropagator->inject($response, $this->propagationSetter, $context);
    }

    public function handleError(Throwable $e, Request $request, ContextInterface $context): void {
        // no-op
    }
}
