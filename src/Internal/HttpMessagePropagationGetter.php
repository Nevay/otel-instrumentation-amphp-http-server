<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\Internal;

use Amp\Http\HttpMessage;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use function array_keys;
use function assert;
use function implode;

/**
 * @internal
 */
final class HttpMessagePropagationGetter implements PropagationGetterInterface {

    public function keys($carrier): array {
        assert($carrier instanceof HttpMessage);

        return array_keys($carrier->getHeaders());
    }

    public function get($carrier, string $key): ?string {
        assert($carrier instanceof HttpMessage);

        if (!$header = $carrier->getHeaderArray($key)) {
            return null;
        }

        return implode(',', $header);
    }
}
