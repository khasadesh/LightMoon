<?php

namespace LightMoon;

use Pimple\Container;
use FastRoute\Dispatcher;
use LightMoon\Http\Request;
use LightMoon\Http\Response;
use FastRoute\RouteCollector;
use InvalidArgumentException;
use FastRoute\RouteParser\Std;
use Psr\Http\Message\ResponseInterface;
use FastRoute\Dispatcher\GroupCountBased;

class App
{

    /**
     * @var Container
     */
    private $container;

    /**
     * @var \swoole_http_server
     */
    private $httpServer;

    /**
     * @var string port
     */
    private $port;

    /**
     * @var string host
     */
    private $host;

    /**
     * @var array events
     */
    private $events = [];

    /**
     * @var string
     */
    private $routePrefix = '';

    /**
     * App constructor.
     * @param array $setting
     */
    public function __construct(array $setting)
    {
        $this->container = new Container();
        $this->container['setting'] = $setting;

        $this->port = $this->container['setting']['listen'];
        $this->host = $this->container['setting']['host'];

        $this->container['router.collector'] = function () {
            /** @var RouteCollector $routeCollector */
            return new RouteCollector(
                new Std(),
                new \FastRoute\DataGenerator\GroupCountBased()
            );
        };

        $this->container['router.dispatch'] = function ($c) {
            return new GroupCountBased($c['router.collector']->getData());
        };

        $this->httpServer = new \swoole_http_server($this->host, $this->port);
        $this->httpServer->set($this->container['setting']['server']);
        $this->events['request'] = [$this, 'onRequest'];
        $this->events['start'] = [$this, 'onStart'];
    }

    /**
     * @param \swoole_http_server $server
     */
    public function onStart(\swoole_http_server $server)
    {
        echo "Server start at {$server->host}:{$server->port}....\n";
    }

    /**
     * @param $request
     * @param $response
     * @return mixed
     */
    public function onRequest($request, $response)
    {
        $httpMethod = $request->server['request_method'];
        $uri = $request->server['request_uri'];

        $routeInfo = $this->container['router.dispatch']->dispatch($httpMethod, $uri);

        if ($routeInfo[0] === Dispatcher::FOUND) {
            $handler = $routeInfo[1]['uses'];
            $middleware = $routeInfo[1]['middleware'];

            $psr7Request = Request::fromSwoole($request);
            $psr7Request = $psr7Request->withAttributes($routeInfo[2]);
            $psr7Response = new Response();

            if ($handler instanceof Handler) {
                if (is_callable($middleware)) {
                    $psr7Response = call_user_func_array($middleware, [$psr7Request, $psr7Response, $handler]);
                } else {
                    $psr7Response = call_user_func_array($handler, [$psr7Request, $psr7Response]);
                }
                $response = $this->parsePsr7Response($psr7Response, $response);
                $response->end();
            } else {
                throw new InvalidArgumentException('handler is invalid');
            }
        } elseif ($routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            $response->status(405);
            $request->end('Method Not Allowed');
        } elseif ($routeInfo[0] === Dispatcher::NOT_FOUND) {
            $response->status(404);
            $response->end('Not Found');
        }
    }

    /**
     * @param ResponseInterface $psr7Response
     * @param $response
     * @return mixed
     */
    private function parsePsr7Response(ResponseInterface $psr7Response, $response)
    {
        // set header and cookie before write content,
        // or header and cookie will be empty
        foreach ($psr7Response->getHeaders() as $key => $header) {
            $response->header($key, implode(',', $header));
        }

        $cookies = $psr7Response->getCookies();

        if (!empty($cookie)) {
            foreach ($cookies as $cookie) {
                $response->cookie($cookie['key'], $cookie['value'], $cookie['expire'],
                    $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
            }
        }

        $response->status($psr7Response->getStatusCode());

        $psr7Response->getBody()->rewind();
        $response->write($psr7Response->getBody()->getContents());

        return $response;
    }

    /**
     * @param $uri
     * @param $callback
     * @param array $middleware
     */
    public function get($uri, $callback, $middleware = null)
    {
        $this->addRoute('GET', $uri, $callback, $middleware);
    }

    /**
     * @param $uri
     * @param $callback
     * @param null $middleware
     * @internal param $handler
     */
    public function post($uri, $callback, $middleware = null)
    {
        $this->addRoute('POST', $uri, $callback, $middleware);
    }

    /**
     * @param $uri
     * @param $callback
     * @param null $middleware
     * @internal param $handler
     */
    public function put($uri, $callback, $middleware = null)
    {
        $this->addRoute('PUT', $uri, $callback, $middleware);
    }

    /**
     * @param $uri
     * @param $callback
     * @param null $middleware
     * @internal param $handler
     */
    public function delete($uri, $callback, $middleware = null)
    {
        $this->addRoute('DELETE', $uri, $callback, $middleware);
    }

    /**
     * @param $uri
     * @param $callback
     * @param null $middleware
     * @internal param $handler
     */
    public function patch($uri, $callback, $middleware = null)
    {
        $this->addRoute('PATCH', $uri, $callback, $middleware);
    }

    /**
     * @param $uri
     * @param $callback
     * @param null $middleware
     * @internal param $handler
     */
    public function head($uri, $callback, $middleware = null)
    {
        $this->addRoute('HEAD', $uri, $callback, $middleware);
    }

    /**
     * @param $method
     * @param $uri
     * @param $callback
     * @param null $middleware
     * @internal param $handler
     */
    public function addRoute($method, $uri, $callback, $middleware = null)
    {
        $this->container['router.collector']->addRoute($method, $this->routePrefix.$uri, [
            'uses' => new Handler($this->container, $callback),
            'middleware' => $middleware,
        ]);
    }

    /**
     * @param $prefix
     * @param $callback
     */
    public function group($prefix, $callback)
    {
        $originPrefix = $this->routePrefix;
        $this->routePrefix = $prefix;
        $callback($this);
        $this->routePrefix = $originPrefix;
    }

    /**
     * @param $provider
     */
    public function register($provider)
    {
        $this->container->register($provider);
    }

    /**
     * @param $event
     * @param $callback
     */
    public function on($event, \Closure $callback)
    {
        $this->events[$event] = $callback;
    }

    /**
     * main run
     */
    public function run()
    {
        foreach ($this->events as $event => $callback) {
            $this->httpServer->on($event, $callback);
        }

        $this->httpServer->start();
    }
}
