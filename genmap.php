<?php
/*PhpDoc:
name:  genmap.php
title: genmap.php - génération de la carte pour tile.php (PERIME)
functions:
doc: |
journal: |
  1/3/2022:
    périme, remplacé par tilemap.php et tilemap.yaml
  18-20/2/2022:
    passage en version 2
    transformation en script
  8/5/2021:
    appel des tuiles en HTTPS ssi tile a été appelé en HTTPS
  19/4/2017
    adaptation au passage sur MacBook
  7/2/2016
    restructuration du code Javascript pour faciliter l'ajout de couches manuellement dans le texte
*/
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/config.inc.php';

use Symfony\Component\Yaml\Yaml;

/*PhpDoc: functions
name:  genmap
title: function genmap($layers, string $gpkey) - génération de la carte
doc: |
*/
function genmap(array $layers, string $gpkey): void {
  $html = <<<EOT
<html>
  <head>
    <title>igngp/tiles</title>
    <meta charset="UTF-8">
<!-- meta nécessaire pour le mobile -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
<!-- styles nécessaires pour le mobile -->
    <link rel="stylesheet" href="https://visu.gexplor.fr/viewer.css">
<!-- styles et src de Leaflet -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.0/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.0/dist/leaflet.js"></script>
<!-- Include the edgebuffer plugin -->
    <script src="https://visu.gexplor.fr/lib/leaflet.edgebuffer.js"></script>
  </head>
  <body>
    <div id="map" style="height: 100%; width: 100%"></div>
    <script>
var map = L.map('map').setView([48, 3], 8); // view pour la zone
L.control.scale({position:'bottomleft', metric:true, imperial:false}).addTo(map);

EOT;

//  print_r($_SERVER);
  $clyrs = ['base'=>[], 'overlay'=>[]]; // ['base/overlay'=>['title=>title, 'code'=>Code JavaScript] ]
  foreach ($_GET as $k => $v) {
//    echo "k=$k, v=$v<br>\n";
    if (($k<>'action') && ($v<>'off')) {
      $varname = $v.count($clyrs[$v]);
//      echo "varname=$varname<br>\n";
      $layer = $layers[$k];
      $fmt = $layer['format'];
      $format = ($fmt=='png' ? 'image/png' : 'image/jpeg');
      $attribution = $layer['attribution'] ?? "&copy; <a href='http://www.ign.fr'>IGN</a>";
      $tilepath = ($_SERVER['SERVER_NAME']=='localhost' ?
         $_SERVER['SERVER_NAME'].dirname($_SERVER['SCRIPT_NAME'])
       : 'igngp.geoapi.fr');
      $tilepath .= '/tile.php';
      $protocole = (($_SERVER['HTTPS'] ?? null ) == 'on') ? 'https' : 'http';
      $key = '';
      if (!isset($layer['gpkey'])) {
        $key = "/$gpkey";
      }
      $detectRetina = isset($layer['detectRetina']) ? 'false' : 'true';
      $code = <<<EOT
new L.TileLayer(
    '$protocole://$tilepath/$k$key/{z}/{x}/{y}.$fmt',
    { format: '$format', minZoom: $layer[minZoom], maxZoom: $layer[maxZoom], detectRetina: $detectRetina,
      attribution: "$attribution"
    }
  )
EOT;
      $clyrs[$v][] = [
        'title'=> $layers[$k]['title'],
        'code'=> $code,
      ];
    }
  }
  if (!$clyrs['base'])
    die("Erreur: pas de couche de base");
// Traitement particulier pour base0 qui doit à la fois être passé à addLayer() et à L.control.layers()
  $base0 = array_shift($clyrs['base']);
  echo $html,
       "var base0 = $base0[code];\n",
       "map.addLayer(base0);\n\n";
  
  echo "<!-- ajout de l'outil de sélection de couche -->\n";
  echo "L.control.layers({\n";
  $clyrsoutput = [ "  \"$base0[title]\" : base0"];
  foreach ($clyrs['base'] as $layer)
    $clyrsoutput[] =  "  \"$layer[title]\" : $layer[code]";
  echo implode(",\n",$clyrsoutput);
  echo "\n}, {\n";
  $clyrsoutput = [];
  foreach ($clyrs['overlay'] as $layer)
    $clyrsoutput[] =  "  \"$layer[title]\" : $layer[code]";
  echo implode(",\n",$clyrsoutput);
  echo "\n}).addTo(map);\n",
       "      </script>\n",
       "  </body>\n",
       "</html>\n";
  die();
}

$config = config();
genmap($config['layers'], array_keys($config['gpkeys'])[0]);
