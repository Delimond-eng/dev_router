<?php

namespace Rtgroup\DevRouter\Core;

use Closure;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Rtgroup\DevRouter\Exceptions\CallbackNotFound;
use Rtgroup\DevRouter\Exceptions\RouteNotFoundException;
use Rtgroup\DevRouter\Interfaces\IContainer;

class Router
{
    /**
     * @var string GET Constante représentant une méthode de requête GET
     */
    public const GET = 'GET';

    /**
     * @var string POST Constante représentant une méthode de requête POST
     */
    public const POST = 'POST';

    /**
     * @var string PUT Constante représentant une méthode de requête PUT
     */
    public const PUT = 'PUT';

    /**
     * @var string PATCH  Constante représentant une méthode de requête PATCH
     */
    public const PATCH = 'PATCH';

    /**
     * @var string DELETE Constante représentant une méthode de requête DELETE
     */
    public const DELETE = 'DELETE';

    /**
     * @var string OPTIONS Constante représentant une méthode de requête OPTIONS
     */
    public const OPTIONS = 'OPTIONS';

    /**
     * @var Request $request Instance d'une classe Request à passer en argument au rappel des routes($callback)
     */
    public Request $request;
    /**
     * @var string $prefix Routes prefix
     */
    private string $prefix = '';
    /**
     * @var array $middlewares Liste des middlewares à exécuter avant l'appel des routes
     */
    private array $middlewares = [];
    /**
     * @var array $routes Liste des routes disponibles
     */
    private array $routes = [];
    /**
     * @var array $routes Détenteur temporaire des informations d'itinéraire jusqu'à ce que tout soit stocké dans le tableau $routes principal
     */
    private array $tmpRoutes = [];

    /**
     * @var array|null Tableau de $routes actuel en cours de traitement pour faciliter l'accès dans d'autres méthodes
     */
    private ?array $currentRoute = NULL;

    /**
     * @var Response Instance d'une classe Response à passer en argument à $routes $callback
     */
    private Response $response;

    /**
     * Router constructor
     */
    public function __construct()
    {
        $this->request = new Request;
        $this->response = Response::getInstance();
    }

    /**
     * Méthode utilisée pour ajouter une $route
     *
     * @param string $path Path for the route
     * @param callable|array|string|null $callback Callback method, an anonymous function or a class and method name to be executed
     * @param string|array|null $methods Allowed request method(s) (GET, POST, PUT, PATCH, DELETE)
     */
    public function add(
        string                     $path = '',
        callable|array|string|null $callback = NULL,
        string|array|null          $methods = self::GET
    ) : void {
        $this->route($path, $callback, $methods);
        $this->save();
    }

    /**
     * Méthode utilisée pour ajouter de nouveaux $routes dans la liste temporaire lors de l'utilisation d'une approche de méthode chaînée
     *
     * @param string $path Path for the route
     * @param callable|array|string|null $callback Callback method, an anonymous function or a class and method name to be executed
     * @param string|array $methods Allowed request method(s) (GET, POST, PUT, PATCH, DELETE)
     *
     * @return Router Renvoie une instance de lui-même afin que d'autres méthodes puissent y être enchaînées
     */
    public function route(
        string                     $path,
        callable|array|string|null $callback,
        string|array               $methods = self::GET
    ) : self {
        if ( is_string($methods) ) $methods = [ $methods ];

        if ( !empty($this->prefix) ) $path = $this->prefix . $path; // Prepend prefix to routes

        if ( $path !== '/' ) $path = rtrim($path, '/');

        $regex = NULL;
        $arguments = NULL;
        if ( str_contains($path, '{') ) {
            $regex = preg_replace('/{.+?}/', '(.+?)', $path);
            $regex = str_replace('/', '\/', $regex);
            $regex = "^$regex$";
            preg_match_all('/{(.+?)}/', $path, $matches);
            if ( isset($matches[1]) && count($matches) > 0 ) $arguments = $matches[1];
        }

        foreach ( $methods as $method ) {
            $this->tmpRoutes[$method][$path] = [
                'callback' => $callback,
                'middlewares' => $this->middlewares,
            ];

            if ( !is_null($regex) ) {
                $this->tmpRoutes[$method][$path]['regex'] = $regex;
                if ( !is_null($arguments) ) {
                    $this->tmpRoutes[$method][$path]['arguments'] = $arguments;
                }
            }
        }

        $this->save(false);
        return $this;
    }

