<?php
/*PhpDoc:
name: fts.php
title: fts.php - exposition avec le protocole API Features des données exposées sur le serveur WFS du géoportail
doc: |
  Réutilisation du code de ../../geovect/features
  Définition d'une page d'accueil spécifique avec la liste des thèmes du Géoportail
  Cette liste est stockée dans le fichier de config
journal: |
  1/3/2022:
    - utilisation de la fonction fts()
  18/2/2022:
    - création
includes:
  - ../../geovect/features/fts.inc.php
  - config.inc.php
*/
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/../../geovect/features/fts.inc.php';
require_once __DIR__.'/config.inc.php';

use Symfony\Component\Yaml\Yaml;

if (!isset($_SERVER['PATH_INFO']) || ($_SERVER['PATH_INFO'] == '/')) { // appel sans paramètre 
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>features</title></head><body>
<h2>Données du serveur WFS du Géoportail exposées selon le protocole OGC API Features</h2>
Ce site expose les données proposées par le serveur WFS du Géoportail de l'IGN
sous la forme d'un bouquet de serveurs conformes
à la <a href='http://docs.opengeospatial.org/is/17-069r3/17-069r3.html' target='_blank'>norme OGC API Features</a>.<br>
Il est en développement.<p>

Les URI des serveurs sont les suivants :<ul>\n";

  foreach (config()['themes'] as $thid => $theme) {
    echo "<li><a href='$_SERVER[SCRIPT_NAME]/$thid'>https://igngp.geoapi.fr/fts.php/$thid</a>
      pour le thème $theme[title]</li>\n";
  }

  echo "</ul>
Ces serveurs peuvent notamment être utilisés :<ul>
  <li>pour naviguer avec un navigateur en Html,</li>
  <li>y accéder au travers d'une API décrite en Open API conformément au standard OGC API Features,</li>
  <li>enfin, les consulter avec les dernières versions
    de <a href='https://www.qgis.org/fr/site/' target='_blank'>QGis (>= 3.16)</a>.<br>\n";
  die();
}

if (!preg_match('!^/([^/]+)!', $_SERVER['PATH_INFO'], $matches))
  error("Erreur, chemin '$_SERVER[PATH_INFO]' non interprété", 400);

//echo "matches="; print_r($matches);
$theme = $matches[1];
$configTheme = config()['themes'][$theme];
//echo '<pre>$configTheme='; print_r($configTheme); echo "</pre>\n"; die();

$doc = new Doc([
  'title'=> "Doc contenant le chemin pour le serveur WFS souhaité et la spec. correspondante",
  '$schema'=> 'doc',
  'datasets'=> [
    $theme => [
      'title'=> $configTheme['title'],
      'abstract'=> $configTheme['abstract'],
      'path'=> "/wfs/wxs.ign.fr/$theme/geoportail/wfs",
    ]
    + (isset($configTheme['conformsTo']) ? ['conformsTo'=> $configTheme['conformsTo']] : []),
  ],
]);

fts($_SERVER['PATH_INFO'], $doc);
