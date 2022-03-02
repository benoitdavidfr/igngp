<?php
/*PhpDoc:
name: tile.php
title: tile.php - service de tuiles simplifiant l'accès aux ressources du GP IGN
doc: |
  Service de tuiles au std OSM simplifiant l'accès au WMTS du GP IGN
  Exemples:
    http://localhost/geoapi/igngp/tile.php
    http://localhost/geoapi/igngp/tile.php/orthos/8/129/88.jpg
    http://localhost/geoapi/igngp/tile.php/cartes/8/129/88.jpg
    https://igngp.geoapi.fr/tile.php/orthos/8/129/88.jpg
    https://tile.openstreetmap.org/8/129/88.png
  Fonctionnalités:
   - appel sans clé
   - simplification des paramètres / WMTS
   - simplification des noms de couches
   - ajout de couches non disponibles en WMTS
   - documentation intégrée
   - génération de carte Leaflet intégrée
   - couche cartes plus simple d'emploi
   - mise en cache pour 21 jours (la durée pourrait dépendre du zoom)
    
  Gestion des erreurs:
   - seules les erreurs de logique du code génèrent un die()
   - en fonctionnement normal toutes les erreurs génèrent une erreur HTTP
journal: |
  19/2/2022:
    - ajout de la prop. User-Agent exigée par le sous-serveur satellites
  18/2/2022:
    - création
includes: [genmap.inc.php]
*/
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

