<?php


use Rtgroup\DevRouter\Core\Request;
use Rtgroup\DevRouter\Core\Response;

class HomeController{

    public function index(Request $request): void
    {
        $message = $request->get('message');
        try {
            Response::
            withHeader("Content-Type", "application/json")::
            withStatus(200, 'OK')::
            withBody(["message" => $message])::
            send();
        } catch (JsonException $e) {
        }
    }

    public function view(Request $request) :void{
        $data = $request->get('message');

        try {
            Response::
            withHeader("Content-Type", "application/json")::
            withStatus(200, 'OK')::
            withBody(["message" => $data])::
            send();
        } catch (JsonException $e) {
        }

    }


}