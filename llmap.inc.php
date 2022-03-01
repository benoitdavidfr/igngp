<?php
require_once __DIR__.'/vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

class LLMap {
  protected string $fileName;
  
  function __construct(string $fileName) {
    $this->fileName = $fileName;
  }
  
  function show0(array $vars): void {
    foreach ($vars as $k => $v) {
      $$k = $v;
    }
    require 'lyrmap.inc.php';
  }
  
  // fonction récursive replacant les dans les chaines les variables Php par leur valeur
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
  
  private function head(array $head, array $vars): void {
    echo "<!DOCTYPE HTML><html><head>\n";
    echo '  <title>',self::replacePhpVars($head['title'], $vars),"</title>\n";
    echo "  <meta charset='UTF-8'>\n";
    echo "  <!-- meta nécessaire pour le mobile -->\n";
    echo "  <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no' />\n";
    echo "  <!-- styles nécessaires pour le mobile -->\n";
    echo "  <link rel='stylesheet' href='https://geoapi.fr/shomgt/leaflet/llmap.css'>\n";
    echo "  <!-- styles et src de Leaflet -->\n";
    echo "  <link rel='stylesheet' href='https://geoapi.fr/shomgt/leaflet/leaflet.css'/>\n";
    echo "  <script src='https://geoapi.fr/shomgt/leaflet/leaflet.js'></script>\n";
    echo '  ',str_replace("\n","\n  ",$head['plugIns']);
    echo "</head>\n";
  }
  
  private function setview(array $view): void {
    echo "var map = L.map('map').setView(",json_encode($view['latLon']),",$view[zoom]);  // view pour la zone\n";
  }
  
  private function layerParams(array $params, array $vars): void {
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
  
  private function layer(string $lyrid, array $layer, array $vars): void {
    $lyrid = self::replacePhpVars($lyrid, $vars);
    echo "  '$lyrid': new $layer[type](\n";
    $this->layerParams($layer['params'], $vars);
    echo "  ),\n";
  }
  
  private function body(array $body, array $vars) {
    echo "<body>\n";
    echo "  <div id='map' style='height: 100%; width: 100%'></div>\n";
    echo "  <script>\n";
    echo $body['jsFunctions'],"\n";
    $this->setview($body['view']);
    echo "L.control.scale({position:'bottomleft', metric:true, imperial:false}).addTo(map);\n\n";
    echo $body['plugInActivation'],"\n";
    
    echo "var baseLayers = {\n";
    foreach ($body['baseLayers'] as $lyrid => $layer)
      $this->layer($lyrid, $layer, $vars);
    echo "};\n";
    echo "map.addLayer(baseLayers['$body[defaultBaseLayer]']);\n\n";
    echo "var overlays = {\n";
    foreach ($body['overlays'] as $lyrid => $layer)
      $this->layer($lyrid, $layer, $vars);
    echo "};\n";
    echo "L.control.layers(baseLayers, overlays).addTo(map);\n";
    echo "    </script>\n";
    echo "  </body>\n";
    echo "</html>\n";
  }
  
  function show(array $vars): void {
    $def = Yaml::parseFile($this->fileName);
    $this->head($def['head'], $vars);
    $this->body($def['body'], $vars);
  }
};