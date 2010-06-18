<?php
//set variables
$settings = array(
	"name" => "Radio Liefde",
	"genre" => "Romance",
	"url" => $_SERVER["SCRIPT_URI"],
	"bitrate" => 96,
	"music_directory" => "music/",
	"database_file" => "music.db",
	"buffer_size" => 16384,
	"max_listen_time" => 14400,
	"randomize_seed" => 31337
);

set_time_limit(0);
require_once("getid3/getid3.php");
$getID3 = new getID3;

//load playlist
if(!file_exists($settings["database_file"])) {
	$filenames = array_slice(scandir($settings["music_directory"]), 2);
	
	foreach($filenames as $filename) {
		$id3 = $getID3->analyze($settings["music_directory"].$filename);
		if($id3["fileformat"] == "mp3") {
			$playfile = array(
				"filename" => $id3["filename"],
				"filesize" => $id3["filesize"],
				"playtime" => $id3["playtime_seconds"],
				"audiostart" => $id3["avdataoffset"],
				"audioend" => $id3["avdataend"],
				"audiolength" => $id3["avdataend"] - $id3["avdataoffset"],
				"artist" => $id3["tags"]["id3v2"]["artist"][0],
				"title" => $id3["tags"]["id3v2"]["title"][0]
			);
			if(empty($playfile["artist"]) || empty($playfile["title"]))
				list($playfile["artist"], $playfile["title"]) = explode(" - ", substr($playfile["filename"], 0 , -4));
			$playfiles[] = $playfile;
		}
	}

	file_put_contents($settings["database_file"], serialize($playfiles));
} else {
	$playfiles = unserialize(file_get_contents($settings["database_file"]));
}

//user agents
$icy_data = false;
foreach(array("iTunes", "VLC", "Winamp") as $agent)
	if(substr($_SERVER["HTTP_USER_AGENT"], 0, strlen($agent)) == $agent)
		$icy_data = true;

//set playlist
$start_time = microtime(true);
srand($settings["randomize_seed"]);
shuffle($playfiles);

//sum playtime
foreach($playfiles as $playfile)
	$total_playtime += $playfile["playtime"];

//calculate the current song
$play_pos = $start_time % $total_playtime;
foreach($playfiles as $i=>$playfile) {
	$play_sum += $playfile["playtime"];
	if($play_sum > $play_pos)
		break;
}
$track_pos = ($playfiles[$i]["playtime"] - $play_sum + $play_pos) * $playfiles[$i]["audiolength"] / $playfiles[$i]["playtime"];

//output headers
header("Content-type: audio/mpeg");
if($icy_data) {
	header("icy-name: ".$settings["name"]);
	header("icy-genre: ".$settings["genre"]);
	header("icy-url: ".$settings["url"]);
	header("icy-metaint: ".$settings["buffer_size"]);
	header("icy-br: ".$settings["bitrate"]);
	header("Content-Length: ".$settings["max_listen_time"] * $settings["bitrate"] * 128); //suppreses chuncked transfer-encoding
}

//play content
$o = $i;
$old_buffer = substr(file_get_contents($settings["music_directory"].$playfiles[$i]["filename"]), $playfiles[$i]["audiostart"] + $track_pos, $playfiles[$i]["audiolength"] - $track_pos);
while(time() - $start_time < $settings["max_listen_time"]) {
	$i = ++$i % count($playfiles);
	$buffer = $old_buffer.substr(file_get_contents($settings["music_directory"].$playfiles[$i]["filename"]), $playfiles[$i]["audiostart"], $playfiles[$i]["audiolength"]);
		
	for($j = 0; $j < floor(strlen($buffer) / $settings["buffer_size"]); $j++) {
		if($icy_data) {
			if($i == $o + 1 && ($j * $settings["buffer_size"]) <= strlen($old_buffer))
				$payload = "StreamTitle='{$playfiles[$o]["artist"]} - {$playfiles[$o]["title"]}';".chr(0);
			else
				$payload = "StreamTitle='{$playfiles[$i]["artist"]} - {$playfiles[$i]["title"]}';".chr(0);

			$metadata = chr(ceil(strlen($payload) / 16)).$payload.str_repeat(chr(0), 16 - (strlen($payload) % 16));
		}
		echo substr($buffer, $j * $settings["buffer_size"], $settings["buffer_size"]).$metadata;
	}
	$o = $i;
	$old_buffer = substr($buffer, $j * $settings["buffer_size"]);
}
?>