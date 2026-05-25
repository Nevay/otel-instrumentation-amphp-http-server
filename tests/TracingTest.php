<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Amp\Http\Client\Request;
use Amp\Http\HttpStatus;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\InternetAddress;
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
use function assert;
use function hex2bin;

final class TracingTest extends AsyncTestCase {

    public function testTracing(): void {
        $tracerProvider = (new TracerProviderBuilder())
            ->addSpanProcessor(new BatchSpanProcessor($exporter = new InMemorySpanExporter()))
            ->build();

        $server = TestUtil::startServer(new Tracing($tracerProvider));
        $address = $server->getServers()[0]->getAddress();
        assert($address instanceof InternetAddress);

        try {
            TestUtil::request($server, new Request('/foo'));
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

        $server = TestUtil::startServer(new Tracing($tracerProvider, routeResolver: new RequestAttributeResolver(UriTemplate::class)), new ClosureRequestHandler(static function (\Amp\Http\Server\Request $request): Response {
            $request->setAttribute(UriTemplate::class, new UriTemplate('/{param}'));

            return new Response();
        }));
        $address = $server->getServers()[0]->getAddress();
        assert($address instanceof InternetAddress);

        try {
            TestUtil::request($server, new Request('/foo'));
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

        $server = TestUtil::startServer([new RequestPropagator(new TraceContextPropagator()), new Tracing($tracerProvider)]);

        $request = new Request('/foo');
        $request->setHeader('traceparent', '00-ac0a7f8c2faac49775a616b7c0cc21d8-43b34e9afb52a2db-01');

        try {
            TestUtil::request($server, $request);
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

        $server = TestUtil::startServer(new Tracing($tracerProvider), new ClosureRequestHandler(static function () use (&$span): Response {
            $span = Span::getCurrent();
            return new Response(HttpStatus::OK);
        }));

        try {
            TestUtil::request($server, new Request('/foo'));
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

        $server = TestUtil::startServer([new Tracing($tracerProvider), new ResponsePropagator(new TraceResponsePropagator())]);

        try {
            $response = TestUtil::request($server, new Request('/foo'));
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

        $server = TestUtil::startServer(new Tracing($tracerProvider), new ClosureRequestHandler(static fn() => throw new LogicException()));

        try {
            TestUtil::request($server, new Request('/foo'));
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

    public function testUsesForwardedHeaderForServerAddress(): void {
        $tracerProvider = (new TracerProviderBuilder())
            ->addSpanProcessor(new BatchSpanProcessor($exporter = new InMemorySpanExporter()))
            ->build();

        $server = TestUtil::startServer(new Tracing($tracerProvider));

        $request = new Request('/foo');
        $request->setHeader('forwarded', 'for=192.0.2.60;proto=https;host=192.1.2.32');

        try {
            TestUtil::request($server, $request);
        } finally {
            $server->stop();
            $tracerProvider->shutdown();
        }

        $spans = $exporter->collect(true);
        $this->assertCount(1, $spans);

        $attributes = $spans[0]->getAttributes();
        $this->assertSame('192.0.2.60', $attributes->get('client.address'));
        $this->assertSame('https', $attributes->get('url.scheme'));
        $this->assertSame('192.1.2.32', $attributes->get('server.address'));
        $this->assertSame(null, $attributes->get('server.port'));
    }
}
