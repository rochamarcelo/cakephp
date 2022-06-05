<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         0.2.9
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

namespace Cake\Routing;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;
use Throwable;

class RouteContext
{
    /**
     * @var array
     */
    protected $requestContext;
    /**
     * @var string
     */
    protected $fullBaseUrl;
    /**
     * @var \Cake\Http\ServerRequest|null
     */
    protected $request;

    /**
     * @param array $requestContext Context data.
     */
    /**
     * @param array $requestContext
     * @@param \Cake\Http\ServerRequest|null $request Server Request object.
     */
    public function __construct(array $requestContext, ?ServerRequest $request = null)
    {
        $this->requestContext = $requestContext;
        if ($this->request !== null) {
            $this->setRequest($request);
        }
    }

    /**
     * Finds URL for specified action.
     *
     * Returns a URL pointing to a combination of controller and action.
     *
     * ### Usage
     *
     * - `Router::url('/posts/edit/1');` Returns the string with the base dir prepended.
     *   This usage does not use reverser routing.
     * - `Router::url(['controller' => 'Posts', 'action' => 'edit']);` Returns a URL
     *   generated through reverse routing.
     * - `Router::url(['_name' => 'custom-name', ...]);` Returns a URL generated
     *   through reverse routing. This form allows you to leverage named routes.
     *
     * There are a few 'special' parameters that can change the final URL string that is generated
     *
     * - `_base` - Set to false to remove the base path from the generated URL. If your application
     *   is not in the root directory, this can be used to generate URLs that are 'cake relative'.
     *   cake relative URLs are required when using requestAction.
     * - `_scheme` - Set to create links on different schemes like `webcal` or `ftp`. Defaults
     *   to the current scheme.
     * - `_host` - Set the host to use for the link. Defaults to the current host.
     * - `_port` - Set the port if you need to create links on non-standard ports.
     * - `_full` - If true output of `Router::fullBaseUrl()` will be prepended to generated URLs.
     * - `#` - Allows you to set URL hash fragments.
     * - `_ssl` - Set to true to convert the generated URL to https, or false to force http.
     * - `_name` - Name of route. If you have setup named routes you can use this key
     *   to specify it.
     *
     * @param string|array|\Psr\Http\Message\UriInterface|null $url An array specifying any of the following:
     *   'controller', 'action', 'plugin' additionally, you can provide routed
     *   elements or query string parameters. If string it can be name any valid url
     *   string or it can be an UriInterface instance.
     * @param bool $full If true, the full base URL will be prepended to the result.
     *   Default is false.
     * @return string Full translated URL with base path.
     * @throws \Cake\Core\Exception\CakeException When the route name is not found
     */
    public function buildUrl($url = null, bool $full = false): string
    {
        $context = $this->requestContext;
        $request = $this->getRequest();

        $context['_base'] = $context['_base'] ?? Configure::read('App.base') ?: '';

        if (empty($url)) {
            $here = $request ? $request->getRequestTarget() : '/';
            $output = $context['_base'] . $here;
            if ($full) {
                $output = $this->fullBaseUrl() . $output;
            }

            return $output;
        }

        $params = [
            'plugin' => null,
            'controller' => null,
            'action' => 'index',
            '_ext' => null,
        ];
        if (!empty($context['params'])) {
            $params = $context['params'];
        }

        $frag = '';

        if (is_array($url)) {
            if (isset($url['_path'])) {
                $url = self::unwrapShortString($url);
            }

            if (isset($url['_ssl'])) {
                $url['_scheme'] = $url['_ssl'] === true ? 'https' : 'http';
            }

            if (isset($url['_full']) && $url['_full'] === true) {
                $full = true;
            }
            if (isset($url['#'])) {
                $frag = '#' . $url['#'];
            }
            unset($url['_ssl'], $url['_full'], $url['#']);

            $url = $this->_applyUrlFilters($url);

            if (!isset($url['_name'])) {
                // Copy the current action if the controller is the current one.
                if (
                    empty($url['action']) &&
                    (
                        empty($url['controller']) ||
                        $params['controller'] === $url['controller']
                    )
                ) {
                    $url['action'] = $params['action'];
                }

                // Keep the current prefix around if none set.
                if (isset($params['prefix']) && !isset($url['prefix'])) {
                    $url['prefix'] = $params['prefix'];
                }

                $url += [
                    'plugin' => $params['plugin'],
                    'controller' => $params['controller'],
                    'action' => 'index',
                    '_ext' => null,
                ];
            }

            // If a full URL is requested with a scheme the host should default
            // to App.fullBaseUrl to avoid corrupt URLs
            if ($full && isset($url['_scheme']) && !isset($url['_host'])) {
                $url['_host'] = $context['_host'];
            }
            $context['params'] = $params;

            $output = Router::getRouteCollection()->match($url, $context);
        } else {
            $url = (string)$url;

            $plainString = (
                strpos($url, 'javascript:') === 0 ||
                strpos($url, 'mailto:') === 0 ||
                strpos($url, 'tel:') === 0 ||
                strpos($url, 'sms:') === 0 ||
                strpos($url, '#') === 0 ||
                strpos($url, '?') === 0 ||
                strpos($url, '//') === 0 ||
                strpos($url, '://') !== false
            );

            if ($plainString) {
                return $url;
            }
            $output = $context['_base'] . $url;
        }

        $protocol = preg_match('#^[a-z][a-z0-9+\-.]*\://#i', $output);
        if ($protocol === 0) {
            $output = str_replace('//', '/', '/' . $output);
            if ($full) {
                $output = $this->fullBaseUrl() . $output;
            }
        }

        return $output . $frag;
    }

