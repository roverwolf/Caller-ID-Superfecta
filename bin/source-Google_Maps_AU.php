<?php
//this file is designed to be used as an include that is part of a loop.
//If a valid match is found, it should give $caller_id a value
//available variables for use are: $thenumber
//retreive website contents using get_url_contents($url);

//configuration / display parameters
//The description cannot contain "a" tags, but can contain limited HTML. Some HTML (like the a tags) will break the UI.
$source_desc = "http://maps.google.com.au - 	These listings include business data for AU<br><br>This data source requires Superfecta Module version 2.2.4 or higher.";


//run this if the script is running in the "get caller id" usage mode.
if($usage_mode == 'get caller id')
{
	$number_error = false;

	if($debug)
	{
		print "Searching maps.google.com.au ... ";
	}
	
	// Validate number
	if($match = match_pattern("0[2356789]XXXXXXXX",$thenumber)){
		// Land line
		$num1 = substr($thenumber,0,2);
		$num2 = substr($thenumber,2,4);
		$num3 = substr($thenumber,6,4);
		$fullnum = "(".$num1.") ".$num2." ".$num3;

	}elseif($match = match_pattern("04XXXXXXXX",$thenumber)){
		// Mobile number
		$num1 = substr($thenumber,0,4);
		$num2 = substr($thenumber,4,3);
		$num3 = substr($thenumber,7,3);
		$fullnum = $num1." ".$num2." ".$num3;
	}else{
		$number_error = true;
	}
	
	if($number_error)
	{
		if($debug)
		{
			print "Skipping Source - Non AU number: ".$thenumber."<br>\n";
		}
	}
	else
	{
		// By default, the found name is empty
		$name = "";
		// We'll be searching google maps
		$url = "http://maps.google.com.au/m?q=%22".$num1."+".$num2."+".$num3."%22";
		$value = get_url_contents($url);

		// Grab the first result from google maps that matches our phone number
		$pattern = "/<a class=\"uf\" href=\"[^\"]+\" *>([^<]+)<\/a>[^<]*<\/div>[^<]*<div class=\"[^\"]*\">[^<]*<\/div>[^<]*<div><a class=\"[^\"]*\" href=\"tel:".$thenumber."\" *>/";
		preg_match($pattern, $value, $match);
		if(isset($match[1]) && strlen($match[1])){
			$name = trim(strip_tags($match[1]));
		}
		// If we found a match, return it
		if(strlen($name) > 1)
		{
			$caller_id = $name;
		}
		else if($debug)
		{
			print "not found<br>\n";
		}
	}
}
?>