    /**
     * Méthode utilisée pour enregistrer les informations de routage dans le tableau global $routes
     *
     * @param bool $cleanData les données des tmpRoutes doivent-elles être effacées ou non lors de l'exécution de cette méthode. Par defaut c'est TRUE
     */
    public function save(bool $cleanData = true) : self {

        foreach ( $this->tmpRoutes as $method => $route ) {
            if ( !isset($this->routes[$method]) ) $this->routes[$method] = [];
            $path = array_key_first($route);

            if ( count($this->middlewares) > 0 && count($route[$path]['middlewares']) === 0 ) {
                $route[$path]['middlewares'] = $this->middlewares;
            }

            $route[$path]["di"] = [];
            if ( is_array($route[$path]["callback"]) && count($route[$path]["callback"]) > 2 ) {
                //store manual entries for dependency injection
                while ( count($route[$path]["callback"]) > 2 ) {
                    $route[$path]["di"][] = array_pop($route[$path]["callback"]);
                }
            }

            if ( !empty($this->prefix) && !str_starts_with($path, $this->prefix) ) {
                $newPath = rtrim("$this->prefix$path", '/');
                $route[$newPath] = $route[$path];
                unset($route[$path]);
            }

            $this->routes[$method] = array_merge($this->routes[$method], $route);
        }

        if ( $cleanData ) {
            $this->prefix = '';
            $this->middlewares = [];
        }

        $this->tmpRoutes = [];


        return $this;
    }

    /**
     * Méthode utilisée pour gérer l'exécution des routes et des middlewares
     *
     * @throws RouteNotFoundException Lorsque la $route n'a pas été trouvé
     * @throws CallbackNotFound Lorsque le rappel($callback) pour la $route n'a pas été trouvé
     */
    public function handle() : void {

        $path = $this->get_path();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ( !isset($this->routes[$method]) ) {
            throw new RouteNotFoundException("Route $path not found", 404);
        }

        $route = $this->routes[$method][$path] ?? false;

        $arguments = [];
        if ( $route === false ) {
            $dynamic_routes = array_filter($this->routes[$method], fn($route) => !is_null($route['regex'] ?? NULL));
            foreach ( $dynamic_routes as $routePath => $dynamic_route ) {
                $countRouteSlashes = count(explode("/", $routePath));
                $countPathSlashes = count(explode('/', $path));

                //TODO: Find a way to not check the number of / as it seems a bit hacky
                if ( $countPathSlashes !== $countRouteSlashes ) continue;

                if ( preg_match("/{$dynamic_route['regex']}/", $path) ) {
                    $route = $dynamic_route;
                    $arguments = $this->get_route_arguments($dynamic_route, $path);
                    break;
                }
            }
        }

        if ( $route === false ) throw new RouteNotFoundException("Route $path not found", 404);
        $this->currentRoute = $route;

        $middlewares = $route['middlewares'] ?? [];
        $this->execute_middleware($middlewares);

        $callback = $route['callback'] ?? false;
        if ( $callback === false ) throw new CallbackNotFound("No callback specified for $path", 404);

        $callback = $this->setup_callback($callback);

        if ( !is_callable($callback) ) throw new CallbackNotFound("Unable to execute callback for $path", 404);

        $parameters = $this->get_all_arguments($callback);

        $callbackArguments = [];
        foreach ( $parameters as $name => $type ) {
            if (strtolower($type) === strtolower('Gac\Routing\Request')) {
                $callbackArguments[$name] = $this->request;
                continue;
            }

            if (strtolower($type) === strtolower('Gac\Routing\Response')) {
                $callbackArguments[$name] = $this->response;
                continue;
            }
            $callbackArguments[$name] = $arguments[$name] ?? NULL;
        }

        foreach ( $this->currentRoute["di"] as $argument ) {
            $name = array_key_first($argument);
            $value = $argument[$name];
            if ( !isset($callbackArguments[$name]) ) {
                $callbackArguments[$name] = $value;
            }
        }

        $this->currentRoute = NULL;

        call_user_func_array($callback, $callbackArguments);
    }

    /**
     * Méthode utilisée pour obtenir une liste d'arguments pour une route
     *
     * @param array $route
     * @param string $path
     *
     * @return array
     */
    private function get_route_arguments(array $route, string $path) : array {
        $arguments = [];
        if ( !isset($route["regex"]) ) return $arguments;

        preg_match_all("/{$route['regex']}/", $path, $matches);
        if ( count($matches) > 1 ) array_shift($matches);
        $matches = array_map(fn($m) => $m[0], $matches);

        $args = $route['arguments'] ?? [];
        foreach ( $args as $index => $argumentName ) {
            $type = 'string';
            if ( str_contains($argumentName, ':') ) {
                $colonIndex = strpos($argumentName, ':');
                $type = substr($argumentName, 0, $colonIndex);
                $argumentName = substr($argumentName, $colonIndex + 1, strlen($argumentName));
            }

            $value = $matches[$index] ?? NULL;
            $value = match ( $type ) {
                'int' => intval($value),
                'float' => floatval($value),
                'double' => doubleval($value),
                'bool' => is_numeric($value) ? boolval($value) : ( $value === 'true' ),
                default => (string) $value,
            };

            $arguments[$argumentName] = $value;
        }

        return $arguments;
    }

