<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\RequestHandler;
use Nevay\OTelInstrumentation\AmphpHttpServer\Internal\TelemetryErrorHandler;
use Nevay\OTelInstrumentation\AmphpHttpServer\Internal\TelemetryRequestHandler;

final class TelemetryDriverFactory implements HttpDriverFactory {

    /**
     * @param HttpDriverFactory $httpDriverFactory
     * @param array<TelemetryHandler> $handlers
     */
    public function __construct(
        private readonly HttpDriverFactory $httpDriverFactory,
        private readonly array $handlers,
    ) {}

    public function createHttpDriver(RequestHandler $requestHandler, ErrorHandler $errorHandler, Client $client): HttpDriver {
        return $this->httpDriverFactory->createHttpDriver(
            new TelemetryRequestHandler($requestHandler, $this->handlers),
            new TelemetryErrorHandler($errorHandler, $this->handlers),
            $client,
        );
    }

    public function getApplicationLayerProtocols(): array {
        return $this->httpDriverFactory->getApplicationLayerProtocols();
    }
}