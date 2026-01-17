<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver;

use Amp\Http\Server\Request;
use Nevay\OTelInstrumentation\AmphpHttpServer\RouteResolver;

final class RequestAttributeResolver implements RouteResolver {

    public function __construct(
        private readonly string $attribute,
    ) {}

    public function resolveRoute(Request $request): ?string {
        if (!$request->hasAttribute($this->attribute)) {
            return null;
        }

        return (string) $request->getAttribute($this->attribute);
    }
}