<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\ConfigEnv;

use Nevay\OTelInstrumentation\AmphpHttpServer\AmphpHttpServerConfig;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoaderRegistry;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvResolver;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use function in_array;

/**
 * @implements EnvComponentLoader<InstrumentationConfiguration>
 */
final class InstrumentationConfigurationAmphpHttpServerConfig implements EnvComponentLoader {

    public function load(EnvResolver $env, EnvComponentLoaderRegistry $registry, Context $context): InstrumentationConfiguration {
        $disabledInstrumentations = $env->list('OTEL_PHP_DISABLED_INSTRUMENTATIONS');

        return new AmphpHttpServerConfig(
            enabled: !$disabledInstrumentations || $disabledInstrumentations !== ['all'] && !in_array('amphp-http-server', $disabledInstrumentations, true),
        );
    }

    public function name(): string {
        return AmphpHttpServerConfig::class;
    }
}