    /**
     * Méthode utilisée pour configurer les propriétés $callback pour les routes
     *
     * @param Closure|string|array $callback Callback data of a route
     *
     * @return mixed Renvoie les données nécessaires pour exécuter le rappel($callback)
     */
    private function setup_callback(Closure|string|array $callback) : mixed {
        if ( ( is_string($callback) && class_exists($callback) ) || is_array($callback) ) {
            if ( is_string($callback) ) {
                return new $callback;
            }

            if ( is_array($callback) ) {
                //There is no method provided so relay on __invoke to be used
                if ( isset($callback[1]) && is_array($callback[1]) ) {
                    $callback[1] = IContainer::get($callback[0], $callback[1]);
                    return new $callback[0](...$callback[1]);
                }

                //There is a method provided but also any other arguments
                if ( isset($callback[1]) && is_string($callback[1]) ) {
                    //There are dependencies that need to be injected
                    if ( isset($callback[2]) ) {
                        $callback[2] = IContainer::get($callback[0], $callback[2]);
                        return [ new $callback[0](...$callback[2]), $callback[1] ];
                    }
                    return [ new $callback[0], $callback[1] ];
                }

                $args = IContainer::get($callback[0]);
                return [ new $callback[0](...$args), "__invoke" ];
            }
        }

        return $callback;
    }

    /**
     * Méthode qui renvoie le chemin actuel auquel l'utilisateur tente d'accéder
     *
     * @return string Returns the current path
     */
    private function get_path() : string {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $position = strpos($path, '?');

        $path = ( $path !== '/' ) ? rtrim($path, '/') : $path;
        return ( $position === false ) ? $path : substr($path, 0, $position);
    }

    /**
     * Méthode qui exécute chaque middleware spécifié avant l'exécution du rappel de la route
     *
     * @param array $data Liste des middlewares à exécuter avant d'accéder au point final
     *
     * @throws CallbackNotFound Lorsque la méthode middleware spécifiée est introuvable
     */
    private function execute_middleware(array $data) : void {
        $namedArguments = match ( is_null($this->currentRoute) ) {
            false => $this->get_route_arguments($this->currentRoute, $this->get_path()),
            default => []
        };

        foreach ( $data as $key => $function ) {
            $arguments = [];
            $tmpArguments = [];

            if ( is_integer($key) && is_array($function) ) {
                $class = $function[0];
                $method = $function[1];
                array_shift($function);
                array_shift($function);
                $tmpArguments = $function;
                $function = [ new $class, $method ];
            }

            if ( is_string($key) ) {
                $tmpArguments = [ $function ];
                $function = $key;
            }

            $parameters = $this->get_all_arguments($function);
            $requestClassIndex = array_search(Request::class, array_values($parameters));

            $paramNames = array_keys($parameters);
            for ( $index = 0; $index < count($parameters); $index++ ) {
                if ( $index === $requestClassIndex ) {
                    $arguments[$index] = $this->request;
                    continue;
                }
                $arguments[$index] = $tmpArguments[$index] ?? $namedArguments[$paramNames[$index]] ?? NULL;
            }

            if ( !is_callable($function) ) throw new CallbackNotFound("Middleware method $function not found", 404);
            call_user_func($function, ...$arguments);
        }
    }

    /**
     * Méthode privée utilisée pour récupérer les arguments des méthodes de rappel de la route
     *
     * @param object|array|string $function
     *
     * @return array|null Renvoie une liste d'arguments pour une méthode ou null en cas d'erreur
     */
    private function get_all_arguments(object|array|string $function) : array|null {
        $function_get_args = [];
        try {
            if ( ( is_string($function) && function_exists($function) ) || $function instanceof Closure ) {
                $ref = new ReflectionFunction($function);
            } elseif (
                is_string($function) &&
                ( str_contains($function, "::") && !method_exists(...explode("::", $function)) )
            ) {
                return $function_get_args;
            } elseif ( is_object($function) || is_array($function) ) {
                $class = ( (array) $function )[0];
                $method = ( (array) $function )[1];
                $ref = new ReflectionMethod($class, $method);
            } else {
                return $function_get_args;
            }

            foreach ( $ref->getParameters() as $param ) {
                if ( !isset($function_get_args[$param->name]) ) {
                    $type = $param->getType();
                    if ( is_null($type) ) {
                        $function_get_args[$param->name] = 'nothing';
                    } else {
                        if ( $type instanceof ReflectionNamedType ) {
                            $function_get_args[$param->name] = $type->getName() ?? 'string';
                        } elseif ( $type instanceof ReflectionUnionType ) {
                            # $function_get_args[$param->name] = implode("|", $type->getTypes());
                            $function_get_args[$param->name] = "mixed";
                        }
                    }
                }
            }
            return $function_get_args;
        } catch ( ReflectionException $ex ) {
            error_log($ex->getMessage());
            return NULL;
        }
    }

