<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;

use Amp\Http\HttpMessage;
use Amp\Http\Server\Middleware\Forwarded;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Amp\Socket\UnixAddress;
use Composer\InstalledVersions;
use JetBrains\PhpStorm\ExpectedValues;
use League\Uri\Exceptions\SyntaxError;
use League\Uri\UriString;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver\CompositeRouteResolver;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpConfig;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpServerConfig;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\DefaultSanitizer;
use Throwable;
use function array_combine;
use function sprintf;
use function strtolower;

final class Tracing implements TelemetryHandler {

    private readonly TracerInterface $tracer;
    /** @var array<string, string> */
    private readonly array $requestHeaderAttributes;
    /** @var array<string, string> */
    private readonly array $responseHeaderAttributes;
    /** @var array<string, string> */
    private readonly array $knownRequestMethods;

    public function __construct(
        TracerProviderInterface $tracerProvider,
        array $requestHeaders = [],
        array $responseHeaders = [],
        array $knownMethods = HttpConfig::HTTP_METHODS,
        private readonly HttpServerConfig $config = new HttpServerConfig(),
        private readonly UriSanitizer $sanitizer = new DefaultSanitizer(),
        private readonly RouteResolver $routeResolver = new CompositeRouteResolver(),
    ) {
        $this->tracer = $tracerProvider->getTracer(
            'tbachert/otel-instrumentation-amphp-http-server',
            InstalledVersions::getPrettyVersion('tbachert/otel-instrumentation-amphp-http-server'),
            'https://opentelemetry.io/schemas/1.39.0',
        );
        $this->requestHeaderAttributes = self::prepareHeaderAttributes($requestHeaders, 'request');
        $this->responseHeaderAttributes = self::prepareHeaderAttributes($responseHeaders, 'response');
        $this->knownRequestMethods = array_combine($knownMethods, $knownMethods);
    }

    public function handleRequest(Request $request, ContextInterface $context): ContextInterface {
        $method = $this->knownRequestMethods[$request->getMethod()] ?? null;
        $uri = $this->sanitizer->sanitize($request->getUri());

        $spanBuilder = $this->tracer
            ->spanBuilder($method ?? 'HTTP')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.request.method', $method ?? '_OTHER')
            ->setAttribute('url.path', $uri->getPath())
            ->setAttribute('url.scheme', $uri->getScheme())
            ->setAttribute('network.protocol.version', $request->getProtocolVersion())
            ->setAttribute('user_agent.original', $request->getHeader('user-agent'))
        ;
        if ($uri->getQuery() !== '') {
            $spanBuilder->setAttribute('url.query', $uri->getQuery());
        }
        if ($method === null) {
            $spanBuilder->setAttribute('http.request.method_original', $request->getMethod());
        }

        $this->populateNetworkAddress($spanBuilder, $request);
        $this->populateOriginalAddress($spanBuilder, $request);
        $this->populateHeaderAttributes($spanBuilder, $request, $this->requestHeaderAttributes);

        if ($this->config->captureRequestBodySize && $request->hasHeader('content-length')) {
            $spanBuilder->setAttribute('http.request.body.size', +$request->getHeader('content-length'));
        }

        return $spanBuilder->setParent($context)->startSpan()->storeInContext($context);
    }

    public function handleResponse(Response $response, Request $request, ContextInterface $context): void {
        $span = Span::fromContext($context);

        if (($route = $this->routeResolver->resolveRoute($request)) !== null) {
            $span->setAttribute('http.route', $route);
            $span->updateName(sprintf('%s %s',
                $this->knownRequestMethods[$request->getMethod()] ?? 'HTTP',
                $route,
            ));
        }

        $this->populateHeaderAttributes($span, $response, $this->responseHeaderAttributes);
        $span->setAttribute('http.response.status_code', $response->getStatus());
        if ($response->getStatus() >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->setAttribute('error.type', (string) $response->getStatus());
        }

        if ($this->config->captureResponseBodySize && $response->hasHeader('content-length')) {
            $span->setAttribute('http.response.body.size', +$response->getHeader('content-length'));
        }

        $response->onDispose($span->end(...));
    }

