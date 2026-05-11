<?php
declare(strict_types=1);

namespace Tylio\Middleware;

use Tylio\Services\Auth;
use Tylio\Services\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Verifies the CSRF token on non-safe methods (POST/PUT/PATCH/DELETE).
 * Expects AuthMiddleware to have already run, and reads the expected token
 * from the session.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private Csrf $csrf, private Auth $auth) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $expected = $this->auth->csrf();
        $received = $request->getHeaderLine('X-CSRF-Token');
        if ($received === '') {
            $body = $request->getParsedBody();
            if (is_array($body) && isset($body['_csrf'])) {
                $received = (string)$body['_csrf'];
            }
        }

        if (!$this->csrf->isValid($expected, $received)) {
            $response = (new ResponseFactory())->createResponse(419);
            $response->getBody()->write(json_encode(['error' => 'csrf_mismatch']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
