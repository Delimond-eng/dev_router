<?php
use Rtgroup\DevRouter\Core\Request;
use Rtgroup\DevRouter\Core\Response;

class AgentController
{
    public function all() :void{
        $agents = [];
        try {
            Response::
            withHeader("Content-Type", "application/json")::
            withStatus(200, 'OK')::
            withBody(["message" => $agents])::
            send();
        } catch (JsonException $e) {
        }
    }

    public function create(Request $request):void{
        $data = $request->getAll();
        $header = $request->headers(key: 'Authorization');
        try {
            Response::
            withHeader("Content-Type", "application/json")::
            withStatus(200, 'OK')::
            withBody(["datas" => $data, "headers"=>$header])::
            send();
        } catch (JsonException $e) {
        }
    }
}