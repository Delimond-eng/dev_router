<?php


use Rtgroup\DevRouter\Core\Request;
use Rtgroup\DevRouter\Core\Response;
use Rtgroup\DevRouter\Core\Router;
use Rtgroup\DevRouter\Exceptions\CallbackNotFound;
use Rtgroup\DevRouter\Exceptions\RouteNotFoundException;

require_once "vendor/autoload.php";



$router = new Router();

$router
    ->prefix('/home')
    ->get('/', function () {
        Response::
        withHeader("Content-Type", "application/json")::
        withStatus(200, 'OK')::
        withBody([ "message" => "Get message with body json" ])::
        send();
    })
    ->post('/', function (){
        Response::
        withHeader("Content-Type", "application/json")::
        withStatus(200, 'OK')::
        withBody([ "message" => "Welcome" ])::
        send();
    })
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

