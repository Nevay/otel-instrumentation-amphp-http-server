<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\Config;

use Nevay\OTelInstrumentation\AmphpHttpServer\AmphpHttpServerConfig;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver\CompositeRouteResolver;
use OpenTelemetry\API\Configuration\Config\ComponentPlugin;
use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\Config\ComponentProviderRegistry;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * @implements ComponentProvider<InstrumentationConfiguration>
 */
final class InstrumentationConfigurationAmphpHttpServerConfig implements ComponentProvider {

    /**
     * @param array{
     *     enabled: bool,
     *     route_resolvers: list<ComponentPlugin<RouteResolver>>,
     * } $properties
     */
    public function createPlugin(array $properties, Context $context): InstrumentationConfiguration {
        $routeResolvers = [];
        foreach ($properties['route_resolvers'] as $routeResolver) {
            $routeResolvers[] = $routeResolver->create($context);
        }

        return new AmphpHttpServerConfig(
            enabled: $properties['enabled'],
            routeResolver: new CompositeRouteResolver(...$routeResolvers),
        );
    }

    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition {
        $node = $builder->arrayNode('amphp_http_server');
        $node
            ->canBeDisabled()
            ->children()
                ->append($registry->componentList('route_resolvers', RouteResolver::class))
            ->end()
        ;

        return $node;
    }
}
