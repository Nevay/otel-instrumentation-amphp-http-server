<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Amp\Http\Server\SocketHttpServer;
use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use PHPUnit\Framework\TestCase;

final class InstrumentationTest extends TestCase {

    public function testInstrumentationHooksSocketServerConstructor(): void {
        $hookManager = $this->createMock(HookManagerInterface::class);
        $configProperties = $this->createMock(ConfigProperties::class);

        $hookManager->expects($this->once())->method('hook')->with(SocketHttpServer::class, '__construct');

        $instrumentation = new AmphpHttpServerInstrumentation();
        $instrumentation->register($hookManager, $configProperties, new Context());
    }

    public function testInstrumentationCanBeDisabled(): void {
        $hookManager = $this->createMock(HookManagerInterface::class);
        $configProperties = $this->createMock(ConfigProperties::class);

        $hookManager->expects($this->never())->method('hook');
        $configProperties->method('get')->with(AmphpHttpServerConfig::class)->willReturn(new AmphpHttpServerConfig(enabled: false));

        $instrumentation = new AmphpHttpServerInstrumentation();
        $instrumentation->register($hookManager, $configProperties, new Context());
    }
}