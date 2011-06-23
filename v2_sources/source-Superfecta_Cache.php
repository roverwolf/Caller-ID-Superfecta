<?php
//this file is designed to be used as an include that is part of a loop.
//If a valid match is found, it should give $caller_id a value
//available variables for use are: $thenumber
//retreive website contents using get_url_contents($url);

//configuration / display parameters
//The description cannot contain "a" tags, but can contain limited HTML. Some HTML (like the a tags) will break the UI.
$source_desc = "Searches the built in Superfecta Cache for caller ID information. This is a very fast source of information.<br>This source is 
generally faster than the Asterisk Phonebook, and more efficient with larger caches.<br><br>Module version 2.2.4 or higher, for CID Rules.";
$source_param = array();
$source_param['Cache_Timeout']['desc'] = 'Specify the number of days that a cached value should stay in the database.';
$source_param['Cache_Timeout']['type'] = 'number';
$source_param['Cache_Timeout']['default'] = 120;
$source_param['CID_Exclusion_Rules']['desc'] = '
	Incoming calls with CID matching the patterns specified here will not be cached. If this is 
	left blank, all CIDs will be cached. <br />
	<br /><b>Rules:</b><br /> 
	<strong>X</strong>&nbsp;&nbsp;&nbsp; matches any digit from 0-9<br /> 
	<strong>Z</strong>&nbsp;&nbsp;&nbsp; matches any digit from 1-9<br /> 
	<strong>N</strong>&nbsp;&nbsp;&nbsp; matches any digit from 2-9<br /> 
	<strong>[1237-9]</strong>&nbsp;	 matches any digit or letter in the brackets (in this 
		example, 1,2,3,7,8,9)<br /> 
	<strong>.</strong>&nbsp;&nbsp;&nbsp; wildcard, matches one or more characters (not 
		allowed before a | or +)<br /> 
	<strong>|</strong>&nbsp;&nbsp;&nbsp; removes a dialing prefix from the number (for 
		example, 613|NXXXXXX would match when some one dialed "6135551234" but would only 
		pass "5551234" to the Superfecta look up.)<br />
	<strong>+</strong>&nbsp;&nbsp;&nbsp; adds a dialing prefix to the number (for 
		example, 1613+NXXXXXX would match when someone dialed "5551234" 
		and would pass "16135551234" to the Superfecta look up.)<br /><br /> 
 
	You can also use both + and |, for example: 01+0|1ZXXXXXXXXX would match 
	"016065551234" and dial it as "0116065551234" Note that the order does not matter, eg. 
	0|01+1ZXXXXXXXXX does the same thing.';
$source_param['CID_Exclusion_Rules']['type'] = 'textarea';
$source_param['CID_Exclusion_Rules']['default'] = '';

//run this if the script is running in the "get caller id" usage mode.
if($usage_mode == 'get caller id')
{
	// Compatbility for pre 2.2.4
	if(function_exists("match_pattern_all")){ 
		$rule_match = match_pattern_all(isset($run_param['CID_Exclusion_Rules'])?$run_param['CID_Exclusion_Rules']:'' , $thenumber);
	}else{
		if($debug){
			print "Cache CID rules require Superfecta 2.2.4 or higher.<br>\n";
		}
		$rule_match = array('status'=>false,'number'=>false);
	}
	if((!$rule_match['status']) || (!$rule_match['number'])){
		if($debug)
		{
			print "Searching Superfecta Cache ... ";
		}
		
		//clear old cache
		$sql = "DELETE FROM superfectacache WHERE dateentered < DATE_SUB(NOW(),INTERVAL ".(isset($run_param['Cache_Timeout'])?$run_param['Cache_Timeout']:$source_param['Cache_Timeout']['default'])." DAY)";
		$db->query($sql);
		
		//query cache
		$sql = "SELECT callerid FROM superfectacache WHERE number = '$thenumber'";
		$sres = $db->getOne($sql);
		if (DB::IsError($sres))
		{
			die_freepbx( "Error: " . $sres->getMessage() .  "<br>");
		}
		
		//check to see if there is a valid return and that it's not numeric
		if(($sres != '') && !is_numeric($sres))
		{
			$caller_id = $sres;
			$cache_found = true;
		}
		else if($debug)
		{
			print "not found<br>\n";
		}
	}elseif($debug){
		print "Matched cache exclusion rule: '".$rule_match['pattern']."' with: '".$rule_match['number']."'<br>\nSkipping cache 
lookup.<br>\n";
	}
}

if($usage_mode == 'post processing')
{
	// Compatbility for pre 2.2.4
	if(function_exists("match_pattern_all")){ 
		$rule_match = match_pattern_all(isset($run_param['CID_Exclusion_Rules'])?$run_param['CID_Exclusion_Rules']:'' , $thenumber);
	}else{
		$rule_match = array('status'=>false,'number'=>false);
	}
	if((!$rule_match['status']) || (!$rule_match['number'])){
		if(!$cache_found && ($first_caller_id != ''))
		{
			$sql = "REPLACE INTO superfectacache (number,callerid,dateentered)
							VALUES($thenumber,'$first_caller_id',NOW())";
			$db->query($sql);
			if($debug)
			{
				print "Caller ID data added to cache.<br>\n<br>\n";
			}
		}
	}elseif($debug){
		print "Matched cache exclusion rule: '".$rule_match['pattern']."' with: '".$rule_match['number']."'<br>\nSkipping cache 
storage.<br>\n";
	}
}
?>