    /**
     * Sets the full base URL that will be used as a prefix for generating
     * fully qualified URLs for this application. If no parameters are passed,
     * the currently configured value is returned.
     *
     * ### Note:
     *
     * If you change the configuration value `App.fullBaseUrl` during runtime
     * and expect the router to produce links using the new setting, you are
     * required to call this method passing such value again.
     *
     * @param string|null $base the prefix for URLs generated containing the domain.
     * For example: `http://example.com`
     * @return string
     */
    public function fullBaseUrl(?string $base = null): string
    {
        if ($base === null && $this->fullBaseUrl !== null) {
            return $this->fullBaseUrl;
        }

        if ($base !== null) {
            $this->fullBaseUrl = $base;
            Configure::write('App.fullBaseUrl', $base);
        } else {
            $base = (string)Configure::read('App.fullBaseUrl');

            // If App.fullBaseUrl is empty but context is set from request through setRequest()
            if (!$base && !empty($this->requestContext['_host'])) {
                $base = sprintf(
                    '%s://%s',
                    $this->requestContext['_scheme'],
                    $this->requestContext['_host']
                );
                if (!empty($this->requestContext['_port'])) {
                    $base .= ':' . $this->requestContext['_port'];
                }

                Configure::write('App.fullBaseUrl', $base);

                return $this->fullBaseUrl = $base;
            }

            $this->fullBaseUrl = $base;
        }

        $parts = parse_url($this->fullBaseUrl);
        $this->requestContext = [
                '_scheme' => $parts['scheme'] ?? null,
                '_host' => $parts['host'] ?? null,
                '_port' => $parts['port'] ?? null,
            ] + $this->requestContext;

        return $this->fullBaseUrl;
    }

    /**
     * Inject route defaults from `_path` key
     *
     * @param array $url Route array with `_path` key
     * @return array
     */
    protected static function unwrapShortString(array $url)
    {
        foreach (['plugin', 'prefix', 'controller', 'action'] as $key) {
            if (array_key_exists($key, $url)) {
                throw new InvalidArgumentException(
                    "`$key` cannot be used when defining route targets with a string route path."
                );
            }
        }
        $url += Router::parseRoutePath($url['_path']);
        $url += [
            'plugin' => false,
            'prefix' => false,
        ];
        unset($url['_path']);

        return $url;
    }

    /**
     * Applies all the connected URL filters to the URL.
     *
     * @param array $url The URL array being modified.
     * @return array The modified URL.
     * @see \Cake\Routing\Router::url()
     * @see \Cake\Routing\Router::addUrlFilter()
     */
    protected function _applyUrlFilters(array $url): array
    {
        $request = $this->getRequest();
        foreach (Router::getUrlFilters() as $filter) {
            try {
                $url = $filter($url, $request);
            } catch (Throwable $e) {
                if (is_array($filter)) {
                    $ref = new ReflectionMethod($filter[0], $filter[1]);
                } else {
                    /** @psalm-var \Closure|callable-string $filter */
                    $ref = new ReflectionFunction($filter);
                }
                $message = sprintf(
                    'URL filter defined in %s on line %s could not be applied. The filter failed with: %s',
                    $ref->getFileName(),
                    $ref->getStartLine(),
                    $e->getMessage()
                );
                throw new RuntimeException($message, (int)$e->getCode(), $e);
            }
        }

        return $url;
    }

    /**
     * @return array
     */
    public function getRequestContext(): array
    {
        return $this->requestContext;
    }

    /**
     * Get the current request object.
     *
     * @return \Cake\Http\ServerRequest|null
     */
    public function getRequest(): ?ServerRequest
    {
        return $this->request;
    }

    /**
     * @param ServerRequest|null $request
     */
    public function setRequest(?ServerRequest $request): void
    {
        $this->request = $request;
        $this->requestContext['_base'] = $request->getAttribute('base');
        $this->requestContext['params'] = $request->getAttribute('params', []);

        $uri = $request->getUri();
        $this->requestContext += [
            '_scheme' => $uri->getScheme(),
            '_host' => $uri->getHost(),
            '_port' => $uri->getPort(),
        ];
    }
}
