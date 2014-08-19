<?php
function AddLayer($oMap,$LayerArray)
{
    /**
     * Generic function that adds a Layer to the Map object based on a description array.
     * The the set(), setMetaData(), setConnectionType() will be used to set the layer's properties
     * aside from the class and style objects
     *
     * INPUT: Map object, Array with layer description, extent, srs, style (TODO), name of geotiff
     * OUTPUT: Map object with layer
     *
     * EPSG:900913 is necessary for overlaying with commercial API see: http://docs.openlayers.org/library/spherical_mercator.html#mapserver
     *
     * Attention to the scale values of mapserver ("SCALE=0,21") automatic scaling is time consuming
     * 
     * Extensively based on Jorge's code: edited January 2011 by L. Bastin to allow dynamic selection of images / shapefiles
     */

    $oLayer=ms_newLayerObj($oMap);
    $oLayer->offsite->setRGB(0,0,0);
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

    //Connection type
    if (array_key_exists('setconnectiontype', $LayerArray)) {
    //if ($LayerArray["setconnectiontype"]){  // This test will throw an error in the Apache logs - ignore it.
        $oLayer->setConnectionType($LayerArray["setconnectiontype"]);
    };
    //Class object
    //class of layer with line color
    if (array_key_exists('class', $LayerArray)) {
    
      $ClassArray=$LayerArray["class"];  // This test (for existence) will throw an error in the Apache logs - ignore it.

        $oClass=ms_newClassObj($oLayer);
        $oClass->set("name",$ClassArray["name"]);
       
        $style = ms_newStyleObj($oClass);
        
        $style->outlinecolor->setRGB( $ClassArray["style_line"][0],$ClassArray["style_line"][1],$ClassArray["style_line"][2] );
        $style->color->setRGB( $ClassArray["style_fill"][0],$ClassArray["style_fill"][1],$ClassArray["style_fill"][2] );
    };
    //Raster processing (band coloring etc)
    $ProcessingArray=$LayerArray["processing"];
    if ($ProcessingArray){
        foreach ($ProcessingArray as $v){
            $oLayer->setProcessing($v);
        }
    }
    return($oMap);


}; //end of function

function cleanParamValue($r, $inString)
{
  $retVal = isset($r[strtoupper($inString)]) ? $r[strtoupper($inString)] : $r[$inString];
  return trim($retVal);
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

//$config = parse_ini_file('../config/config.ini', 1);
//$errorfile = $config['mapserver']['errorfile'];
//$shapedir = $config['mapserver']['shapedir'];
//$imagepath = $config['mapserver']['imagepath'];

//Creation of the map obect
$oMap=ms_newMapObjFromString("MAP END");
$oMap->set("name","test_MS_raster");
$oMap->set("debug",MS_OFF);
//shapfile locations
$oMap->set("shapepath","/");
//$oMap->set("shapepath","/srv/www/htdocs/mstmp/");

$datafile = cleanParamValue($_REQUEST,'data');

$coords = explode(",", cleanParamValue($_REQUEST,'bbox'));
$llx = (float)$coords[0];
$lly = (float)$coords[1];
$urx = (float)$coords[2];
$ury = (float)$coords[3];

$epsg = "+init=epsg:" . cleanParamValue($_REQUEST,'epsg');

$RGB = cleanParamValue($_REQUEST,'rgb');

$proc=null;
if (strtoupper(cleanParamValue($_REQUEST,'autoscale')) === 'YES') {
  // TODO - work out how to ignore certain values - (for scaling striped images) - tried "NODATA=0,0,0" but it didn't work
  $proc = array("NODATA=0", "BANDS=" . $RGB, "SCALE_1=AUTO", "SCALE_2=AUTO", "SCALE_3=AUTO");
} else {
  $proc = array("BANDS=" . $RGB);
}

//$w = $_REQUEST["WIDTH"];
//$h = $_REQUEST["HEIGHT"];
//$oMap->setSize($w,$h); // display size in window

$oMap->set("status", MS_ON);

$oMap->setExtent($llx,$lly,$urx,$ury);

// The MS_TRUE will sets the units (and extent?) to EPSG code, no need for setting units
$oMap->setProjection($epsg, MS_TRUE);

$oMap->web->set ("imagepath", "/srv/www/htdocs/mstmp/"); // web image directory
$oMap->web->set ("imageurl", "/mstmp/"); //web url directory

$oMap->setConfigOption('MS_ERRORFILE', '/tmp/ms_pais.log');
$oMap->setConfigOption('MS_ENCRYPTION_KEY', "/home/webuser/mapfiles/key.txt");

//General web service information
// THIS ERRORS IN MAPSERVER 6!! because the map->web->metadata command returns a webObj, NOT the hashtable
// see bug report here... https://trac.osgeo.org/mapserver/ticket/3971
// TODO update Mapserver when safe to do so.
//$hashTableMap=$oMap->web->metadata;
//$hashTableMap->set("ows_enable_request", "*");
//$hashTableMap->set("ows_title","Demo server");
//$hashTableMap->set("ows_onlineresource","http://h03-dev-vm3.jrc.it/mapserver_utils/raster_WMS.php?");
//$hashTableMap->set("ows_srs","EPSG:" . $_REQUEST["epsg"]); 

// INSTEAD, do....
$oMap->setMetadata("ows_enable_request", "*");
$oMap->setMetadata("ows_title","Demo server");
$oMap->setMetadata("ows_onlineresource","http://h03-dev-vm3.jrc.it/mapserver_utils/raster_WMS.php");
$oMap->setMetadata("ows_srs","EPSG:" . $_REQUEST["epsg"]);

      //Raster example
      $Layer=array(
        "set"=>array(
             "status" => MS_ON,
             "name"=>"rasterLayer",
  
             "data"=> "/rasterdata/data/pais/" . $datafile,
            "dump"=>MS_TRUE,
             "type"=>MS_LAYER_RASTER
             ),

          "metadata"=>array("ows_srs"=>"EPSG:" . $_REQUEST["epsg"],
             "wms_format"=>"image/png",
              "ows_extent"=>$llx . $lly . $urx . $ury,
             "ows_title"=>"PAIS imagery"),

           "processing"=>$proc
           //"processing"=>array("BANDS=" . $RGB, "SCALE_1=AUTO", "SCALE_2=AUTO", "SCALE_3=AUTO")
          //"processing"=>array("BANDS=" . $RGB)
       );
       
    $oMap=AddLayer($oMap,$Layer);

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
