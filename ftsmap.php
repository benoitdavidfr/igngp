<?php
/*PhpDoc:
title: ftsmap.php - affichage d'une carte d'un objet ou d'une collection issu de fts.php
name: ftsmap.php
doc: |
journal: |
  1/3/2022:
    - adaptation pour pouvoir fonctionner soit en mode http soit en mode Php
  28/2/2022:
    - création
*/
ini_set('memory_limit', '10G');

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/../../geovect/gegeom/gebox.inc.php';
require_once __DIR__.'/../../geovect/gegeom/zoom.inc.php';
require_once __DIR__.'/config.inc.php';
require_once __DIR__.'/llmap.inc.php';

use Symfony\Component\Yaml\Yaml;

//define('MODE', 'http'); // requête du service par appel d'une URL hhtp
define('MODE', 'Php'); // requête du service au travers de la classe FeatureServer
//define('MODE', 'test'); // requête du service lu dans un fichier exemple

// l'URL de base pour les appels en mode http et pour les couches dans les cartes
$baseFtsUrl = (($_SERVER['HTTP_HOST']=='localhost')? 'http://localhost/geoapi/igngp' : 'https://igngp.geoapi.fr') . '/fts.php';

if (MODE == 'Php') { // En mode Php, Utilisation de la classe FeatureServer
  require_once __DIR__.'/../../geovect/features/ftrserver.inc.php';
}

if (in_array($_SERVER['PATH_INFO'] ?? null, [null, '/'])) { // appel initial /
  foreach (config()['themes'] as $thid => $theme) {
    echo "<a href='$_SERVER[SCRIPT_NAME]/$thid'>$theme[title]</a><br>\n";
  }
  die();
}

if (preg_match('!^/([^/]+)$!', $_SERVER['PATH_INFO'], $matches)) { // /{theme}
  $thid = $matches[1];
  if (MODE == 'http') { // requête du service par appel d'une URL hhtp
    $colls = file_get_contents("$baseFtsUrl/$thid/collections");
    //echo "<pre>$colls";
    $colls = json_decode($colls, true);
  }
  else { // requête du service au travers de la classe FeatureServer
    $ftrserver = FeatureServer::new('wfs', "/wxs.ign.fr/$thid/geoportail/wfs", '', null);
    $colls = $ftrserver->collections('');
  }
  $colls = $colls['collections'];
  //echo '<pre>colls='; print_r($colls);
  foreach ($colls as $coll) {
    echo "<a href='$_SERVER[SCRIPT_NAME]/$thid/$coll[id]'>$coll[title]</a><br>\n";
  }
  die();
}

