# OpenTelemetry [amphp/http-server] instrumentation

## Installation

```shell
composer require tbachert/otel-instrumentation-amphp-http-server
```

## Usage

### Automatic instrumentation

This instrumentation is enabled by default.

#### Disable via file-based configuration

```yaml
instrumentations/development:
  php:
    amphp_http_server: false
```

#### Disable via env-based configuration

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=amphp-http-server
```

### Manual instrumentation

```php
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\SocketHttpServer;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryDriverFactory;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\Metrics;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\RequestPropagator;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\ResponsePropagator;
use Nevay\OTelInstrumentation\AmphpHttpServer\TelemetryHandler\Tracing;

$httpServer = new SocketHttpServer(
    ...,
    httpDriverFactory: new TelemetryDriverFactory(
        new DefaultHttpDriverFactory($logger),
        [
            new RequestPropagator($propagator),
            new ResponsePropagator($responsePropagator),
            new Tracing($tracerProvider),
            new Metrics($meterProvider),
        ],
    ),
)
```

[amphp/http-server]: https://github.com/amphp/http-server
