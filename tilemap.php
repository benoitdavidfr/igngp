<?php
/*PhpDoc:
name:  tilemap.php
title: tilemap.php - génération de la carte pour tile.php (PERIME)
doc: |
journal: |
  1/3/2022:
    remplacement de genmap.php en utilisant llmap
*/
require_once __DIR__.'/config.inc.php';
require_once __DIR__.'/llmap.inc.php';

use Symfony\Component\Yaml\Yaml;

$config = config();
$layers = $config['layers'];
$gpkey = array_keys($config['gpkeys'])[0];

$map = Yaml::parseFile(__DIR__.'/tilemap.yaml');
$map['body']['baseLayers'] = [];
$map['body']['overlays'] = [];
$map['body']['defaultBaseLayer'] = null;

foreach ($_GET as $k => $v) {
  // outre action, $_GET contient une entrée par id de couche avec pour valeur off, base ou overlay
  if (($k<>'action') && ($v<>'off')) {
    $layer = $layers[$k];
    $protocole = (($_SERVER['HTTPS'] ?? null ) == 'on') ? 'https' : 'http';
    $tilepath = ($_SERVER['SERVER_NAME']=='localhost' ?
       $_SERVER['SERVER_NAME'].dirname($_SERVER['SCRIPT_NAME'])
     : 'igngp.geoapi.fr');
    $tilepath .= '/tile.php';
    $key = isset($layer['gpkey']) ? '' : "/$gpkey";
    
    $kindLayer = ($v == 'base') ? 'baseLayers' : 'overlays';
    $map['body'][$kindLayer][$layer['title']] = [
      'type'=> 'L.TileLayer',
      'params'=> [
        "$protocole://$tilepath/$k$key/{z}/{x}/{y}.$layer[format]",
        [
          'format'=> ($layer['format']=='png' ? 'image/png' : 'image/jpeg'),
          'minZoom'=> $layer['minZoom'],
          'maxZoom'=> $layer['maxZoom'],
          'detectRetina'=> !isset($layer['detectRetina']),
          'attribution'=> $layer['attribution'] ?? "&copy; <a href='http://www.ign.fr'>IGN</a>",
        ],
      ]
    ];
    if (!$map['body']['defaultBaseLayer'] && ($v=='base'))
      $map['body']['defaultBaseLayer'] = $layer['title'];
  }
}

LLMap::genPhp($map);
