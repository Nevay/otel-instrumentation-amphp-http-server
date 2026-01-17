<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Amp\Http\Server\Request;

interface RouteResolver {
    public function resolveRoute(Request $request): ?string;
}
