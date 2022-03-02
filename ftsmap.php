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
  echo "<a href='$_SERVER[SCRIPT_NAME]/$thid/$collId/map?startindex=$startindex&limit=$limit'>map</a><br>\n";
  echo "<a href='$_SERVER[SCRIPT_NAME]/$thid/$collId?startindex=$next";
  foreach ($_GET as $k => $v)
    if (($k <> 'startindex') && ($v <> ''))
      echo "&amp;$k=$v";
  echo "'>next</a><br>\n";
  die();
}

if (preg_match('!^/([^/]+)/([^/]+)/map$!', $_SERVER['PATH_INFO'], $matches)) { // /{theme}/{collId}/map
  $thid = $matches[1];
  $collId = $matches[2];
  $startindex = $_GET['startindex'] ?? 0;
  $limit = $_GET['limit'] ?? 10;
  LLMap::genPhp(
    __DIR__.'/lyrmap.yaml',
    [
      'title'=> "carte $_SERVER[PATH_INFO]",
      'collId'=> $collId,
      'collUrl'=> "$baseFtsUrl/$thid/collections/$collId/items?startindex=$startindex&limit=$limit",
      'collUrl0'=> "$baseFtsUrl/$thid/collections/$collId/items?startindex=0&limit=$limit",
    ]
  );
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
  LLMap::genPhp(
    __DIR__.'/itemmap.yaml',
    [
      'title'=> "carte $_SERVER[PATH_INFO]",
      'center'=> $center,
      'zoom'=> $zoom,
      'itemUrl'=> $itemUrl,
    ]
  );
  die();
}

die("no match");