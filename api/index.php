<?php
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$apidef = Yaml::parseFile(__DIR__.'/apidef.yaml');
header('Content-Type: application/json');
die(json_encode($apidef));
