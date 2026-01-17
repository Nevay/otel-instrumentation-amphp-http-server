<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\Config;

use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver\RequestAttributeResolver;
use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\Config\ComponentProviderRegistry;
use OpenTelemetry\API\Configuration\Context;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * @implements ComponentProvider<RouteResolver>
 */
final class RouteResolverRequestAttribute implements ComponentProvider {

    /**
     * @param array{
     *     attribute: string,
     * } $properties
     */
    public function createPlugin(array $properties, Context $context): RouteResolver {
        return new RequestAttributeResolver($properties['attribute']);
    }

    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition {
        $node = $builder->arrayNode('request_attribute');
        $node
            ->children()
                ->scalarNode('attribute')->isRequired()->cannotBeEmpty()->end()
            ->end()
        ;

        return $node;
    }
}