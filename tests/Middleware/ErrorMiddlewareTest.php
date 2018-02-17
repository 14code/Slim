<?php
namespace Slim\Tests\Middleware;

use Guzzle\Common\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Handlers\ErrorHandler;
use Slim\Http\Body;
use Slim\Http\Environment;
use Slim\Http\Headers;
use Slim\Http\Request;
use Slim\Http\Uri;
use Slim\Middleware\ErrorMiddleware;
use Slim\Middleware\RoutingMiddleware;
use Slim\Tests\Mocks\MockCustomException;

/**
 * Class ErrorMiddlewareTest
 * @package Slim\Tests\Middleware
 */
class ErrorMiddlewareTest extends TestCase
{
    public function testSetErrorHandler()
    {
        $app = new App();

        $mw = new RoutingMiddleware($app->getRouter());
        $app->add($mw);

        $exception = HttpNotFoundException::class;
        $handler = function ($req, $res) {
            return $res->withJson('Oops..');
        };
        $mw2 = new ErrorMiddleware(false, false);
        $mw2->setErrorHandler($exception, $handler);
        $app->add($mw2);

        $request = $this->requestFactory('/foo/baz/');
        $app->setRequest($request);
        $app->run();

        $response = $app->getResponse();
        $expectedOutput = json_encode('Oops..');
        $this->assertEquals($response->getBody(), $expectedOutput);
        $this->expectOutputString($expectedOutput);
    }

    public function testSetDefaultErrorHandler()
    {
        $app = new App();

        $mw = new RoutingMiddleware($app->getRouter());
        $app->add($mw);

        $handler = function ($req, $res) {
            return $res->withJson('Oops..');
        };
        $mw2 = new ErrorMiddleware(false, false);
        $mw2->setDefaultErrorHandler($handler);
        $app->add($mw2);

        $request = $this->requestFactory('/foo/baz/');
        $app->setRequest($request);
        $app->run();

        $response = $app->getResponse();
        $expectedOutput = json_encode('Oops..');
        $this->assertEquals($response->getBody(), $expectedOutput);
        $this->expectOutputString($expectedOutput);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testSetErrorHandlerThrowsException()
    {
        $mw = new ErrorMiddleware(false, false);
        $mw->setErrorHandler(RuntimeException::class, 'Uncallable');
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testSetDefaultErrorHandlerThrowsException()
    {
        $mw = new ErrorMiddleware(false, false);
        $mw->setDefaultErrorHandler('Uncallable');
    }

    public function testGetErrorHandlerWillReturnDefaultErrorHandlerForUnhandledExceptions()
    {
        $middleware = new ErrorMiddleware(false, false);
        $exception = MockCustomException::class;
        $handler = $middleware->getErrorHandler($exception);
        $this->assertInstanceOf(ErrorHandler::class, $handler);
    }

    /**
     * @requires PHP 7.0
     */
    public function testErrorHandlerHandlesThrowables()
    {
        $app = new App();

        $mw2 = function ($req, $res) {
            throw new \Error('Oops..');
        };
        $app->add($mw2);

        $handler = function ($req, $res, $exception) {
            return $res->withJson($exception->getMessage());
        };
        $mw = new ErrorMiddleware(false, false);
        $mw->setDefaultErrorHandler($handler);
        $app->add($mw);

        $app->get('/foo', function ($req, $res) {
            return $res->withJson('...');
        });

        $request = $this->requestFactory('/foo');
        $app->setRequest($request);
        $app->run();

        $response = $app->getResponse();
        $expectedOutput = json_encode('Oops..');
        $this->assertEquals($response->getBody(), $expectedOutput);
        $this->expectOutputString($expectedOutput);
    }

    /**
     * helper to create a request object
     * @return Request
     */
    private function requestFactory($requestUri, $method = 'GET', $data = [])
    {
        $defaults = [
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_URI' => $requestUri,
            'REQUEST_METHOD' => $method,
        ];

        $data = array_merge($defaults, $data);

        $env = Environment::mock($data);
        $uri = Uri::createFromGlobals($env);
        $headers = Headers::createFromGlobals($env);
        $cookies = [];
        $serverParams = $env;
        $body = new Body(fopen('php://temp', 'r+'));
        $request = new Request($method, $uri, $headers, $cookies, $serverParams, $body);

        return $request;
    }
}
