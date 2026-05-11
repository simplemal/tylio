<?php
declare(strict_types=1);

namespace Tylio\Middleware;

use Tylio\Services\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private Auth $auth) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->auth->loadFromRequest($request);
        if (!$this->auth->check()) {
            return self::unauthenticated();
        }
        $request = $request
            ->withAttribute('user', $this->auth->user())
            ->withAttribute('session', $this->auth->session());
        return $handler->handle($request);
    }

    private static function unauthenticated(): ResponseInterface
    {
        $response = (new ResponseFactory())->createResponse(401);
        $response->getBody()->write(json_encode(['error' => 'unauthenticated']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
