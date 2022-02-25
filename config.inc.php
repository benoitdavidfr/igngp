<?php
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

function config(): array {
  if (is_file(__DIR__.'/config.pser') && (filemtime(__DIR__.'/config.pser') > filemtime(__DIR__.'/config.yaml'))) {
    return unserialize(file_get_contents(__DIR__.'/config.pser'));
  }
  else {
    $config = Yaml::parseFile(__DIR__.'/config.yaml');
    file_put_contents(__DIR__.'/config.pser', serialize($config));
    return $config;
  }
}
