<?php

namespace ManaPHP\Mvc;

use ManaPHP\Http\Response;
use ManaPHP\Router\NotFoundRouteException;
use ManaPHP\View;

/**
 * Class ManaPHP\Mvc\Application
 *
 * @package application
 * @property \ManaPHP\Http\RequestInterface   $request
 * @property \ManaPHP\Http\ResponseInterface  $response
 * @property \ManaPHP\RouterInterface         $router
 * @property \ManaPHP\Mvc\DispatcherInterface $dispatcher
 * @property \ManaPHP\ViewInterface           $view
 * @property \ManaPHP\Http\SessionInterface   $session
 */
class Application extends \ManaPHP\Application
{
    /**
     * Application constructor.
     *
     * @param \ManaPHP\Loader      $loader
     * @param \ManaPHP\DiInterface $di
     */
    public function __construct($loader, $di = null)
    {
        parent::__construct($loader, $di);

        $web = '';
        if (isset($_SERVER['SCRIPT_NAME']) && ($pos = strrpos($_SERVER['SCRIPT_NAME'], '/')) > 0) {
            $web = substr($_SERVER['SCRIPT_NAME'], 0, $pos);
            if (substr_compare($web, '/public', -7) === 0) {
                $web = substr($web, 0, -7);
            }
        }
        $this->alias->set('@web', $web);

        $this->attachEvent('dispatcher:beforeDispatch', [$this, 'authorize']);
    }

    public function authenticate()
    {
        return $this->identity->authenticate();
    }

    /**
     * @param \ManaPHP\Mvc\DispatcherInterface $dispatcher
     */
    public function authorize($dispatcher)
    {

    }

    /**
     * @return \ManaPHP\Http\ResponseInterface
     * @throws \ManaPHP\Router\NotFoundRouteException
     */
    public function handle()
    {
        $this->authenticate();

        if (!$this->router->handle()) {
            throw new NotFoundRouteException(['router does not have matched route for `:uri`', 'uri' => $this->router->getRewriteUri()]);
        }

        $controllerName = $this->router->getControllerName();
        $actionName = $this->router->getActionName();
        $params = $this->router->getParams();

        $ret = $this->dispatcher->dispatch($controllerName, $actionName, $params);
        if ($ret !== false) {
            $actionReturnValue = $this->dispatcher->getReturnedValue();
            if ($actionReturnValue === null || $actionReturnValue instanceof View) {
                $this->view->render($this->dispatcher->getControllerName(), $this->dispatcher->getActionName());
                $this->response->setContent($this->view->getContent());
            } elseif ($actionReturnValue instanceof Response) {
                null;
            } else {
                $this->response->setJsonContent($actionReturnValue);
            }
        }

        return $this->response;
    }

    /**
     * @return array
     */
    public function coreComponents()
    {
        return [
            'router' => 'ManaPHP\Router',
            'dispatcher' => 'ManaPHP\Mvc\Dispatcher',
            'actionInvoker' => 'ManaPHP\ActionInvoker',
            'errorHandler' => 'ManaPHP\Mvc\ErrorHandler',
            'url' => 'ManaPHP\View\Url',
            'response' => 'ManaPHP\Http\Response',
            'request' => 'ManaPHP\Http\Request',
            'html' => 'ManaPHP\View\Html',
            'view' => 'ManaPHP\View',
            'flash' => 'ManaPHP\View\Flash\Adapter\Direct',
            'flashSession' => 'ManaPHP\View\Flash\Adapter\Session',
            'session' => 'ManaPHP\Http\Session',
            'captcha' => 'ManaPHP\Security\Captcha',
            'csrfToken' => 'ManaPHP\Security\CsrfToken',
            'viewsCache' => ['class' => 'ManaPHP\Cache\Engine\File', 'dir' => '@data/viewsCache', 'extension' => '.html'],
            'cookies' => 'ManaPHP\Http\Cookies',
            'debugger' => 'ManaPHP\Debugger',
            'authorization' => 'ManaPHP\Authorization\Bypass',
            'swooleHttpServer' => 'ManaPHP\Swoole\HttpServer'
        ];
    }

    public function main()
    {
        $this->dotenv->load();
        $this->configure->loadFile();

        $this->registerServices();

        try {
            $this->handle();
        } /** @noinspection PhpUndefinedClassInspection */
        catch (\Exception $e) {
            $this->errorHandler->handle($e);
        } catch (\Error $e) {
            $this->errorHandler->handle($e);
        }

        $this->response->setHeader('X-Response-Time', sprintf('%.3f', microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']));
        $this->response->send();
    }
}