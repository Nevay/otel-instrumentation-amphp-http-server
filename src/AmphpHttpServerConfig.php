<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver\CompositeRouteResolver;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;

final class AmphpHttpServerConfig implements InstrumentationConfiguration {

    public function __construct(
        public readonly bool $enabled = true,
        public readonly RouteResolver $routeResolver = new CompositeRouteResolver(),
    ) {}
}