if (preg_match('!^/([^/]+)/([^/]+)$!', $_SERVER['PATH_INFO'], $matches)) { // /{theme}/{collId}
  $thid = $matches[1];
  $collId = $matches[2];
  $startindex = $_GET['startindex'] ?? 0;
  $limit = $_GET['limit'] ?? 10;
  $properties = $_GET['properties'] ?? null;
  //echo "path=$path<br>\n";
  if (MODE == 'http') { // requête du service par appel d'une URL hhtp
    $params = '';
    foreach ($_GET as $k=>$v) {
      if ($v !== '')
        $params .= ($params?'&':'')."$k=".urlencode($v);
    }
    $path = "$baseFtsUrl/$thid/collections/$collId/items?$params";
    $items = file_get_contents($path);
    $items = json_decode($items, true);
  }
  elseif (MODE == 'Php') { // requête du service au travers de la classe FeatureServer
    $filters = [];
    foreach ($_GET as $k=>$v) {
      if (!in_array($k, ['f','properties','limit','startindex']) && ($v != ''))
        $filters[$k] = $v;
    }
    $ftrserver = FeatureServer::new('wfs', "/wxs.ign.fr/$thid/geoportail/wfs", '', null);
    $items = $ftrserver->items('', $collId, [], $filters, $properties ? explode(',', $properties) : [], $limit, $startindex);
  }
  else { // Test
    $items = file_get_contents(__DIR__.'/items-eg.json');
    $items = json_decode($items, true);
  }
  //echo '<pre>',Yaml::dump($items),"</pre>\n";
  $properties = $items['features'] ? array_keys($items['features'][0]['properties']) : [];
  // formulaire de modification des paramètres
  echo "<table border=1><form>";
  echo "<tr><td>startindex</td><td><input type='text' name ='startindex' value='$startindex'>\n";
  echo "<tr><td>limit</td><td><input type='text' name ='limit' value='$limit'>\n";
  echo "<tr><td>properties</td><td><input type='text' name ='properties' value='",implode(',',$properties),"'>\n";
  foreach ($properties as $prop)
    echo "<tr><td>$prop</td><td><input type='text' name ='$prop' value='",$_GET[$prop] ?? '',"'>\n";
  echo "<tr><td colspan=2><center><input type='submit'></center></td></tr>\n";
  echo "</table>\n";
  echo "numberReturned=$items[numberReturned] / numberMatched=$items[numberMatched]<br>\n";
  echo "<table border=1>\n";
  foreach ($items['features'] as $i => $feature) {
    if ($i == 0)
      echo "<th>",implode('</th><th>', array_keys($feature['properties'])),"</th>\n";
    $href = "$_SERVER[SCRIPT_NAME]/$thid/$collId/$feature[id]/map/".implode(',', $feature['bbox']);
    echo "<tr><td><a href='$href'>",implode('</td><td>', $feature['properties']),"</a></td></tr>\n";
  }
  echo "</table>\n";
  $next = $startindex+$limit;
  echo "<a href='$_SERVER[SCRIPT_NAME]/$thid/$collId/map?startindex=$startindex'>map</a><br>\n";
  echo "<a href='$_SERVER[SCRIPT_NAME]/$thid/$collId?startindex=$next";
  foreach ($_GET as $k => $v)
    if (($k <> 'startindex') && ($v <> ''))
      echo "&amp;$k=$v";
  echo "'>next</a><br>\n";
  die();
}

