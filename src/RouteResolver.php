<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Amp\Http\Server\Request;

interface RouteResolver {

    /**
     * Returns the matched route template for the request.
     *
     * This MUST be low-cardinality and include all static path segments, with dynamic path segments represented with
     * placeholders.
     *
     * A static path segment is a part of the route template with a fixed, low-cardinality value. This includes literal
     * strings like `/users/` and placeholders that are constrained to a finite, predefined set of values, e.g.
     * `{controller}` or `{action}`.
     *
     * A dynamic path segment is a placeholder for a value that can have high cardinality and is not constrained to a
     * predefined list like static path segments.
     *
     * @return string|null the matched route template for the request
     * @see https://opentelemetry.io/docs/specs/semconv/registry/attributes/http/#http-route
     */
    public function resolveRoute(Request $request): ?string;
}