    public function handleError(Throwable $e, Request $request, ContextInterface $context): void {
        $span = Span::fromContext($context);

        if (($route = $this->routeResolver->resolveRoute($request)) !== null) {
            $span->setAttribute('http.route', $route);
            $span->updateName(sprintf('%s %s',
                $this->knownRequestMethods[$request->getMethod()] ?? 'HTTP',
                $route,
            ));
        }

        $span->recordException($e, ['exception.escaped' => true]);
        $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
        $span->setAttribute('error.type', $e::class);

        $span->end();
    }

    private function populateNetworkAddress(SpanBuilderInterface $spanBuilder, Request $request): void {
        $localAddress = $request->getClient()->getLocalAddress();
        if ($localAddress instanceof InternetAddress) {
            $spanBuilder->setAttribute('server.address', $localAddress->getAddress());
            $spanBuilder->setAttribute('server.port', $localAddress->getPort());
            if ($this->config->captureNetworkLocalAddress) {
                $spanBuilder->setAttribute('network.local.address', $localAddress->getAddress());
            }
            if ($this->config->captureNetworkLocalPort) {
                $spanBuilder->setAttribute('network.local.port', $localAddress->getPort());
            }
            if ($this->config->captureNetworkTransport) {
                $spanBuilder->setAttribute('network.transport', 'tcp');
            }
        }
        if ($localAddress instanceof UnixAddress) {
            if ($this->config->captureNetworkLocalAddress) {
                $spanBuilder->setAttribute('network.local.address', $localAddress->toString());
            }
            if ($this->config->captureNetworkTransport) {
                $spanBuilder->setAttribute('network.transport', 'unix');
            }
        }
        $remoteAddress = $request->getClient()->getRemoteAddress();
        if ($remoteAddress instanceof InternetAddress) {
            $spanBuilder->setAttribute('network.peer.address', $remoteAddress->getAddress());
            $spanBuilder->setAttribute('network.peer.port', $remoteAddress->getPort());
            $spanBuilder->setAttribute('client.address', $remoteAddress->getAddress());
            if ($this->config->captureClientPort) {
                $spanBuilder->setAttribute('client.port', $remoteAddress->getPort());
            }
        }
        if ($remoteAddress instanceof UnixAddress) {
            $spanBuilder->setAttribute('network.peer.address', $remoteAddress->toString());
        }
    }

    private function populateOriginalAddress(SpanBuilderInterface $spanBuilder, Request $request): void {
        $host = null;
        if ($request->hasAttribute(Forwarded::class)) {
            /** @var Forwarded $forwarded */
            $forwarded = $request->getAttribute(Forwarded::class);
            $spanBuilder->setAttribute('client.address', $forwarded->getFor()->getAddress());
            if ($this->config->captureClientPort) {
                $spanBuilder->setAttribute('client.port', $forwarded->getFor()->getPort());
            }

            $host = $forwarded->getField('host');
        }
        $host ??= $request->getHeader(':authority');
        $host ??= $request->getHeader('host');
        try {
            $components = UriString::parseAuthority($host);
            $spanBuilder->setAttribute('server.address', $components['host']);
            $spanBuilder->setAttribute('server.port', $components['port']);
        } catch (SyntaxError) {}
    }

    private static function populateHeaderAttributes(SpanBuilderInterface|SpanInterface $span, HttpMessage $message, array $headerAttributes): void {
        foreach ($headerAttributes as $header => $attribute) {
            if ($value = $message->getHeaderArray($header)) {
                $span->setAttribute($attribute, $value);
            }
        }
    }

    private static function prepareHeaderAttributes(array $headers, #[ExpectedValues(['request', 'response'])] string $type): array {
        $prepared = [];
        foreach ($headers as $header) {
            $header = strtolower($header);
            $prepared[$header] = sprintf('http.%s.header.%s', $type, $header);
        }

        return $prepared;
    }
}
