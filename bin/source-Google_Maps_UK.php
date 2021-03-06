<?php
//this file is designed to be used as an include that is part of a loop.
//If a valid match is found, it should give $caller_id a value
//available variables for use are: $thenumber
//retreive website contents using get_url_contents($url);

//configuration / display parameters
//The description cannot contain "a" tags, but can contain limited HTML. Some HTML (like the a tags) will break the UI.
$source_desc = "http://maps.google.co.uk - 	These listings include business data for the UK.<br><br>This data source requires Superfecta Module version 2.2.4 or higher.";


//run this if the script is running in the "get caller id" usage mode.
if($usage_mode == 'get caller id')
{
	$number_error = true;

	if($debug)
	{
		print "Searching maps.google.co.uk ... ";
	}
	
	// Validate number
	// if($match = preg_match("/(01[2-6|9]1[0-9]{7})|(011[3-8][0-9]{7})|(012[2-3][3-9][0-9]{6})|(012[6|7][0-9]{7})|(0120[0|2|4-9][0-9]{6})|(0124[1-6|8|9][0-9]{6})|(0125[0|2-9][0-9]{6})|(0128[0|2-9][0-9]{6})|(0129[0-9]{7})|(013[2-3|5][0|2-9][0-9]{6})|(013[4|6][0-9]{7})|(0130[0-9]{7})|(0135[0|2-9][0-9]{6})|(0136[0-9]{7})|(0137[1-3|5-7|9][0-9]{6})|(0138[0-4|6-9][0-9]{6})|(0139[2|4-5|7-8][0-9]{6})|(014[6-7][0-9]{7})|(0140[0|3-9][0-9]{6})|(0142[0|2-9][0-9]{6})|(0143[0-9]{7})|(0144[0|2-6|9][0-9]{6})|(01449[0-9]{6})|(0145[0-8][0-9]{6})|(0148[0-5|7-9][0-9]{6})|(0149[0-7|9][0-9]{6})|(0150[1-3|5-9][0-9]{6})|(0152[0|2|4-9][0-9]{6})|(0153[0-1|4-6|8-9][0-9]{6})|(0154[0|2-9][0-9]{6})|(0155[0|3-9][0-9]{6})|(0156[0-9]{7})|(0157[0-3|5-9][0-9]{6})|(0158[0-4|6|8][0-9]{6})|(0159[0-9]{7})|(0160[0|3-4|6|8-9][0-9]{6})|(0162[0-6|8-9][0-9]{6})|(0163[0-1|3-9][0-9]{6})|(0164[1-4|6-7][0-9]{6})|(0165[0-6|9][0-9]{6})|(0166[1|3-9][0-9]{6})|(0167[0-8][0-9]{6})|(0168[0-1|3-9][0-9]{6})|(0169[0-2|4-5|7-8][0-9]{6})|(017[5|6][0-9][0-9]{6})|(0170[0|2|4|6-9][0-9]{6})|(0172[0-9][0-9]{6})|(0173[0|2-3|6-8][0-9]{6})|(0174[0|3-9][0-9]{6})|(0177[0-3|5-9][0-9]{6})|(0178[0|2|4-9][0-9]{6})|(0179[0|2-9][0-9]{6})|(0180[3|5-9][0-9]{6})|(0182[1-5|7-9][0-9]{6})|(0183[0|2-8][0-9]{6})|(0184[0-8][0-9]{6})|(0185[1-2|4-9][0-9]{6})|(0186[2-6|9][0-9]{6})|(0187[0-9]{7})|(0188[0|2-9][0-9]{6})|(0189[0|2|5-6|9][0-9]{6})|(0190[0|2-5|8-9][0-9]{6})|(0192[0|2-6|8-9][0-9]{6})|(0193[1-5|7-9][0-9]{6})|(0194[2-9][0-9]{6})|(0195[0-5|7|9][0-9]{6})|(0196[2-4|7-9][0-9]{6})|(0197[0-2|4-5|7-8][0-9]{6})|(0198[0-9]{7})|(0199[2-5|7][0-9]{6})|(02[0|3|4|8|9][0-9]{8})|(02[0|3|4|8|9][0-9]{8})/",$thenumber)){
	if($match = match_pattern("0[123456]XXXXXXXXX",$thenumber)){		
		// Land line
		$number_error = false;
	}elseif($match = match_pattern("0[789]XXXXXXXXX",$thenumber)){
		// Mobile number or Premium Rate
		$number_error = false;
	}
	
	if($number_error)
	{
		if($debug)
		{
			print "Skipping Source - Non UK number: {$thenumber}<br>\n";
		}
	}
	else
	{
		if($debug)
		{
			print "Searching maps.google.co.uk for number: {$thenumber}<br>\n";
		}
		// By default, the found name is empty
		$name = "";

		// We'll be searching google maps
		$url = "http://maps.google.co.uk/m?q=%22{$thenumber}%22";
		$value = get_url_contents($url);

		// Grab the first result from google maps that matches our phone number
		$pattern = "/<a class=\"uf\" href=\"[^\"]+\" *>([^<]+)<\/a>[^<]<a href=\"[^\"]+\" *><img class=\"t3h5iu\" src=\"[^\"]+\" *[^<]+<\/a> <\/div>[^<]*<div class=\"[^\"]*\">[^<]*<\/div>[^<]*<div><a class=\"[^\"]*\" href=\"tel:{$thenumber}\" *>/";
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
