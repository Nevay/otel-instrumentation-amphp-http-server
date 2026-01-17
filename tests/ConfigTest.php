<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use League\Uri\UriTemplate;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver\CompositeRouteResolver;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver\RequestAttributeResolver;
use Nevay\OTelSDK\Configuration\Config;
use Nevay\OTelSDK\Configuration\Env;
use Nevay\OTelSDK\Configuration\Env\ArrayEnvSource;
use Nevay\OTelSDK\Configuration\Env\EnvSourceReader;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase {

    public function testKitchenSink(): void {
        $result = Config::loadFile(__DIR__ . '/snippets/kitchen-sink.yaml');

        $config = $result->configProperties->get(AmphpHttpServerConfig::class);

        $this->assertInstanceOf(AmphpHttpServerConfig::class, $config);
        $this->assertTrue($config->enabled);
        $this->assertEquals(
            new CompositeRouteResolver(
                new RequestAttributeResolver('http.route'),
                new RequestAttributeResolver(UriTemplate::class),
            ),
            $config->routeResolver,
        );
    }

    public function testDisabled(): void {
        $result = Config::loadFile(__DIR__ . '/snippets/disabled.yaml');

        $config = $result->configProperties->get(AmphpHttpServerConfig::class);

        $this->assertInstanceOf(AmphpHttpServerConfig::class, $config);
        $this->assertFalse($config->enabled);
    }

    public function testEnv(): void {
        $result = Env::load(new EnvSourceReader([new ArrayEnvSource([])]));

        $config = $result->configProperties->get(AmphpHttpServerConfig::class);

        $this->assertInstanceOf(AmphpHttpServerConfig::class, $config);
        $this->assertTrue($config->enabled);
    }

    public function testEnvDisabled(): void {
        $result = Env::load(new EnvSourceReader([new ArrayEnvSource(['OTEL_PHP_DISABLED_INSTRUMENTATIONS' => 'amphp-http-server'])]));

        $config = $result->configProperties->get(AmphpHttpServerConfig::class);

        $this->assertInstanceOf(AmphpHttpServerConfig::class, $config);
        $this->assertFalse($config->enabled);
    }

    public function testEnvDisabledAll(): void {
        $result = Env::load(new EnvSourceReader([new ArrayEnvSource(['OTEL_PHP_DISABLED_INSTRUMENTATIONS' => 'all'])]));

        $config = $result->configProperties->get(AmphpHttpServerConfig::class);

        $this->assertInstanceOf(AmphpHttpServerConfig::class, $config);
        $this->assertFalse($config->enabled);
    }
}