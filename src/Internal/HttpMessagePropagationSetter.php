<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\Internal;

use Amp\Http\Server\Response;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use function assert;

/**
 * @internal
 */
final class HttpMessagePropagationSetter implements PropagationSetterInterface {

    public function set(&$carrier, string $key, string $value): void {
        assert($carrier instanceof Response);

        $carrier->setHeader($key, $value);
    }
}
