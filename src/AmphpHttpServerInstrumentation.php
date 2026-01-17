<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\SocketHttpServer;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\Metrics;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\RequestPropagator;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\ResponsePropagator;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\Tracing;
use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\API\Instrumentation\Configuration\General\HttpConfig as GeneralHttpConfig;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpConfig as PhpHttpConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AmphpHttpServerInstrumentation implements Instrumentation {

    public function register(HookManagerInterface $hookManager, ConfigProperties $configuration, Context $context): void {
        $config = $configuration->get(AmphpHttpServerConfig::class) ?? new AmphpHttpServerConfig();
        if (!$config->enabled) {
            return;
        }

        $generalHttpConfig = $configuration->get(GeneralHttpConfig::class)?->config;
        $phpHttpConfig = $configuration->get(PhpHttpConfig::class) ?? new PhpHttpConfig();

        $handlers = [
            new RequestPropagator($context->propagator),
            new ResponsePropagator($context->responsePropagator),

            new Tracing(
                tracerProvider: $context->tracerProvider,
                requestHeaders: $generalHttpConfig['server']['request_captured_headers'] ?? [],
                responseHeaders: $generalHttpConfig['server']['response_captured_headers'] ?? [],
                knownMethods: $phpHttpConfig->knownHttpMethods,
                config: $phpHttpConfig->server,
                sanitizer: $phpHttpConfig->sanitizer,
                routeResolver: $config->routeResolver,
            ),
            new Metrics(
                meterProvider: $context->meterProvider,
                knownMethods: $phpHttpConfig->knownHttpMethods,
                routeResolver: $config->routeResolver,
            ),
        ];

        $hookManager->hook(
            SocketHttpServer::class,
            '__construct',
            preHook: static function(SocketHttpServer $server, array $params) use ($handlers): array {
                if (!($logger = $params[0] ?? new NullLogger()) instanceof LoggerInterface) {
                    return [];
                }
                if (!($driverFactory = $params[5] ?? new DefaultHttpDriverFactory($logger)) instanceof HttpDriverFactory) {
                    return [];
                }

                return [5 => new TelemetryDriverFactory($driverFactory, $handlers)];
            },
        );
    }
}
