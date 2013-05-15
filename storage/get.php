<?php

require_once("./common.php");
error_reporting(0);
ob_start();

$chunk_size = 3600;

$filename   = basename($_GET['filename']);
$ch_id      = intval($_GET['ch_id']);
$start_time = intval($_GET['start']);
$duration   = intval($_GET['duration']);

$queue = array();

if (empty($filename) || empty($duration)){
    header("HTTP/1.0 400 Bad Request");
    exit;
}

$file = RECORDS_DIR."archive"."/".$ch_id.'/'.$filename;

if (!file_exists($file) || !is_readable($file)){
    header("HTTP/1.0 404 Not Found");
    exit;
}

while ($duration > 0){

    $filesize  = filesize($file);
    $from_byte = intval($start_time * $filesize / $chunk_size);

    if (($duration + $start_time) >= $chunk_size){
        $to_byte    = $filesize;
        $duration  -= $chunk_size - $start_time;
        $start_time = 0;
    }else{
        $to_byte  = intval(($start_time + $duration) * $filesize / $chunk_size);
        $duration = 0;
    }

    $queue[] = array(
        "filename"  => $file,
        "from_byte" => $from_byte,
        "to_byte"   => $to_byte,
        "size"      => $to_byte - $from_byte
    );

    $start_time = 0;
    $file = get_next_file($file);
}

$size = get_content_length($queue);

_log("\nTotal size: ".$size);

if (isset($_SERVER['HTTP_RANGE'])){

    list($size_unit, $range_orig) = explode('=', $_SERVER['HTTP_RANGE'], 2);

    if ($size_unit == 'bytes'){
        list($range, $extra_ranges) = explode(',', $range_orig, 2);
        _log("Range: ".$range_orig);
    }else{
        $range = '';
    }
}else{
    $range = '';
}

list($seek_start, $seek_end) = explode('-', $range, 2);

$seek_end   = (empty($seek_end)) ? ($size - 1) : min(abs(intval($seek_end)),($size - 1));
$seek_start = (empty($seek_start) || $seek_end < abs(intval($seek_start))) ? 0 : max(abs(intval($seek_start)),0);

if (isset($_SERVER['HTTP_RANGE'])){
    header('HTTP/1.1 206 Partial Content');
}
header("Content-Type: video/mpeg");
header('Content-Length: '.($seek_end - $seek_start + 1));
header('Content-Range: bytes '.$seek_start.'-'.$seek_end.'/'.$size);

$offset = 0;

_log("Queue length: ".count($queue));

foreach ($queue as $item){

    _log("File: ".$item["filename"]);
    _log("Size: ".$item["size"]);
    _log("Start: ".$seek_start);
    _log("End:  ".$seek_end);
    _log("From Byte:  ".$item["from_byte"]);
    _log("To Byte:  ".$item["to_byte"]);
    _log("Filesize: ".filesize($item["filename"]));
    _log("Offset: ".$offset);

    if (($offset + $item["from_byte"] + $seek_start) <= ($offset + filesize($item["filename"]))){

        $fp = fopen($item["filename"], 'rb');
        //fseek($fp, $item["from_byte"]);
        //$seek = $seek_start - $offset;
        fseek($fp, $item["from_byte"] + $seek_start);

        _log("Seek: ".($item["from_byte"] + $seek_start));

    }else{
        //$offset += $item["size"];
        $offset     += filesize($item["filename"]);
        $seek_start -= filesize($item["filename"]) - $item["from_byte"];
        continue;
    }

    set_time_limit(0);

    while(!feof($fp)){

        $buf_size = 1024*8;
        $pos      = ftell($fp);

        if ($pos >= $item["to_byte"]){
            _log("File close: ".$item["filename"]." on pos: ".$pos);
            fclose($fp);
            break;
        }

        if ($pos + $buf_size > $item["to_byte"]){
            $buf_size = $item["to_byte"] - $pos;
        }

        if ($buf_size > 0){
            echo fread($fp, $buf_size);
        }

        flush();
        ob_flush();
    }
    
    if (is_resource($fp)){
        _log("Close file resource");
        fclose($fp);
    }

    //$offset += $item["size"];
    $offset     += filesize($item["filename"]);
    $seek_start  = 0;
} 

function get_next_file($file){

    $filename = basename($file);
    $filename = substr($filename, 0, strpos($filename, "."));

    $filedate = $filename.":00:00";
    $filedate = str_replace("-", " ", $filedate);
    $filedate = strtotime($filedate." +1 hour");

    return str_replace($filename, date("Ymd-H", $filedate), $file);
}

function get_content_length($queue){

    $length = 0;

    foreach ($queue as $item){
        $length += $item["size"];
    }

    return $length;
}

function _log($message){
    //$stderr = fopen('php://stderr', 'w');
    //fwrite($stderr, $message."\n");
    //fclose($stderr);
}
?>