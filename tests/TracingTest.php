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
use League\Uri\UriTemplate;
use LogicException;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver\RequestAttributeResolver;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\RequestPropagator;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\ResponsePropagator;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\Tracing;
use Nevay\OTelSDK\Trace\IdGenerator;
use Nevay\OTelSDK\Trace\SpanExporter\InMemorySpanExporter;
use Nevay\OTelSDK\Trace\SpanProcessor\BatchSpanProcessor;
use Nevay\OTelSDK\Trace\TracerProviderBuilder;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator;
use Psr\Log\LoggerInterface;
use function assert;
use function hex2bin;
use function is_array;

final class TracingTest extends AsyncTestCase {

    public function testTracing(): void {
        $tracerProvider = (new TracerProviderBuilder())
            ->addSpanProcessor(new BatchSpanProcessor($exporter = new InMemorySpanExporter()))
            ->build();

        $server = $this->startServer(new Tracing($tracerProvider));
        $address = $server->getServers()[0]->getAddress();
        assert($address instanceof InternetAddress);

        try {
            $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $tracerProvider->shutdown();
        }

        $spans = $exporter->collect(true);
        $this->assertCount(1, $spans);

        $this->assertSame('GET', $spans[0]->getName());

        $attributes = $spans[0]->getAttributes();
        $this->assertSame('GET', $attributes->get('http.request.method'));
        $this->assertSame('/foo', $attributes->get('url.path'));
        $this->assertSame('http', $attributes->get('url.scheme'));
        $this->assertSame($address->getAddress(), $attributes->get('server.address'));
        $this->assertSame($address->getPort(), $attributes->get('server.port'));
        $this->assertSame(200, $attributes->get('http.response.status_code'));
    }

    public function testHttpRouteResolver(): void {
        $tracerProvider = (new TracerProviderBuilder())
            ->addSpanProcessor(new BatchSpanProcessor($exporter = new InMemorySpanExporter()))
            ->build();

        $server = $this->startServer(new Tracing($tracerProvider, routeResolver: new RequestAttributeResolver(UriTemplate::class)), new ClosureRequestHandler(static function(\Amp\Http\Server\Request $request): Response {
            $request->setAttribute(UriTemplate::class, new UriTemplate('/{param}'));

            return new Response();
        }));
        $address = $server->getServers()[0]->getAddress();
        assert($address instanceof InternetAddress);

        try {
            $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $tracerProvider->shutdown();
        }

        $spans = $exporter->collect(true);
        $this->assertCount(1, $spans);

        $this->assertSame('GET /{param}', $spans[0]->getName());

        $attributes = $spans[0]->getAttributes();
        $this->assertSame('/{param}', $attributes->get('http.route'));
    }

    public function testRequestTraceIsPropagated(): void {
        $tracerProvider = (new TracerProviderBuilder())
            ->addSpanProcessor(new BatchSpanProcessor($exporter = new InMemorySpanExporter()))
            ->build();

        $server = $this->startServer([new RequestPropagator(new TraceContextPropagator()), new Tracing($tracerProvider)]);

        $request = new Request('/foo');
        $request->setHeader('traceparent', '00-ac0a7f8c2faac49775a616b7c0cc21d8-43b34e9afb52a2db-01');

        try {
            $this->request($server, $request);
        } finally {
            $server->stop();
            $tracerProvider->shutdown();
        }

        $spans = $exporter->collect(true);
        $this->assertCount(1, $spans);

        $this->assertTrue($spans[0]->getParentContext()->isRemote());
        $this->assertSame('ac0a7f8c2faac49775a616b7c0cc21d8', $spans[0]->getContext()->getTraceId());
        $this->assertSame('43b34e9afb52a2db', $spans[0]->getParentContext()->getSpanId());
    }

    public function testSpanIsActiveInRequestHandler(): void {
        $tracerProvider = (new TracerProviderBuilder())
            ->addSpanProcessor(new BatchSpanProcessor($exporter = new InMemorySpanExporter()))
            ->build();

        $server = $this->startServer(new Tracing($tracerProvider), new ClosureRequestHandler(static function() use (&$span): void {
            $span = Span::getCurrent();
        }));

        try {
            $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $tracerProvider->shutdown();
        }

        $spans = $exporter->collect(true);
        $this->assertCount(1, $spans);

        $this->assertInstanceOf(SpanInterface::class, $span);
        $this->assertSame($spans[0]->getContext(), $span->getContext());
    }

    public function testResponseTraceIsPropagated(): void {
        $tracerProvider = (new TracerProviderBuilder())
            ->addSpanProcessor(new BatchSpanProcessor($exporter = new InMemorySpanExporter()))
            ->setIdGenerator(new class implements IdGenerator {

                public function generateSpanIdBinary(): string {
                    return hex2bin('43b34e9afb52a2db');
                }

                public function generateTraceIdBinary(): string {
                    return hex2bin('ac0a7f8c2faac49775a616b7c0cc21d8');
                }

                public function traceFlags(): int {
                    return 0;
                }
            })
            ->build();

        $server = $this->startServer([new Tracing($tracerProvider), new ResponsePropagator(new TraceResponsePropagator())]);

        try {
            $response = $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $tracerProvider->shutdown();
        }

        $spans = $exporter->collect(true);
        $this->assertCount(1, $spans);

        $this->assertTrue($response->hasHeader('traceresponse'));
        $this->assertSame('00-ac0a7f8c2faac49775a616b7c0cc21d8-43b34e9afb52a2db-01', $response->getHeader('traceresponse'));
    }

    public function testErrorHandlerRecordsStatusCode(): void {
        $tracerProvider = (new TracerProviderBuilder())
            ->addSpanProcessor(new BatchSpanProcessor($exporter = new InMemorySpanExporter()))
            ->build();

        $server = $this->startServer(new Tracing($tracerProvider), new ClosureRequestHandler(static fn() => throw new LogicException()));

        try {
            $this->request($server, new Request('/foo'));
        } finally {
            $server->stop();
            $tracerProvider->shutdown();
        }

        $spans = $exporter->collect(true);
        $this->assertCount(1, $spans);

        $attributes = $spans[0]->getAttributes();
        $this->assertSame('500', $attributes->get('error.type'));
        $this->assertSame(500, $attributes->get('http.response.status_code'));
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