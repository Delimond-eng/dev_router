<?php

namespace middlewares;

use JsonException;
use Rtgroup\DevRouter\Core\Request;
use Rtgroup\DevRouter\Core\Response;

class Auth
{
    public function verify_token(Request $request) : void {

        $token = $request->headers('Authorization');

        if(count($token)>0){
            try {
                Response::
                withHeader("Content-Type", "application/json")::
                withStatus(200, 'OK')::
                withBody(["message" => "success"])::
                send();
            } catch (JsonException $e) {
            }
        }
        else{
            try {
                Response::
                withHeader("Content-Type", "application/json")::
                withStatus(401, 'OK')::
                withBody(["message" => "token invalid", "status"=>"failed"])::
                send();
            } catch (JsonException $e) {
            }
            die();
        }


        //Do something
    }
}