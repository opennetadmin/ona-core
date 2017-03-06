<?php

namespace ONA\controllers;

class domains {
   //Constructor
   public function __construct($app) {
       require_once($GLOBALS['base'].'/lib/modules/domains.php');
   }


   public function Any($request, $response, $args) {

     // Process various method types
     switch ($request->getMethod()) {
       case 'GET':
         $output = process_output(run_module('domains', $args + (array)$request->getQueryParams()));
         break;
       case 'POST':
         $output = process_output(run_module('domain_add', $request->getParsedBody()));
         $response = $response->withStatus(201);
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }

     return $response->withJson($output);
   }



   public function Specific($request, $response, $args) {

     // Process various method types
     switch ($request->getMethod()) {
       case 'GET':
         $output = process_output(run_module('domain_display', $args + (array)$request->getQueryParams()));
         break;
       case 'DELETE':
         $output = process_output(run_module('domain_del', $args));
         break;
       case 'POST':
         $output = process_output(run_module('domain_modify', $args + (array)$request->getParsedBody()));
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }

     return $response->withJson($output);
   }

}
