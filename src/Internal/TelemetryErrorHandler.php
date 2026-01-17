<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\Internal;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

/**
 * @internal
 */
final class TelemetryErrorHandler implements ErrorHandler {

    /**
     * @param ErrorHandler $errorHandler
     * @param list<TelemetryHandler> $handlers
     */
    public function __construct(
        private readonly ErrorHandler $errorHandler,
        private readonly array $handlers,
    ) {}

    public function handleError(int $status, ?string $reason = null, ?Request $request = null): Response {
        if (!$request?->hasAttribute(ContextInterface::class)) {
            return $this->errorHandler->handleError($status, $reason, $request);
        }

        $context = $request->getAttribute(ContextInterface::class);
        assert($context instanceof ContextInterface);

        $scope = $context->activate();
        try {
            $response = $this->errorHandler->handleError($status, $reason, $request);
        } catch (Throwable $e) {
            foreach ($this->handlers as $handler) {
                $handler->handleError($e, $request, $context);
            }

            throw $e;
        } finally {
            $scope->detach();
        }

        foreach ($this->handlers as $handler) {
            $handler->handleResponse($response, $request, $context);
        }

        return $response;
    }
}