if (preg_match('!^xx/([^/]+)/([^/]+)/map$!', $_SERVER['PATH_INFO'], $matches)) { // /{theme}/{collId}/map
  $thid = $matches[1];
  $collId = $matches[2];
  $startindex = $_GET['startindex'] ?? 0;
  $collUrl = "$baseFtsUrl/$thid/collections/$collId/items?startindex=$startindex";
  $collUrl0 = "$baseFtsUrl/$thid/collections/$collId/items";
  echo <<<EOT
<!DOCTYPE HTML><html><head>
  <title>carte $_SERVER[PATH_INFO]</title>
  <meta charset="UTF-8">
  <!-- meta nécessaire pour le mobile -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <!-- styles nécessaires pour le mobile -->
  <link rel='stylesheet' href='https://geoapi.fr/shomgt/leaflet/llmap.css'>
  <!-- styles et src de Leaflet -->
  <link rel="stylesheet" href='https://geoapi.fr/shomgt/leaflet/leaflet.css'/>
  <script src='https://geoapi.fr/shomgt/leaflet/leaflet.js'></script>
  <!-- Include the edgebuffer plugin -->
  <script src="https://geoapi.fr/shomgt/leaflet/leaflet.edgebuffer.js"></script>
  <!-- Include the Control.Coordinates plugin -->
  <link rel='stylesheet' href='https://geoapi.fr/shomgt/leaflet/Control.Coordinates.css'>
  <script src='https://geoapi.fr/shomgt/leaflet/Control.Coordinates.js'></script>
  <!-- Include the uGeoJSON plugin -->
  <script src="https://geoapi.fr/shomgt/leaflet/leaflet.uGeoJSON.js"></script>
  <!-- plug-in d'appel des GeoJSON en AJAX -->
  <script src='https://geoapi.fr/shomgt/leaflet/leaflet-ajax.js'></script>
</head>
<body>
  <div id="map" style="height: 100%; width: 100%"></div>
  <script>

// affichage des caractéristiques de chaque GeoTiff
var onEachFeature = function (feature, layer) {
  var popupContent = '<pre><u><i>id</i></u>: '+feature.id+"\\n";
  popupContent += '<u><i>'+'properties</i></u>: '+JSON.stringify(feature.properties)+"\\n";
  popupContent += '</ul>';
  layer.bindPopup(popupContent);
  layer.bindTooltip(feature.id);
}

var map = L.map('map').setView([46.5,3],6);  // view pour la zone
L.control.scale({position:'bottomleft', metric:true, imperial:false}).addTo(map);

// activation du plug-in Control.Coordinates
var c = new L.Control.Coordinates();
c.addTo(map);
map.on('click', function(e) { c.setCoordinates(e); });

var baseLayers = {
  // IGN
  "Plan IGN" : new L.TileLayer(
    'https://igngp.geoapi.fr/tile.php/plan-ignv2/{z}/{x}/{y}.png',
    { "format":"image/jpeg","minZoom":0,"maxZoom":18,"detectRetina":false,
      "attribution":"&copy; <a href='http://www.ign.fr' target='_blank'>IGN</a>"
    }
  ),
  // OSM
  "OSM" : new L.TileLayer(
    'http://{s}.tile.osm.org/{z}/{x}/{y}.png',
    {"attribution":"&copy; <a href='https://www.openstreetmap.org/copyright' target='_blank'>les contributeurs d’OpenStreetMap</a>"}
  ),
  // Fond blanc
  "Fond blanc" : new L.TileLayer(
    'https://visu.gexplor.fr/utilityserver.php/whiteimg/{z}/{x}/{y}.jpg',
    { format: 'image/jpeg', minZoom: 0, maxZoom: 21, detectRetina: false}
  )
};
map.addLayer(baseLayers["Plan IGN"]);

var overlays = {
  "$collId" : new L.GeoJSON.AJAX('$collUrl', {
    style: { color: 'blue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeature
  }),

  "UGeoJSONLayer" : new L.UGeoJSONLayer({
      endpoint: '$collUrl0',
      minZoom: 0, maxZoom: 18, usebbox: true, onEachFeature: onEachFeature
  }),
  
// affichage d'une couche debug
  "debug" : new L.TileLayer(
    'http://visu.gexplor.fr/utilityserver.php/debug/{z}/{x}/{y}.png',
    {"format":"image/png","minZoom":0,"maxZoom":21,"detectRetina":false}
  )
};

L.control.layers(baseLayers, overlays).addTo(map);
    </script>
  </body>
</html>
EOT;
  die();
}

if (preg_match('!^/([^/]+)/([^/]+)/map$!', $_SERVER['PATH_INFO'], $matches)) { // /{theme}/{collId}/map
  $thid = $matches[1];
  $collId = $matches[2];
  $startindex = $_GET['startindex'] ?? 0;
  $llmap = new LLMap(__DIR__.'/lyrmap.yaml');
  $llmap->show([
    'title'=> "carte $_SERVER[PATH_INFO]",
    'collId'=> $collId,
    'collUrl'=> "$baseFtsUrl/$thid/collections/$collId/items?startindex=$startindex",
    'collUrl0'=> "$baseFtsUrl/$thid/collections/$collId/items",
  ]);
  die();
}

if (preg_match('!^/([^/]+)/([^/]+)/([^/]+)/map/([-\d,.]+)$!', $_SERVER['PATH_INFO'], $matches)) { // /{theme}/{collId}/{itemId}/map
  $thid = $matches[1];
  $collId = $matches[2];
  $itemId = $matches[3];
  $bbox = new \gegeom\GBox($matches[4]);
  $center = $bbox->center();
  $center = json_encode([$center[1],$center[0]]);
  $zoom = \gegeom\Zoom::zoomForGBoxSize($bbox->size());
  $itemUrl = "$baseFtsUrl/$thid/collections/$collId/items/$itemId";
  echo <<<EOT
<!DOCTYPE HTML><html><head>
  <title>carte $_SERVER[PATH_INFO]</title>
  <meta charset="UTF-8">
  <!-- meta nécessaire pour le mobile -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <!-- styles nécessaires pour le mobile -->
  <link rel='stylesheet' href='https://geoapi.fr/shomgt/leaflet/llmap.css'>
  <!-- styles et src de Leaflet -->
  <link rel="stylesheet" href='https://geoapi.fr/shomgt/leaflet/leaflet.css'/>
  <script src='https://geoapi.fr/shomgt/leaflet/leaflet.js'></script>
  <!-- Include the edgebuffer plugin -->
  <script src="https://geoapi.fr/shomgt/leaflet/leaflet.edgebuffer.js"></script>
  <!-- Include the Control.Coordinates plugin -->
  <link rel='stylesheet' href='https://geoapi.fr/shomgt/leaflet/Control.Coordinates.css'>
  <script src='https://geoapi.fr/shomgt/leaflet/Control.Coordinates.js'></script>
  <!-- Include the uGeoJSON plugin -->
  <script src="https://geoapi.fr/shomgt/leaflet/leaflet.uGeoJSON.js"></script>
  <!-- plug-in d'appel des GeoJSON en AJAX -->
  <script src='https://geoapi.fr/shomgt/leaflet/leaflet-ajax.js'></script>
</head>
<body>
  <div id="map" style="height: 100%; width: 100%"></div>
  <script>
var itemurl = '$itemUrl';

// affichage des caractéristiques de chaque GeoTiff
var onEachFeature = function (feature, layer) {
  var popupContent = '<pre><u><i>id</i></u>: '+feature.id+"\\n";
  popupContent += '<u><i>'+'properties</i></u>: '+JSON.stringify(feature.properties)+"\\n";
  popupContent += '</ul>';
  layer.bindPopup(popupContent);
  layer.bindTooltip(feature.id);
}

var map = L.map('map').setView($center,$zoom);  // view pour la zone
L.control.scale({position:'bottomleft', metric:true, imperial:false}).addTo(map);

// activation du plug-in Control.Coordinates
var c = new L.Control.Coordinates();
c.addTo(map);
map.on('click', function(e) { c.setCoordinates(e); });

var baseLayers = {
  // IGN
  "Plan IGN" : new L.TileLayer(
    'https://igngp.geoapi.fr/tile.php/plan-ignv2/{z}/{x}/{y}.png',
    { "format":"image/jpeg","minZoom":0,"maxZoom":18,"detectRetina":false,
      "attribution":"&copy; <a href='http://www.ign.fr' target='_blank'>IGN</a>"
    }
  ),
  // OSM
  "OSM" : new L.TileLayer(
    'http://{s}.tile.osm.org/{z}/{x}/{y}.png',
    {"attribution":"&copy; <a href='https://www.openstreetmap.org/copyright' target='_blank'>les contributeurs d’OpenStreetMap</a>"}
  ),
  // Fond blanc
  "Fond blanc" : new L.TileLayer(
    'https://visu.gexplor.fr/utilityserver.php/whiteimg/{z}/{x}/{y}.jpg',
    { format: 'image/jpeg', minZoom: 0, maxZoom: 21, detectRetina: false}
  )
};
map.addLayer(baseLayers["Plan IGN"]);

var overlays = {
  "item" : new L.GeoJSON.AJAX(itemurl, {
    style: { color: 'blue'}, minZoom: 0, maxZoom: 18, onEachFeature: onEachFeature
  }),

// affichage d'une couche debug
  "debug" : new L.TileLayer(
    'http://visu.gexplor.fr/utilityserver.php/debug/{z}/{x}/{y}.png',
    {"format":"image/png","minZoom":0,"maxZoom":21,"detectRetina":false}
  )
};
map.addLayer(overlays["item"]);

L.control.layers(baseLayers, overlays).addTo(map);
    </script>
  </body>
</html>
EOT;
  die();
}

die("no match");