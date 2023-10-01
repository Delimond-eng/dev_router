<?php


use middlewares\Auth;
use Rtgroup\DevRouter\Core\Response;
use Rtgroup\DevRouter\Core\Router;
use Rtgroup\DevRouter\Exceptions\CallbackNotFound;
use Rtgroup\DevRouter\Exceptions\RouteNotFoundException;

require_once "vendor/autoload.php";
require_once "controllers/HomeController.php";
require_once "controllers/AgentController.php";
require_once "middlewares/Auth.php";

$router = new Router();

$router
->middleware([
    [Auth::class, 'verify_token']
])
       ->prefix('/home')
    ->post('/', [HomeController::class, 'index'])
    ->get('/view', [HomeController::class, 'view'])
    ->save();
$router->prefix('/agents')
        ->get('/all', [AgentController::class, 'all'])
        ->post('/create', [AgentController::class, 'create'])
->save();

/**
 * Handle all routes
 */

try {
    $router->handle();
} catch (CallbackNotFound $e) {
    echo $e->getMessage();
} catch (RouteNotFoundException $ex) {
    echo $ex->getMessage();
}