    /**
     * Méthode utilisée pour récupérer une liste de toutes les routes créées
     *
     * @return array Renvoie la liste des routes définies
     */
    public function get_routes() : array {
        return $this->routes;
    }

    /**
     * Méthode Wrapper utilisée pour ajouter de nouvelles routes GET
     *
     * @param string $path Path for the route
     * @param callable|array|string|null $callback $callback, une fonction anonyme ou un nom de classe et de méthode à exécuter
     *
     * @return Router Renvoie une instance de lui-même afin que d'autres méthodes puissent y être enchaînées
     */
    public function get(string $path, callable|array|string|null $callback = NULL) : self {
        $this->route($path, $callback, [ self::GET ]);
        return $this;
    }

    /**
     * Méthode Wrapper utilisée pour ajouter de nouvelles routes POST
     *
     * @param string $path Path for the route
     * @param callable|array|string|null $callback $callback, une fonction anonyme ou un nom de classe et de méthode à exécuter
     *
     * @return Router Renvoie une instance de lui-même afin que d'autres méthodes puissent y être enchaînées
     */
    public function post(string $path, callable|array|string|null $callback = NULL) : self {
        $this->route($path, $callback, [ self::POST ]);
        return $this;
    }

    /**
     * Méthode Wrapper utilisée pour ajouter de nouvelles routes PUT
     *
     * @param string $path Path for the route
     * @param callable|array|string|null $callback $callback, une fonction anonyme ou un nom de classe et de méthode à exécuter
     *
     * @return Router Renvoie une instance de lui-même afin que d'autres méthodes puissent y être enchaînées
     */
    public function put(string $path, callable|array|string|null $callback = NULL) : self {
        $this->route($path, $callback, [ self::PUT ]);
        return $this;
    }

    /**
     * Méthode Wrapper utilisée pour ajouter de nouvelles routes PATCH
     *
     * @param string $path Path for the route
     * @param callable|array|string|null $callback $callback, une fonction anonyme ou un nom de classe et de méthode à exécuter
     *
     * @return Router Renvoie une instance de lui-même afin que d'autres méthodes puissent y être enchaînées
     */
    public function patch(string $path, callable|array|string|null $callback = NULL) : self {
        $this->route($path, $callback, [ self::PATCH ]);
        return $this;
    }

    /**
     * Méthode Wrapper utilisée pour ajouter de nouvelles routes DELETE
     *
     * @param string $path Path for the route
     * @param callable|array|string|null $callback $callback, une fonction anonyme ou un nom de classe et de méthode à exécuter
     *
     * @return Router Renvoie une instance de lui-même afin que d'autres méthodes puissent y être enchaînées
     */
    public function delete(string $path, callable|array|string|null $callback = NULL) : self {
        $this->route($path, $callback, [ self::DELETE ]);
        return $this;
    }

    /**
     * Méthode Wrapper utilisée pour ajouter de nouvelles routes OPTIONS
     *
     * @param string $path Path for the route
     * @param callable|array|string|null $callback $callback, une fonction anonyme ou un nom de classe et de méthode à exécuter
     *
     * @return Router Renvoie une instance de lui-même afin que d'autres méthodes puissent y être enchaînées
     */
    public function options(string $path, callable|array|string|null $callback = NULL) : self {
        $this->route($path, $callback, [ self::OPTIONS ]);
        return $this;
    }

    /**
     * Méthode utilisée pour définir le préfixe des itinéraires
     *
     * @param string $prefix Préfixe à ajouter à toutes les routes de la chaîne.
     *
     * @return Router Renvoie une instance de lui-même afin que d'autres méthodes puissent y être enchaînées
     */
    public function prefix(string $prefix = '') : self {
        $this->prefix .= $prefix;
        return $this;
    }

    /**
     * Méthode utilisée pour définir les middlewares pour les routes
     *
     * @param array $data Liste des middlewares à exécuter avant les routes
     *
     * @return Router Renvoie une instance de lui-même afin que d'autres méthodes puissent y être enchaînées
     */
    public function middleware(array $data) : self {
        $this->middlewares = array_merge($this->middlewares, $data);
        return $this;
    }

    /**
     * Méthode utilisée pour ajouter plus de routes au gestionnaire de route principal
     *
     * @param array $routes Liste des $routes d'autres classes des $routes
     *
     * @return Router Renvoie une instance de lui-même afin que d'autres méthodes puissent y être enchaînées
     */
    public function append(array $routes) : self {
        $this->routes = array_merge_recursive($routes, $this->routes);
        return $this;
    }
}