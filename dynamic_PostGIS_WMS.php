<?php
function UpdateLayer($oMap,$LayerArray, $projString)
{
    /**
     * Generic function that adds a Layer to the Map object based on a description array.
     * The the set(), setMetaData(), setConnectionType() will be used to set the layer's properties
     * aside from the class and style objects
     *
     * INPUT: Skeleton map object, Array with layer description, extent, srs, style (specified by SLD path), name of shapefile
     * OUTPUT: Map object with layer
     * 
     * Extensively based on Jorge's code: edited January 2011 by L. Bastin to allow dynamic selection of images / shapefiles
     * Reedited L Bastin 2015 to connect to PostGIS and allow a user to set thresholds for colouring polygons
     */
    $oLayer=$oMap->getLayer(0);
    
    //Standard set
    $SetArray=$LayerArray["set"];

    foreach ($SetArray as $k=>$v){
        $oLayer->set($k,$v);
    };
    // Metadata info
    $SetMetaArray=$LayerArray["metadata"];
    foreach ($SetMetaArray as $k=>$v){
        $oLayer->setMetaData($k,$v);
    };

    $oLayer->setProjection($projString);

    //Create classes for the different categories: TODO make this more flexible in future
     //class of layer with line color
    if (array_key_exists('classes', $LayerArray)) {
    
      $ClassArray=$LayerArray["classes"];  // This test (for existence) will throw an error in the Apache logs - ignore it.
      foreach ($ClassArray as &$classInfo) {

        $oClass=ms_newClassObj($oLayer);
        $oClass->set("name",$classInfo["name"]);
        //$oClass->setExpression($classInfo["expression"]);
        $oClass->setExpression($classInfo["expression"]);
       
        $style = ms_newStyleObj($oClass);
        
        $style->outlinecolor->setRGB( $classInfo["style_line"][0],$classInfo["style_line"][1],$classInfo["style_line"][2] );
        $style->color->setRGB( $classInfo["style_fill"][0],$classInfo["style_fill"][1],$classInfo["style_fill"][2] );
      }
    };
    
    // $styleFileName = '/tmp/ecoregions.sld';
    // this was temporary for exporting the SLD, and then replacing \\n with \n in Notepad
    //error_log($oMap->generateSLD());
    // $sldStyle = $oMap->generateSLD();
    // file_put_contents($styleFileName, $sldStyle);

    $mapFileName = '/tmp/test2.map';
    $oMap->save($mapFileName);
    // For looking at the plausibility of the map thet gets generated
   
    return($oMap);


}; //end of function

function cleanParamValue($r, $inString)
{
  if (!isset($r[strtoupper($inString)]) && !isset($r[$inString]))
  {
    return null;
  }
  else
  {
    $retVal = isset($r[strtoupper($inString)]) ? $r[strtoupper($inString)] : $r[$inString];
    return trim($retVal);
  }
}
/*
 * Uncomment to debug
 */
ini_set("error_reporting",E_ALL ^E_STRICT);


//WFS is time/memory consuming
// trys to send as compressed (faster)
ob_start("ob_gzhandler");
ini_set('max_execution_time', 3000);
ini_set('always_populate_raw_post_data',true);

$request = ms_newOwsRequestObj();

foreach ($_REQUEST as $k=>$v) {
  $request->setParameter($k,$v);
};

// Make an upper and lower-case version of the params to handle user error
$req_down = array_change_key_case($_REQUEST, CASE_LOWER);

$request->setParameter('LAYERS','dynamic_WMS_layer');

//Creation of the map obect
$oMap=ms_newMapObj("template_postgisWMS.map");

$dataString = "geom from (select * from " . cleanParamValue($_REQUEST,'schema') . '.' . cleanParamValue($_REQUEST,'table') . ") as foo using unique id";

// this is a mandatory parameter: assume it will be there
$coords = explode(",", cleanParamValue($_REQUEST,'bbox'));
$llx = (float)$coords[0];
$lly = (float)$coords[1];
$urx = (float)$coords[2];
$ury = (float)$coords[3];

// Default values for thresholds
$thr1 = 5.0;
$thr2 = 10.0;
$thr3 = 17.0;

if (cleanParamValue($_REQUEST,'thresholds'))
{
  // default to showing everything
  $thrs = explode(",", cleanParamValue($_REQUEST,'thresholds'));
  $thr1 = (float)$thrs[0];
  $thr2 = (float)$thrs[1];
  $thr3 = (float)$thrs[2];
}

