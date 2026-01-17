<?php /** @noinspection HttpUrlsUsage */ declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Amp\Cancellation;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceServerSocketFactory;
use League\Uri\Http;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\Metrics;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\Tracing;
use Nevay\OTelSDK\Metrics\Aggregation\DropAggregation;
use Nevay\OTelSDK\Metrics\Data\Histogram;
use Nevay\OTelSDK\Metrics\Data\Metric;
use Nevay\OTelSDK\Metrics\Data\Sum;
use Nevay\OTelSDK\Metrics\MeterProviderBuilder;
use Nevay\OTelSDK\Metrics\MetricExporter\InMemoryMetricExporter;
use Nevay\OTelSDK\Metrics\MetricReader\PeriodicExportingMetricReader;
use Nevay\OTelSDK\Metrics\MetricReader\PullMetricReader;
use Nevay\OTelSDK\Metrics\View;
use Nevay\OTelSDK\Trace\TracerProviderBuilder;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use function Amp\delay;
use function assert;
use function count;
use function is_array;

final class MetricsTest extends AsyncTestCase {

    public function testRequestDurationAttributes(): void {
        $meterProvider = (new MeterProviderBuilder())
            ->addMetricReader(new PeriodicExportingMetricReader($exporter = new InMemoryMetricExporter()))
            ->addView(new View(), name: 'http.server.request.duration')
            ->addView(new View(name: 'http.server.request.duration.opt-in', attributeKeys: ['*']), name: 'http.server.request.duration')
            ->addView(new View(aggregation: new DropAggregation()))
            ->build();

        $server = $this->startServer(new Metrics($meterProvider));
        $address = $server->getServers()[0]->getAddress();
        assert($address instanceof InternetAddress);

        try {
            $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $meterProvider->shutdown();
        }

        /**
         * @var array{
         *     0: Metric<Histogram>,
         *     1: Metric<Histogram>,
         * } $metrics
         */
        $metrics = $exporter->collect();
        $this->assertCount(2, $metrics);

        $this->assertInstanceOf(Histogram::class, $metrics[0]->data);
        $this->assertSame([
            'http.request.method' => 'GET',
            'url.scheme' => 'http',
            'network.protocol.version' => '1.1',
            'http.response.status_code' => 200,
        ], $metrics[0]->data->dataPoints[0]->attributes->toArray());

        $this->assertInstanceOf(Histogram::class, $metrics[1]->data);
        $this->assertSame([
            'http.request.method' => 'GET',
            'server.address' => $address->getAddress(),
            'server.port' => $address->getPort(),
            'url.scheme' => 'http',
            'network.protocol.version' => '1.1',
            'http.response.status_code' => 200,
        ], $metrics[1]->data->dataPoints[0]->attributes->toArray());
    }

    public function testActiveRequestsAttributes(): void {
        $meterProvider = (new MeterProviderBuilder())
            ->addMetricReader(new PeriodicExportingMetricReader($exporter = new InMemoryMetricExporter()))
            ->addView(new View(), name: 'http.server.active_requests')
            ->addView(new View(name: 'http.server.active_requests.opt-in', attributeKeys: ['*']), name: 'http.server.active_requests')
            ->addView(new View(aggregation: new DropAggregation()))
            ->build();

        $server = $this->startServer(new Metrics($meterProvider));
        $address = $server->getServers()[0]->getAddress();
        assert($address instanceof InternetAddress);

        try {
            $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $meterProvider->shutdown();
        }

        /**
         * @var array{
         *     0: Metric<Sum>,
         *     1: Metric<Sum>,
         * } $metrics
         */
        $metrics = $exporter->collect();
        $this->assertCount(2, $metrics);

        $this->assertInstanceOf(Sum::class, $metrics[0]->data);
        $this->assertSame([
            'http.request.method' => 'GET',
            'url.scheme' => 'http',
        ], $metrics[0]->data->dataPoints[0]->attributes->toArray());

        $this->assertInstanceOf(Sum::class, $metrics[1]->data);
        $this->assertSame([
            'http.request.method' => 'GET',
            'server.address' => $address->getAddress(),
            'server.port' => $address->getPort(),
            'url.scheme' => 'http',
        ], $metrics[1]->data->dataPoints[0]->attributes->toArray());
    }

    public function testActiveRequestsValue(): void {
        $meterProvider = (new MeterProviderBuilder())
            ->addMetricReader($reader = new PullMetricReader($exporter = new InMemoryMetricExporter()))
            ->addView(new View(), name: 'http.server.active_requests')
            ->addView(new View(aggregation: new DropAggregation()))
            ->build();

        $server = $this->startServer(new Metrics($meterProvider), new ClosureRequestHandler(static function() use ($meterProvider): Response {
            delay(0.1);
            return new Response();
        }));

        try {
            for ($i = 0; $i < 5; $i++) {
                EventLoop::queue($this->request(...), $server, new Request('/foo'));
            }

            delay(0.05);
            $reader->collect();
            delay(0.1);
            $reader->collect();
        } finally {
            $server->stop();
            $meterProvider->shutdown();
        }

        /**
         * @var array{
         *      0: Metric<Sum>,
         *      1: Metric<Sum>,
         * } $metrics
         */
        $metrics = $exporter->collect();
        $this->assertCount(2, $metrics);

        $this->assertInstanceOf(Sum::class, $metrics[0]->data);
        $this->assertSame(5, $metrics[0]->data->dataPoints[0]->value);
        $this->assertSame(0, $metrics[1]->data->dataPoints[0]->value);
    }

    public function testMetricsHaveTraceExemplars(): void {
        $tracerProvider = (new TracerProviderBuilder())
            ->build();
        $meterProvider = (new MeterProviderBuilder())
            ->addMetricReader(new PeriodicExportingMetricReader($exporter = new InMemoryMetricExporter()))
            ->build();

        $server = $this->startServer([new Tracing($tracerProvider), new Metrics($meterProvider)]);

        try {
            $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $meterProvider->shutdown();
        }

        $metrics = $exporter->collect();
        $this->assertNotEmpty($metrics);

        foreach ($metrics as $metric) {
            foreach ($metric->data->dataPoints as $dataPoint) {
                $this->assertNotEmpty($dataPoint->exemplars);
                foreach ($dataPoint->exemplars as $exemplar) {
                    $this->assertNotNull($exemplar->spanContext);
                }
            }
        }
    }

    private function startServer(TelemetryHandler|array $handler, ?RequestHandler $requestHandler = null): HttpServer {
        $requestHandler ??= new ClosureRequestHandler(static fn(): Response => new Response());

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
        $server->start($requestHandler, new DefaultErrorHandler());

        return $server;
    }

    private function request(HttpServer $server, Request $request, ?Cancellation $cancellation = null): \Amp\Http\Client\Response {
        $address = $server->getServers()[0]->getAddress();
        $request->setUri(Http::parse($request->getUri(), "http://$address"));

        return HttpClientBuilder::buildDefault()->request($request, $cancellation);
    }
}