class Tile {
  const MIME_TYPES = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
  ];
  const ERROR_MESSAGES = [
    400 => 'Bad request',
    403 => 'Forbidden',
    404 => 'Not Found',
    500 => 'Internal Server Error',
  ];
  
  protected ?string $url=null; // l'URL qui va permettre d'aller chercher la tuile
  protected string $format; // le format de la tuile sous forme MIME
  
  // génère une erreur Http avec un message éventuel
  static function error(int $errorCode, string $message=''): void {
    $errorMessage = "HTTP/1.1 $errorCode ".(self::ERROR_MESSAGES[$errorCode] ?? 'Error');
    header($errorMessage);
    header("Content-Type: text/plain; charset=utf-8");
    if ($message)
      die("$message\nErreur $errorCode");
    else
      die("$errorMessage\nErreur $errorCode");
  }
  
  static function homePageHtml(): void {
    echo <<<EOT
<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>tile</title></head><body>
<h2>Serveur de tuiles des ressources de l'IGN</h2>
format d'appel: <code>https://$_SERVER[SERVER_NAME]$_SERVER[SCRIPT_NAME]/{layer}/{z}/{x}/{y}.[jpg|png]</code><br>
Où {layer} est le nom d'une des couches ci-dessous.<br>
Les couches peuvent être co-visualisées en les sélectionnant avec le bouton radio de droite
soit en couche de base soit en couche superposée (overlay).<br>
Cette co-visualisation fournit par la même occasion un exemple simple de carte Leaflet utilisant les couches.<br>
Consulter les <a href='index.html#cu'>conditions d'utilisation</a><br>

<form action='tilemap.php'><table border=1><th>nom</th><th>titre</th><th>off/base/overlay</th>

EOT;

    $config = self::config();
    foreach ($config['layers'] as $lyrname => $layer) {
      echo "<tr><td>$lyrname</td>",
           "<td><a href='tile.php/$lyrname'>$layer[title]</a>",
           isset($layer['gpkey']) ? '' : " (<b>Nécessite une clé</b>)",
           "</td>",
           "<td><input type='radio' name='$lyrname' value='off' checked> ",
           "<input type='radio' name='$lyrname' value='base'>  ",
           "<input type='radio' name='$lyrname' value='overlay'></td></tr>\n";
    }

    echo <<<EOT
<tr><td colspan=3><center><input type='submit' value='carte'></center></td></tr>
</table></form>

EOT;
  }
  
  static function homePageJson(): string {
    $config = self::config();
    $homePage = [
      'title'=> "Serveur de tuiles des ressources de l'IGN",
      'layers'=> [],
    ];
    foreach ($config['layers'] as $lyrid => $layer) {
      $homePage['layers'][] = array_merge(
        [
          'name'=> $lyrid,
          'title'=> $layer['title'],
        ],
        isset($layer['abstract']) ? ['abstract'=> $layer['abstract']] : [],
        isset($layer['gpkey']) ? [] : ['gpkey'=> "Couche nécessitant une clé"],
        ['url'=> "https://$_SERVER[SERVER_NAME]$_SERVER[SCRIPT_NAME]/$lyrid"]
      );
    }
    return json_encode($homePage);
  }

  static function layerDocHtml(string $lyrname): void {
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>tile</title></head><body><pre>\n";
    echo Yaml::dump(self::config()['layers'][$lyrname]),"\n";
    
    echo "</pre><h3>Schéma JSON utilisé</h3><pre>\n";
    $layersSchema = Yaml::parseFile(__DIR__.'/config.schema.yaml')['definitions']['layer'];
    echo Yaml::dump($layersSchema, 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
  }
  
  static function layerDocJson(string $lyrname): string {
    return json_encode(self::config()['layers'][$lyrname]);
  }
  
  function doc($layers, $message=null) {
    if (!isset($_SERVER['HTTP_ACCEPT']) or ($_SERVER['HTTP_ACCEPT']=='application/json'))
      docinjson($layers, $message);
    echo "<tr><td colspan=3><center><input type='submit' value='carte'></center></td></tr>\n",
         "</table></form>\n",
         "<a href='?action=docinjson'>Affichage de la doc en JSON</a>\n";
    die();
  }
  
  static function config(): array {
    if (is_file(__DIR__.'/config.pser') && (filemtime(__DIR__.'/config.pser') > filemtime(__DIR__.'/config.yaml'))) {
      return unserialize(file_get_contents(__DIR__.'/config.pser'));
    }
    else {
      $config = Yaml::parseFile(__DIR__.'/config.yaml');
      file_put_contents(__DIR__.'/config.pser', serialize($config));
      return $config;
    }
  }
  
  function __construct(string $layer, string $gpkey, int $x, int $y, int $z, string $fmt) {
    $config = self::config();
    if (!isset($config['layers'][$layer]))
      self::error(400, "couche '$layer' non définie");

    $layerConf = $config['layers'][$layer];
    if (!$gpkey) {
      if (!($gpkey = $layerConf['gpkey'] ?? null)) {
        self::error(500, "Clé non définie pour la couche '$layer'");
      }
    }
    $this->format = self::MIME_TYPES[$layerConf['format']];
    $gpname = $layerConf['gpname'];
    $style = $layerConf['style'] ?? 'normal';
    $server = $config['servers'][$layerConf['server']]; // caractéristiques du serveur
    switch ($server['protocol']) {
      case 'WMTS': {
        $this->url = str_replace('{key}', $gpkey, $server['url'])
          .'?service=WMTS&version=1.0.0&request=GetTile'
          .'&tilematrixSet=PM&height=256&width=256'
          ."&layer=$gpname&format=$this->format&style=$style"
          ."&tilematrix=$z&tilecol=$x&tilerow=$y";
        break;
      }
      
      default:
        self::error(500, "protocole '$server[protocol]' non implémenté");
    }
  }
  
  function get(): ?string { // récupère le contenu de la tuile sur le serveur IGN 
    $referer = $_SERVER['HTTP_REFERER'] ?? null;
    $http_context_options = [
      'method'=>"GET",
      'timeout' => 60, // 1 min.
      'ignore_errors'=> true,
      'header'=>"Accept-language: en\r\n"
               .($referer ? "referer: $referer\r\n" : '')
               ."User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:97.0) Gecko/20100101 Firefox/97.0\r\n",
    ];
    $stream_context = stream_context_create(['http'=>$http_context_options]);
    $data = file_get_contents($this->url, false, $stream_context);
    $errorCode = substr($http_response_header[0], 9, 3);
    if ($errorCode == 200)
      return $data;
    else {
      //print_r($http_response_header);
      // L'erreur est retournée sous la forme d'un ExceptionReport
      $pattern = '!<ExceptionReport[^>]*>\s*<Exception exceptionCode="([^"]*)"\s*>\s*([^<]*)</Exception>\s*</ExceptionReport>!';
      if (preg_match($pattern, $data, $matches))
        self::error($errorCode, "exceptionCode: $matches[1], message: $matches[2]");
      else
        self::error($errorCode, $data);
    }
  }
  
  function sendData(string $data): void { // Envoi des données avec mise en cache
    $nbDaysInCache = 21;
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: max-age='.($nbDaysInCache*24*60*60)); // mise en cache pour $nbDaysInCache jours
    header('Expires: '.date('r', time() + ($nbDaysInCache*24*60*60))); // mise en cache pour $nbDaysInCache jours
    header('Last-Modified: '.date('r'));
    header("Content-Type: $this->format");
    die($data);
  }
  
  static function process(): void { // Traite la requête
    if (!isset($_SERVER['PATH_INFO'])) {
      if (!isset($_SERVER['HTTP_ACCEPT']) || ($_SERVER['HTTP_ACCEPT']=='application/json')) {
        header('Content-Type: application/json');
        die(self::homePageJson());
      }
      else {
        self::homePageHtml();
        die();
      }
    }
    elseif (preg_match('!^/([^/]+)(/([^/]+))?/(\d+)/(\d+)/(\d+)\.(png|jpg)$!', $_SERVER['PATH_INFO'], $matches)) {
      $tile = new Tile(layer: $matches[1], gpkey: $matches[3], x: $matches[5], y: $matches[6], z: $matches[4], fmt: $matches[7]);
      $data = $tile->get();
      $tile->sendData($data);
    }
    elseif (preg_match('!^/([^/]+)(/([^/]+))?(/{z}/{x}/{y}\.(png|jpg))?$!', $_SERVER['PATH_INFO'], $matches)) {
      $lyrid = $matches[1];
      if (!isset($_SERVER['HTTP_ACCEPT']) || ($_SERVER['HTTP_ACCEPT']=='application/json')) {
        header('Content-Type: application/json');
        die(self::layerDocJson($lyrid));
      }
      else {
        self::layerDocHtml($lyrid);
        die();
      }
    }
    else {
      self::error(400);
    }
  }
}

Tile::process();
