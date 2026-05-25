<?php /** @noinspection HttpUrlsUsage */ declare(strict_types=1);
namespace Nevay\OTelInstrumentation\AmphpHttpServer;

use Amp\Cancellation;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Middleware\ForwardedHeaderType;
use Amp\Http\Server\Middleware\ForwardedMiddleware;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\ResourceServerSocketFactory;
use League\Uri\Http;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function is_array;

final class TestUtil {

    public static function startServer(TelemetryHandler|array $handler, ?RequestHandler $requestHandler = null, ?ErrorHandler $errorHandler = null, LoggerInterface $logger = new NullLogger()): HttpServer {
        $requestHandler ??= new ClosureRequestHandler(static fn(): Response => new Response());
        $errorHandler ??= new DefaultErrorHandler();

        if (!is_array($handler)) {
            $handler = [$handler];
        }

        $server = new SocketHttpServer(
            $logger,
            new ResourceServerSocketFactory(),
            new SocketClientFactory($logger),
            middleware: [
                new ForwardedMiddleware(headerType: ForwardedHeaderType::Forwarded, trustedProxies: ['127.0.0.1/32'])
            ],
            httpDriverFactory: new TelemetryDriverFactory(new DefaultHttpDriverFactory($logger), $handler),
        );
        $server->expose('127.0.0.1:0');
        $server->start($requestHandler, $errorHandler);

        return $server;
    }

    public static function request(HttpServer $server, Request $request, ?Cancellation $cancellation = null): \Amp\Http\Client\Response {
        $address = $server->getServers()[0]->getAddress();
        $request->setUri(Http::parse($request->getUri(), "http://$address"));

        return HttpClientBuilder::buildDefault()->request($request, $cancellation);
    }
}
