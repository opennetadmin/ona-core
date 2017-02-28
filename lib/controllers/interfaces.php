<?php

namespace ONA\controllers;

class interfaces {
   //Constructor
   public function __construct($app) {
       require_once($GLOBALS['base'].'/lib/modules/interfaces.php');
   }


   public function Any($request, $response, $args) {

     // Process various method types
     switch ($request->getMethod()) {
       case 'GET':
         $output = process_output(run_module('interfaces', $args + (array)$request->getQueryParams()));
         break;
       case 'POST':
         $output = process_output(run_module('interface_add', $args + (array)$request->getParsedBody()));
         $response = $response->withStatus(201);
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }

     return $response->withJson($output);
   }


//TODO: check if the path is under host.. then force only using that instead of -d host= option.
   public function Specific($request, $response, $args) {

     // Process various method types
     switch ($request->getMethod()) {
       case 'GET':
         $output = process_output(run_module('interface_display', $args + (array)$request->getQueryParams()));
         break;
       case 'DELETE':
         $output = process_output(run_module('interface_del', $args));
         break;
       case 'POST':
         $output = process_output(run_module('interface_modify', $args + (array)$request->getParsedBody()));
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }

     return $response->withJson($output);
   }

}
