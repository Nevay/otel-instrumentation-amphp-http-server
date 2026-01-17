<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver;

use Amp\Http\Server\Request;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver;

final class CompositeRouteResolver implements RouteResolver {

    private readonly array $routeResolvers;

    public function __construct(RouteResolver ...$routeResolvers) {
        $this->routeResolvers = $routeResolvers;
    }

    public function resolveRoute(Request $request): ?string {
        foreach ($this->routeResolvers as $routeResolver) {
            if (($route = $routeResolver->resolveRoute($request)) !== null) {
                return $route;
            }
        }

        return null;
    }
}
