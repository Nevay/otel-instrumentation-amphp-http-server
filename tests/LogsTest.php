<?php /** @noinspection HttpUrlsUsage */ declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Amp\Cancellation;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpErrorException;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\ResourceServerSocketFactory;
use League\Uri\Http;
use LogicException;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\Logs;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\Tracing;
use Nevay\OTelSDK\Common\Configurator\RuleConfiguratorBuilder;
use Nevay\OTelSDK\Logs\LoggerConfig;
use Nevay\OTelSDK\Logs\LoggerProviderBuilder;
use Nevay\OTelSDK\Logs\LogRecordExporter\InMemoryLogRecordExporter;
use Nevay\OTelSDK\Logs\LogRecordProcessor\BatchLogRecordProcessor;
use Nevay\OTelSDK\Trace\Sampler\Composable\ComposableAlwaysOnSampler;
use Nevay\OTelSDK\Trace\Sampler\CompositeSampler;
use Nevay\OTelSDK\Trace\TracerProviderBuilder;
use Psr\Log\LoggerInterface;
use RuntimeException;
use function is_array;

final class LogsTest extends AsyncTestCase {

    public function testLogs(): void {
        $loggerProvider = (new LoggerProviderBuilder())
            ->addLogRecordProcessor(new BatchLogRecordProcessor($exporter = new InMemoryLogRecordExporter()))
            ->build();

        $server = $this->startServer(new Logs($loggerProvider),
            new ClosureRequestHandler(static fn() => throw new LogicException()),
            new class implements ErrorHandler {

                public function handleError(int $status, ?string $reason = null, ?\Amp\Http\Server\Request $request = null): Response {
                    throw new RuntimeException('log message');
                }
            }
        );

        try {
            $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $loggerProvider->shutdown();
        }

        $logRecords = $exporter->collect(true);
        $this->assertCount(1, $logRecords);

        $attributes = $logRecords[0]->getAttributes();
        $this->assertSame('log message', $attributes->get('exception.message'));
        $this->assertSame(RuntimeException::class, $attributes->get('exception.type'));
        $this->assertNull($logRecords[0]->getSpanContext());
    }

    public function testLogRecordContainsTraceContext(): void {
        $tracerProvider = (new TracerProviderBuilder())
            ->build();
        $loggerProvider = (new LoggerProviderBuilder())
            ->addLogRecordProcessor(new BatchLogRecordProcessor($exporter = new InMemoryLogRecordExporter()))
            ->build();

        $server = $this->startServer([new Tracing($tracerProvider), new Logs($loggerProvider)],
            new ClosureRequestHandler(static fn() => throw new HttpErrorException(HttpStatus::SERVICE_UNAVAILABLE)),
            new class implements ErrorHandler {

                public function handleError(int $status, ?string $reason = null, ?\Amp\Http\Server\Request $request = null): Response {
                    throw new RuntimeException('log message');
                }
            }
        );

        try {
            $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $loggerProvider->shutdown();
        }

        $logRecords = $exporter->collect(true);
        $this->assertCount(1, $logRecords);

        $this->assertNotNull($logRecords[0]->getSpanContext());
    }

    public function testTraceBasedLog(): void {
        $tracerProvider = (new TracerProviderBuilder())
            ->setSampler(new CompositeSampler(new ComposableAlwaysOnSampler()))
            ->build();
        $loggerProvider = (new LoggerProviderBuilder())
            ->addLogRecordProcessor(new BatchLogRecordProcessor($exporter = new InMemoryLogRecordExporter()))
            ->setLoggerConfigurator((new RuleConfiguratorBuilder())
                ->withRule(static fn(LoggerConfig $config) => $config->traceBased = true, name: 'tbachert/otel-instrumentation-amphp-http-server')
                ->toConfigurator())
            ->build();

        $server = $this->startServer([new Tracing($tracerProvider), new Logs($loggerProvider)],
            new ClosureRequestHandler(static fn() => throw new HttpErrorException(HttpStatus::SERVICE_UNAVAILABLE)),
            new class implements ErrorHandler {

                public function handleError(int $status, ?string $reason = null, ?\Amp\Http\Server\Request $request = null): Response {
                    throw new RuntimeException('log message');
                }
            }
        );

        try {
            $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $loggerProvider->shutdown();
        }

        $logRecords = $exporter->collect(true);
        $this->assertCount(1, $logRecords);

        $this->assertNotNull($logRecords[0]->getSpanContext());
    }

    public function testLogsNoMessageOnSuccessfulRequest(): void {
        $loggerProvider = (new LoggerProviderBuilder())
            ->addLogRecordProcessor(new BatchLogRecordProcessor($exporter = new InMemoryLogRecordExporter()))
            ->build();

        $server = $this->startServer(new Logs($loggerProvider));

        try {
            $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $loggerProvider->shutdown();
        }

        $logRecords = $exporter->collect(true);
        $this->assertCount(0, $logRecords);
    }

    private function startServer(TelemetryHandler|array $handler, ?RequestHandler $requestHandler = null, ?ErrorHandler $errorHandler = null): HttpServer {
        $requestHandler ??= new ClosureRequestHandler(static fn(): Response => new Response());
        $errorHandler ??= new DefaultErrorHandler();

        if (!is_array($handler)) {
            $handler = [$handler];
        }

        $server = new SocketHttpServer(
            $this->createMock(LoggerInterface::class),
            new ResourceServerSocketFactory(),
            new SocketClientFactory($this->createMock(LoggerInterface::class)),
            httpDriverFactory: new TelemetryDriverFactory(new DefaultHttpDriverFactory($this->createMock(LoggerInterface::class)), $handler),
        );
        $server->expose('127.0.0.1:0');
        $server->start($requestHandler, $errorHandler);

        return $server;
    }

    private function request(HttpServer $server, Request $request, ?Cancellation $cancellation = null): \Amp\Http\Client\Response {
        $address = $server->getServers()[0]->getAddress();
        $request->setUri(Http::parse($request->getUri(), "http://$address"));

        return HttpClientBuilder::buildDefault()->request($request, $cancellation);
    }
}
