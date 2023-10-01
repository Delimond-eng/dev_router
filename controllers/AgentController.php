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
        try {
            Response::
            withHeader("Content-Type", "application/json")::
            withStatus(200, 'OK')::
            withBody(["datas" => $data])::
            send();
        } catch (JsonException $e) {
        }
    }
}