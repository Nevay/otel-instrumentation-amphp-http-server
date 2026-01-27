<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use function class_exists;

if (!class_exists(ServiceLoader::class)) {
    return;
}

ServiceLoader::register(Instrumentation::class, AmphpHttpServerInstrumentation::class);

ServiceLoader::register(ComponentProvider::class, Config\InstrumentationConfigurationAmphpHttpServerConfig::class);
ServiceLoader::register(ComponentProvider::class, Config\RouteResolverRequestAttribute::class);

ServiceLoader::register(EnvComponentLoader::class, ConfigEnv\InstrumentationConfigurationAmphpHttpServerConfig::class);
