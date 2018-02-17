<?php
/**
 * Slim Framework (https://slimframework.com)
 *
 * @link      https://github.com/slimphp/Slim
 * @copyright Copyright (c) 2011-2017 Josh Lockhart
 * @license   https://github.com/slimphp/Slim/blob/3.x/LICENSE.md (MIT License)
 */
namespace Slim\Handlers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Error\Renderers\PlainTextErrorRenderer;
use Slim\Error\Renderers\HtmlErrorRenderer;
use Slim\Error\Renderers\XmlErrorRenderer;
use Slim\Error\Renderers\JsonErrorRenderer;
use Slim\Exception\HttpException;
use Slim\Exception\HttpNotAllowedException;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Interfaces\ErrorRendererInterface;
use Exception;
use RuntimeException;
use Throwable;

/**
 * Abstract Slim application error handler
 *
 * It outputs the error message and diagnostic information in one of the following formats:
 * JSON, XML, Plain Text or HTML based on the Accept header.
 */
abstract class AbstractErrorHandler implements ErrorHandlerInterface
{
    /**
     * Known handled content types
     *
     * @var array
     */
    protected $knownContentTypes = [
        'application/json',
        'application/xml',
        'text/xml',
        'text/html',
        'text/plain'
    ];
    /**
     * @var bool
     */
    protected $displayErrorDetails;
    /**
     * @var bool
     */
    protected $logErrors;
    /**
     * @var string
     */
    protected $contentType;
    /**
     * @var string
     */
    protected $method;
    /**
     * @var ServerRequestInterface
     */
    protected $request;
    /**
     * @var ResponseInterface
     */
    protected $response;
    /**
     * @var Exception
     */
    protected $exception;
    /**
     * @var ErrorRendererInterface
     */
    protected $renderer = null;
    /**
     * @var int
     */
    protected $statusCode;

    /**
     * AbstractErrorHandler constructor.
     * @param bool $logErrors
     */
    public function __construct($logErrors)
    {
        $this->logErrors = $logErrors;
    }

    /**
     * Invoke error handler
     *
     * @param ServerRequestInterface $request   The most recent Request object
     * @param ResponseInterface      $response  The most recent Response object
     * @param Exception|Throwable    $exception The caught Exception object
     * @param bool $displayErrorDetails Whether or not to display the error details
     *
     * @return ResponseInterface
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        $exception,
        $displayErrorDetails
    ) {
        $this->displayErrorDetails = $displayErrorDetails;
        $this->request = $request;
        $this->response = $response;
        $this->exception = $exception;
        $this->method = $request->getMethod();
        $this->statusCode = $this->determineStatusCode();
        $this->contentType = $this->determineContentType($request);
        $this->renderer = $this->determineRenderer();

        if ($this->logErrors) {
            $this->writeToErrorLog();
        }

        return $this->formatResponse();
    }

    /**
     * Determine which content type we know about is wanted using Accept header
     *
     * Note: This method is a bare-bones implementation designed specifically for
     * Slim's error handling requirements. Consider a fully-feature solution such
     * as willdurand/negotiation for any other situation.
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    protected function determineContentType(ServerRequestInterface $request)
    {
        $acceptHeader = $request->getHeaderLine('Accept');
        $selectedContentTypes = array_intersect(explode(',', $acceptHeader), $this->knownContentTypes);
        $count = count($selectedContentTypes);

        if ($count) {
            $current = current($selectedContentTypes);

            /**
             * Ensure other supported content types take precedence over text/plain
             * when multiple content types are provided via Accept header.
             */
            if ($current === 'text/plain' && $count > 1) {
                return next($selectedContentTypes);
            }

            return $current;
        }

        if (preg_match('/\+(json|xml)/', $acceptHeader, $matches)) {
            $mediaType = 'application/' . $matches[1];
            if (in_array($mediaType, $this->knownContentTypes)) {
                return $mediaType;
            }
        }

        return 'text/html';
    }

    /**
     * Determine which renderer to use based on content type
     * Overloaded $renderer from calling class takes precedence over all
     *
     * @return ErrorRendererInterface
     *
     * @throws RuntimeException
     */
    protected function determineRenderer()
    {
        $renderer = $this->renderer;

        if ($renderer !== null
            && (
                !class_exists($renderer)
                || !in_array('Slim\Interfaces\ErrorRendererInterface', class_implements($renderer))
            )
        ) {
            throw new RuntimeException(sprintf(
                'Non compliant error renderer provided (%s). ' .
                'Renderer must implement the ErrorRendererInterface',
                $renderer
            ));
        }

        if ($renderer === null) {
            switch ($this->contentType) {
                case 'application/json':
                    $renderer = JsonErrorRenderer::class;
                    break;

                case 'text/xml':
                case 'application/xml':
                    $renderer = XmlErrorRenderer::class;
                    break;

                case 'text/plain':
                    $renderer = PlainTextErrorRenderer::class;
                    break;

                default:
                case 'text/html':
                    $renderer = HtmlErrorRenderer::class;
                    break;
            }
        }

        return new $renderer();
    }

    /**
     * @return int
     */
    protected function determineStatusCode()
    {
        if ($this->method === 'OPTIONS') {
            return 200;
        }

        if ($this->exception instanceof HttpException) {
            return $this->exception->getCode();
        }

        return 500;
    }

    /**
     * @return ResponseInterface
     */
    protected function formatResponse()
    {
        $response = $this->response;
        $body = $this->renderer->renderWithBody($this->exception, $this->displayErrorDetails);

        if ($this->exception instanceof HttpNotAllowedException) {
            $response = $response->withHeader('Allow', $this->exception->getAllowedMethods());
        }

        return $response
            ->withStatus($this->statusCode)
            ->withHeader('Content-type', $this->contentType)
            ->withBody($body);
    }

    /**
     * Write to the error log if displayErrorDetails is false
     *
     * @return void
     */
    protected function writeToErrorLog()
    {
        $renderer = new PlainTextErrorRenderer();
        $error = $renderer->render($this->exception, $this->displayErrorDetails);
        $error .= "\nView in rendered output by enabling the \"displayErrorDetails\" setting.\n";
        $this->logError($error);
    }

    /**
     * Wraps the error_log function so that this can be easily tested
     *
     * @param string $error
     */
    protected function logError($error)
    {
        error_log($error);
    }
}
