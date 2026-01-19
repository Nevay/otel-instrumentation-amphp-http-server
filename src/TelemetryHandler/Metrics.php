<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;

use Amp\Http\Server\Middleware\Forwarded;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Composer\InstalledVersions;
use League\Uri\Exceptions\SyntaxError;
use League\Uri\UriString;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver\CompositeRouteResolver;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpConfig;
use Throwable;
use function array_combine;
use function hrtime;

final class Metrics implements TelemetryHandler {

    private readonly HistogramInterface $requestDuration;
    private readonly UpDownCounterInterface $activeRequests;
    private readonly HistogramInterface $requestBodySize;
    private readonly HistogramInterface $responseBodySize;
    /** @var array<string, string> */
    private readonly array $knownMethods;

    public function __construct(
        MeterProviderInterface $meterProvider,
        array $knownMethods = HttpConfig::HTTP_METHODS,
        private readonly RouteResolver $routeResolver = new CompositeRouteResolver(),
    ) {
        $meter = $meterProvider->getMeter(
            'tbachert/otel-instrumentation-amphp-http-server',
            InstalledVersions::getPrettyVersion('tbachert/otel-instrumentation-amphp-http-server'),
            'https://opentelemetry.io/schemas/1.39.0',
        );
        $this->requestDuration = $meter->createHistogram(
            'http.server.request.duration',
            's',
            'Duration of HTTP server requests.',
            [
                'Attributes' => ['http.request.method', 'url.scheme', 'error.type', 'http.response.status_code', 'http.route', 'network.protocol.name', 'network.protocol.version'],
                'ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10],
            ],
        );
        $this->activeRequests = $meter->createUpDownCounter(
            'http.server.active_requests',
            '{requests}',
            'Number of active HTTP server requests.',
            [
                'Attributes' => ['http.request.method', 'url.scheme'],
            ],
        );
        $this->requestBodySize = $meter->createHistogram(
            'http.server.request.body.size',
            'By',
            'Size of HTTP server request bodies.',
            [
                'Attributes' => ['http.request.method', 'url.scheme', 'error.type', 'http.response.status_code', 'http.route', 'network.protocol.name', 'network.protocol.version'],
            ],
        );
        $this->responseBodySize = $meter->createHistogram(
            'http.server.response.body.size',
            'By',
            'Size of HTTP server response bodies.',
            [
                'Attributes' => ['http.request.method', 'url.scheme', 'error.type', 'http.response.status_code', 'http.route', 'network.protocol.name', 'network.protocol.version'],
            ],
        );
        $this->knownMethods = array_combine($knownMethods, $knownMethods);
    }

    public function handleRequest(Request $request, ContextInterface $context): ContextInterface {
        $request->setAttribute(Metrics::class, hrtime(true));

        $attributes = $this->basicRequestAttributes($request);

        $this->activeRequests->add(1, $attributes, $context);

        return $context;
    }

    public function handleResponse(Response $response, Request $request, ContextInterface $context): void {
        $attributes = $this->basicRequestAttributes($request);

        $activeRequests = $this->activeRequests;
        $response->onDispose(static fn() => $activeRequests->add(-1, $attributes, $context));

        if (($route = $this->routeResolver->resolveRoute($request)) !== null) {
            $attributes['http.route'] = $route;
        }

        $attributes['network.protocol.version'] = $request->getProtocolVersion();
        $attributes['http.response.status_code'] = $response->getStatus();
        if ($response->isServerError()) {
            $attributes['error.type'] = (string) $response->getStatus();
        }

        $start = $request->getAttribute(Metrics::class);
        $requestDuration = $this->requestDuration;
        $response->onDispose(static fn() => $requestDuration->record((hrtime(true) - $start) / 1e9, $attributes, $context));

        if ($request->hasHeader('content-length')) {
            $this->requestBodySize->record(+$request->getHeader('content-length'), $attributes, $context);
        }
        if ($response->hasHeader('content-length')) {
            $this->responseBodySize->record(+$response->getHeader('content-length'), $attributes, $context);
        }
    }

    public function handleError(Throwable $e, Request $request, ContextInterface $context): void {
        $attributes = $this->basicRequestAttributes($request);

        $this->activeRequests->add(-1, $attributes);

        $attributes['network.protocol.version'] = $request->getProtocolVersion();
        $attributes['error.type'] = $e::class;

        $start = $request->getAttribute(Metrics::class);
        $end = hrtime(true);
        $this->requestDuration->record(($end - $start) / 1e9, $attributes, $context);

        if ($request->hasHeader('content-length')) {
            $this->requestBodySize->record(+$request->getHeader('content-length'), $attributes, $context);
        }
    }

    /**
     * @param Request $request
     * @return array{ 'http.request.method': string, 'server.address': ?string, 'server.port': ?string, 'url.scheme': string, }
     */
    private function basicRequestAttributes(Request $request): array {
        $requestMethod = $this->knownMethods[$request->getMethod()] ?? '_OTHER';
        $serverAddress = null;
        $serverPort = null;

        $localAddress = $request->getClient()->getLocalAddress();
        if ($localAddress instanceof InternetAddress) {
            $serverAddress = $localAddress->getAddress();
            $serverPort = $localAddress->getPort();
        }

        $host = null;
        if ($request->hasAttribute(Forwarded::class)) {
            /** @var Forwarded $forwarded */
            $forwarded = $request->getAttribute(Forwarded::class);
            $host = $forwarded->getField('host');
        }
        $host ??= $request->getHeader(':authority');
        $host ??= $request->getHeader('Host');
        try {
            $components = UriString::parseAuthority($host);
            $components['port'] ??= match ($request->getUri()->getScheme()) {
                'https' => 443,
                'http' => 80,
                default => null,
            };

            $serverAddress = $components['host'];
            $serverPort = $components['port'];
        } catch (SyntaxError) {}

        return [
            'http.request.method' => $requestMethod,
            'server.address' => $serverAddress,
            'server.port' => $serverPort,
            'url.scheme' => $request->getUri()->getScheme(),
        ];
    }
}