$srsInfo = explode(":", cleanParamValue($_REQUEST,'srs'));
$epsgCode = $srsInfo[1];

$epsgString = "init=epsg:" . $epsgCode;
//error_log($epsgString);
// there were problems when this was not also passed to the layer in UpdateLayer below.

$oMap->set("status", MS_ON);
// units, symbol set, fonts?

$oMap->setExtent($llx,$lly,$urx,$ury);
$oMap->setSize(cleanParamValue($_REQUEST,'width'), cleanParamValue($_REQUEST,'height'));

// The MS_TRUE will sets the units (and extent?) to EPSG code, no need for setting units
$oMap->setProjection($epsgString, MS_TRUE);

//General web service information
$hashTableMap=$oMap->web->metadata;
$hashTableMap->set("wms_srs","EPSG:" . $epsgCode); 
$hashTableMap->set("wms_abstract","A Web Map service which will return choropleth maps coloured according to user-specified thresholds."); 

// Build up an array of classes - make this more configurable but for now, default to 3, with prespecified colours

      $WMSLayer=array(
            "set"=>array(
                "name"=>"dynamic_WMS_layer",
      			    "data"=>$dataString),

                "metadata"=>array(
      				    "ows_title"=>"WMS PostGIS dynamic server",
                	"ows_srs"=>"EPSG:" . $epsgCode,
                  "wms_format"=>"image/png"
                   // ,"ows_extent"=>$llx . $lly . $urx . $ury
                  ),
                  // TODO - get this from request parameters as above
                  // Dark red - least protected (default 0-5%)
                  // Red - partially protected (default 5-10%)
                  // Amber - below target (default 10-17%)
                  // Green - meeting target (default over 17%)
                  // TODO make this more dynamic
      			      "classes"=>array(
                    array("name"=>"Least protected", "style_line"=>array(100,0,0), "style_fill"=>array(100,0,0), "expression" =>"([percentage_protected_worldwide] < " . $thr1 . ")"),
                    array("name"=>"Partially protected", "style_line"=>array(255,0,0), "style_fill"=>array(255,0,0), "expression" =>"([percentage_protected_worldwide] >= " . $thr1 . " && [percentage_protected_worldwide] < " . $thr2 . ")"),
                    array("name"=>"Below target", "style_line"=>array(243,174,0), "style_fill"=>array(243,174,0), "expression" =>"([percentage_protected_worldwide] >= " . $thr2 . " && [percentage_protected_worldwide] < " . $thr3 . ")"),
                    array("name"=>"Meeting targets", "style_line"=>array(153,255,51), "style_fill"=>array(153,255,51), "expression" =>"([percentage_protected_worldwide] > " . $thr3 . ")")
                    )
                  
              );

      $oMap=UpdateLayer($oMap,$WMSLayer, $epsgString);

ms_ioinstallstdouttobuffer(); //if added before the warnings and error are outputed to the image buffer

$oMap->owsdispatch($request);
$contenttype = ms_iostripstdoutbuffercontenttype();

//image content
if ( strstr($contenttype, 'image'))  {
  $contenttype=explode("/",$contenttype);
  header("Cache-Control: public, must-revalidate\n");
  header("Expires: Mon, 26 Jul 1997 05:00:00 GMT\n");
  header("Last-Modified: " . gmdate( "D, d M Y H:i:s" ) . " GMT"."\n");
  header("Cache-Control: post-check=0, pre-check=0\n", false );
  header("Pragma: hack\n"); //IE hack
  header("Content-type: ".$contenttype[0]."/".$contenttype[1]."\n");
  header("Content-Disposition: attachment; filename=test.".$contenttype[1]."\n");
  ms_iogetStdoutBufferBytes();
}
//XML or text content
else {
  $buffer = ms_iogetstdoutbufferstring();

  header("Cache-Control: public, must-revalidate\n");
  header("Expires: Mon, 26 Jul 1997 05:00:00 GMT\n");
  header("Last-Modified: " . gmdate( "D, d M Y H:i:s" ) . " GMT"."\n");
  header("Cache-Control: post-check=0, pre-check=0\n", false );
  header("Pragma: hack\n"); //IE hack
  header("Content-type: text/xml\n");
  //echo($buffer);
  echo $buffer;
}
ms_ioresethandlers();

?>
