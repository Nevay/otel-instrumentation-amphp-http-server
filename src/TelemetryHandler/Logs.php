<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Composer\InstalledVersions;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

final class Logs implements TelemetryHandler {

    private readonly LoggerInterface $logger;

    public function __construct(
        LoggerProviderInterface $loggerProvider,
    ) {
        $this->logger = $loggerProvider->getLogger(
            'tbachert/otel-instrumentation-amphp-http-server',
            InstalledVersions::getPrettyVersion('tbachert/otel-instrumentation-amphp-http-server'),
            'https://opentelemetry.io/schemas/1.39.0',
        );
    }

    public function handleRequest(Request $request, ContextInterface $context): ContextInterface {
        return $context;
    }

    public function handleResponse(Response $response, Request $request, ContextInterface $context): void {
        // no-op
    }

    public function handleError(Throwable $e, Request $request, ContextInterface $context): void {
        $this->logger
            ->logRecordBuilder()
            ->setEventName('http.server.request.error')
            ->setSeverityNumber(Severity::ERROR)
            ->setException($e)
            ->setContext($context)
            ->emit();
    }
}
