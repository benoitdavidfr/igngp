<?php
/*PhpDoc:
name: llmap.inc.php
title: llmap.inc.php - module de transformation d'une description Yaml en code Php
doc: |
  Une carte est définie par un document Yaml conforme au schéma llmap.schema.yaml.
  La méthode LLMap::genPhp() transforme une telle définition en code Php/Html/JavaScript
  Le fichier llmap.yaml contient des éléments biens connus qui peuvent être réutilisés dans les définitions
  au moyen de références JSON.
  Les cartes peuvent être paramétrées par des variables Php en incluant dans le fichier Yaml l'affichage de ces variables ;
  les valeurs asssociées à ces variables sont définies dans la variable $vars.
journal: |
  1/3/2022:
    - création
*/
require_once __DIR__.'/vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

class JsonRef {
  static function deref(array $ref, array $val): array|string {
    //echo $ref['$ref'];
    $ref = $ref['$ref'];
    $pos = strpos($ref, '#');
    //echo "pos=$pos\n";
    $ref = substr($ref, $pos+2);
    //echo "ref=$ref\n";
    $ref = explode('/', $ref);
    //print_r($ref);
    foreach ($ref as $key) {
      if (!isset($val[$key]))
        throw new Exception ("Erreur deref sur $key");
      $val = $val[$key];
    }
    return $val;
  }
};

class LLMap {
  static $wk; // éléments biens connus issus du fichier llmap.yaml
  
  // fonction récursive remplacant dans les chaines les variables Php par leur valeur
  static function replacePhpVars(string|array $srce, array $vars): string|array {
    if (is_array($srce)) {
      $result = [];
      foreach ($srce as $k => $v) {
        if (is_string($v) || is_array($v))
          $result[$k] = self::replacePhpVars($v, $vars);
        else
          $result[$k] = $v;
      }
      return $result;
    }
    elseif (is_string($srce)) {
      $pattern = '!<\?php echo \$([a-zA-Z0-9]+);\?>!';
      while (preg_match($pattern, $srce, $matches)) {
        //print_r ($matches);
        $replacement = $vars[$matches[1]] ?? '$'.$matches[1].' non défini';
        $srce = preg_replace($pattern, $replacement, $srce, 1);
      }
      //echo "replacePhpVars($str0)-> $str\n";
      return $srce;
    }
  }
  
  private static function plugInInclude(array|string $plugIn): void {
    if (is_string($plugIn))
      echo $plugIn;
    else
      echo JsonRef::deref($plugIn, self::$wk);
  }

  private static function plugInActivation(array|string $plugIn): void {
    if (is_string($plugIn))
      echo '  ',str_replace("\n","\n  ",$plugIn);
    else
      echo JsonRef::deref($plugIn, self::$wk);
  }
  
  private static function head(array $head, array $vars): void {
    echo "<!DOCTYPE HTML><html><head>\n";
    echo '  <title>',self::replacePhpVars($head['title'], $vars),"</title>\n";
    echo "  <meta charset='UTF-8'>\n";
    echo "  <!-- meta nécessaire pour le mobile -->\n";
    echo "  <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no' />\n";
    echo "  <!-- styles nécessaires pour le mobile -->\n";
    echo "  <link rel='stylesheet' href='https://geoapi.fr/shomgt/leaflet/llmap.css'>\n";
    echo "  <!-- styles et src de Leaflet -->\n";
    echo "  <link rel='stylesheet' href='https://unpkg.com/leaflet@1.7.1/dist/leaflet.css'",
      " integrity='sha512-xodZBNTC5n17Xt2atTPuE1HxjVMSvLVW9ocqUKLsCC5CXdbqCmblAshOMAS6/keqq/sMZMZ19scR4PsZChSR7A=='",
      " crossorigin='' />\n";
    echo "  <script src='https://unpkg.com/leaflet@1.7.1/dist/leaflet.js'",
      " integrity='sha512-XQoYMqMTK8LvdxXYG3nZ448hOEQiglfqkJs1NOQV44cWnUrBc8PkAOcXy20w0vlaXaVUearIOBhiXZ5V3ynxwA=='",
      " crossorigin=''></script>\n";
    foreach ($head['plugIns'] as $plugIn) {
      self::plugInInclude($plugIn);
    }
    echo "</head>\n";
  }
  
  private static function setview(array $view, array $vars): void {
    if (isset($view['$ref']))
      $view = JsonRef::deref($view, self::$wk);
    if (is_array($view['latLon']))
      $latLon = json_encode($view['latLon']);
    else
      $latLon = self::replacePhpVars($view['latLon'], $vars);
    if (is_int($view['zoom']))
      $zoom = $view['zoom'];
    else
      $zoom = self::replacePhpVars($view['zoom'], $vars);
    echo "var map = L.map('map').setView($latLon,$zoom);  // view pour la zone\n";
  }
  
  private static function layerParams(array $params, array $vars): void {
    foreach ($params as $i => $param) {
      echo $i ? ",\n" : '';
      if (is_string($param)) {
        $param = self::replacePhpVars($param, $vars);
        echo "    '$param'";
      }
      elseif (is_array($param)) {
        $json = json_encode(self::replacePhpVars($param, $vars), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $json = preg_replace('!{"\$function":"([^"]+)"}!', '$1', $json);
        echo "    ",$json;
      }
    }
    echo "\n";
  }
  
  private static function layer(string $lyrid, array $layer, array $vars): void {
    if (isset($layer['$ref'])) {
      $layer = JsonRef::deref($layer, self::$wk);
    }
    $lyrid = self::replacePhpVars($lyrid, $vars);
    echo "  '$lyrid': new $layer[type](\n";
    self::layerParams($layer['params'], $vars);
    echo "  ),\n";
  }
  
  private static function body(array $body, array $vars) {
    echo "<body>\n";
    echo "  <div id='map' style='height: 100%; width: 100%'></div>\n";
    echo "  <script>\n";
    echo $body['jsFunctions'] ?? '',"\n";
    self::setview($body['view'], $vars);
    echo "L.control.scale({position:'bottomleft', metric:true, imperial:false}).addTo(map);\n\n";
    foreach ($body['plugInActivation'] as $plugIn) {
      self::plugInActivation($plugIn);
    }
    
    echo "var baseLayers = {\n";
    foreach ($body['baseLayers'] as $lyrid => $layer)
      self::layer($lyrid, $layer, $vars);
    echo "};\n";
    echo "map.addLayer(baseLayers['$body[defaultBaseLayer]']);\n\n";
    echo "var overlays = {\n";
    foreach ($body['overlays'] as $lyrid => $layer)
      self::layer($lyrid, $layer, $vars);
    echo "};\n";
    foreach ($body['addLayers'] ?? [] as $lyrId)
      echo "map.addLayer(overlays['$lyrId']);\n";
    echo "L.control.layers(baseLayers, overlays).addTo(map);\n";
    echo "    </script>\n";
    echo "  </body>\n";
    echo "</html>\n";
  }
  
  static function genPhp(string|array $mapDef, array $vars=[]): void {
    self::$wk = Yaml::parseFile(__DIR__.'/llmap.yaml');
    if (is_string($mapDef))
      $mapDef = Yaml::parseFile($mapDef);
    self::head($mapDef['head'], $vars);
    self::body($mapDef['body'], $vars);
  }
};