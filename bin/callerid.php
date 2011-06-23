<?php
/***
TODO:  Move all DB, FreeBPX and Asterisk specific operations into functions

Original script by Nerd Vittles. (Google for Caller Id Trifecta)
	03/12/2009	Put into module format by Tony Shiffer & Jerry Swordsteel
			commented out fixed variables.  Added db code to get values from db.
	03/30/2009  	New SugarCRM by jpeterman is now included.
	04/08/2009  	Removed dependancy on default id/pw for db connection. now parses amportal.conf
	05-08-2009	Version 2.0.0  Major update - Tickets: Tickets: #7, #10, #15, #17, #36, and #19.(projects.colsolgrp.net)(jjacobs)
	08-18-2009	Version 2.2.0  CID Schemes and online update for data sources (projects.colsolgrp.net)(jjacobs)
	10-26-2009  	Version 2.2.2  http://projects.colsolgrp.net/versions/show/55 (projects.colsolgrp.net) (patrick_elx)
	01-03-2010  	Version 2.3.0  Updates to remove need for Caller ID Lookup module
	01-04-2010  	Version 2.3.0  Updates for running multiple sources at the same time (Multifecta)
***/

$debug_val = (isset($_REQUEST['debug'])) ? $_REQUEST['debug'] : '';
$debug = ($debug_val == 'yes') ? true : false;
//$debug = true;
if($debug){
	// If debugging, report all errors
	error_reporting(-1);
	ini_set('display_errors', '1');
}

$caller_id = '';
$charsetIA5 = true;
$first_caller_id = '';
$prefix = '';
$spam_text = '';
$cache_found = false;
$single_source = false;
$spam = false;
$winning_source = '';
$usage_mode = 'get caller id';
$src_array = array();
$multifecta_id = false;
$multifecta_parent_id = false;
$thenumber_orig = (isset($_REQUEST['thenumber'])) ? trim($_REQUEST['thenumber']) : '';
$DID = (isset($_REQUEST['testdid'])) ? trim($_REQUEST['testdid']) : '';
$scheme = (isset($_REQUEST['scheme'])) ? trim($_REQUEST['scheme']) : '';

if(($thenumber_orig == '') && isset($argv[1]) && ($argv[1] != '-multifecta_id')){
	$thenumber_orig = $argv[1];
}elseif(($DID == '') && isset($argv[2]) && ($argv[1] != '-multifecta_id')){
	$DID = $argv[2];
}elseif(isset($argv[1]) && ($argv[1] == '-multifecta_id') && isset($argv[2])){
	$multifecta_id = $argv[2];
}

//New code for FreePBX 2.9 -- Andrew Nagy (tm1000)
if(file_exists("/etc/freepbx.conf")) {
	//This is FreePBX 2.9+
	if($debug) {
		echo "<br/><strong>Detected FreePBX version is at least 2.9</strong><br/>";
	}
	require("/etc/freepbx.conf");
	global $db,$amp_conf;
} elseif(file_exists("/etc/asterisk/freepbx.conf")) {
	//This is FreePBX 2.9+
	if($debug) {
		echo "<br/><strong>Detected FreePBX version is at least 2.9</strong><br/>";
	}
	require("/etc/asterisk/freepbx.conf");
	global $db,$amp_conf;	
} else {
	//This is > FreePBX 2.8
	if($debug) {
		echo "<br/><strong>Detected FreePBX version is at most 2.8</strong><br/>";
	}
	
	require_once("../../../functions.inc.php");
	
	require_once 'DB.php';
	define("AMP_CONF", "/etc/amportal.conf");

	$amp_conf = parse_amportal_conf(AMP_CONF);
	if(count($amp_conf) == 0)
	{
		fatal("FAILED");
	}

	$dsn = array(
	    'phptype'  => 'mysql', // Looks like we are assuming mysql  -- is this safe? (jkiel - 01/04/2011)
	    'username' => $amp_conf['AMPDBUSER'],
	    'password' => $amp_conf['AMPDBPASS'],
	    'hostspec' => $amp_conf['AMPDBHOST'],
	    'database' => $amp_conf['AMPDBNAME'],
	);
	$options = array();
	$db =& DB::connect($dsn, $options);
	if(PEAR::isError($db))
	{
		die($db->getMessage());
	}

	//connect to the asterisk manager
	require_once('../../../common/php-asmanager.php');
	$astman	= new AGI_AsteriskManager();	
}
//End new FreePBX 2.9 code.

//Check if we are a multifecta child, if so, get our variables from our child record
if($multifecta_id){
	$query  = "SELECT mf.superfecta_mf_id, mf.scheme, mf.cidnum, mf.extension, mf.debug, mfc.source
			FROM superfecta_mf mf, superfecta_mf_child mfc
			WHERE mfc.superfecta_mf_child_id = " . $db->quoteSmart($multifecta_id) . "
			AND mf.superfecta_mf_id = mfc.superfecta_mf_id";

	$res = $db->query($query);
	if (DB::IsError($res)){
		die("Unable to load child record: " . $res->getMessage() .  "<br>");
	}
	if($row = $res->fetchRow(DB_FETCHMODE_ASSOC)){
		
		$scheme = $row['scheme'];
		$thenumber_orig = $row['cidnum'];
		$DID = $row['extension'];
		$multifecta_parent_id = $row['superfecta_mf_id'];
		if($row['debug']){
			$debug = true;
		}
		$single_source = $row['source'];
	}else{
		die("Unable to load multifecta child record '".$multifecta_id."'");
	}
}

if($debug)
{
	$start_time_whole = mctime_float();
	$end_time_whole = 0;
}

$param = array();
$query = "SELECT * FROM superfectaconfig";
$res = $db->query($query);
if (DB::IsError($res)){
	die("Unable to load scheme parameters: " . $res->getMessage() .  "<br>");
}
while ($row = $res->fetchRow(DB_FETCHMODE_ASSOC))
{
	$param[$row['source']][$row['field']] = $row['value'];
}

if($debug)
{
	print "Debugging Enabled, will not stop after first result.<br>\n";
}

//loop through schemes
$query = "SELECT source	FROM superfectaconfig WHERE field = 'order' AND value > 0";

if(($debug || $multifecta_id) && ($scheme != ""))
{
	$query .= " AND	source = " . $db->quoteSmart($scheme);
}
$query .= " ORDER BY value";
$res = $db->query($query);
if(DB::isError($res) && $debug)
{
	print 'The database query of:<br>'.$query.'<br>failed with an error of:<br>'.$res->getMessage();
}
else
{

	// Loop over each scheme
	while ($row = $res->fetchRow(DB_FETCHMODE_ASSOC))
	{
		$this_scheme = $row['source'];
		$run_this_scheme = true;
		$thenumber = ereg_replace('[^0-9]+', '', $thenumber_orig);

		if($debug)
		{
			print "<hr>Processing ".substr($this_scheme,5)." Scheme.<br>\n";
		}
		//trying to push some info to the CLI
		if(!$multifecta_id){
			//$astman->command('VERBOSE "Processing '.substr($this_scheme,5).' Scheme." 3');
		}


		// Determine if this is the correct DID, if this scheme is limited to a DID.

		$rule_match = match_pattern_all( (isset($param[$this_scheme]['DID'])) ? $param[$this_scheme]['DID'] : '', $DID );
		if($rule_match['number']){
			if($debug){print "Matched DID Rule: '".$rule_match['pattern']."' with '".$rule_match['number']."'<br>\n";}
		}elseif($rule_match['status']){
			if($debug){print "No matching DID rules.<br>\n";}
			$run_this_scheme = false;
		}


		// Determine if the CID matches any patterns defined for this scheme

		$rule_match = match_pattern_all((isset($param[$this_scheme]['CID_rules']))?$param[$this_scheme]['CID_rules']:'', $thenumber );
		if($rule_match['number'] && $run_this_scheme){
			if($debug){print "Matched CID Rule: '".$rule_match['pattern']."' with '".$rule_match['number']."'<br>\n";}
			$thenumber = $rule_match['number'];
		}elseif($rule_match['status'] && $run_this_scheme){
			if($debug){print "No matching CID rules.<br>\n";}
			$run_this_scheme = false;
		}

		// Run the scheme
		
		if($run_this_scheme)
		{
			if(!isset($param[$this_scheme]['enable_multifecta'])){
				$param[$this_scheme]['enable_multifecta'] = false;	
			}

			$curl_timeout = $param[$this_scheme]['Curl_Timeout'];

			//if a prefix lookup is enabled, look it up, and truncate the result to 10 characters
			if( (isset($param[$this_scheme]['Prefix_URL'])) && (trim($param[$this_scheme]['Prefix_URL']) != '') && (!$multifecta_id))
			{
				if($debug)
				{
					$start_time = mctime_float();
				}
				
				$prefix = get_url_contents(str_replace("[thenumber]",$thenumber,$param[$this_scheme]['Prefix_URL']));

				if($debug)
				{
					print "Prefix Url defined ...\n";
					if($prefix !='')
					{
						print 'returned: '.$prefix."<br>\n";
					}
					else
					{
						print "result was empty<br>\n";
					}
					print "result <img src='images/scrollup.gif'> took ".number_format((mctime_float()-$start_time),4)." seconds.<br>\n<br>\n";
				}
			}

			//run through the specified sources
			$src_array = explode(',',$param[$this_scheme]['sources']);
			$theoriginalnumber = $thenumber;

			// Check if we are a multifecta parent
			if(($param[$this_scheme]['enable_multifecta'])  && (!$multifecta_id)){
				if($debug){
					print "Multifecta enabled for " .substr($this_scheme,5)." scheme <br>\n";
				}

				// We are a multifecta parent

				$multifecta_start_time = mctime_float();

				// Clean up multifecta records that are over 10 minutes old
				$query = "DELETE mf, mfc FROM superfecta_mf mf, superfecta_mf_child mfc
						WHERE mf.timestamp_start < ".$db->quoteSmart($multifecta_start_time - (60*10))."
						AND mfc.superfecta_mf_id = mf.superfecta_mf_id
						";
				$res2 = $db->query($query);
				if (DB::IsError($res2)){
					die("Unable to delete old multifecta records: " . $res2->getMessage() .  "<br>");
				}

				// Prepare for launching children.

				$query = "INSERT INTO superfecta_mf (
						timestamp_start, 
						scheme, 
						cidnum, 
						extension, 
						prefix, 
						debug
					) VALUES (
						".$db->quoteSmart($multifecta_start_time).",
						".$db->quoteSmart($this_scheme).",
						".$db->quoteSmart($theoriginalnumber).",
						".$db->quoteSmart($DID).",
						".$db->quoteSmart($prefix).",
						".$db->quoteSmart(($debug)?'1':'0')."
					)";
				// Create the parent record
				$res2 = $db->query($query);
				if (DB::IsError($res2)){
					die("Unable to create parent record: " . $res2->getMessage() .  "<br>");
				}
				// (jkiel - 01/04/2011) Get id of the parent record 
				// (jkiel - 01/04/2011) [Insert complaints on Pear DB not supporting a last_insert_id method here]
				// (jkiel - 01/04/2011) What is the point of an abstraction layer when you are forced to bypass it?!?!?
				if($superfecta_mf_id = (($amp_conf["AMPDBENGINE"] == "sqlite3") ? sqlite_last_insert_rowid($db->connection) : mysql_insert_id($db->connection)))
				{
					// We have the parent record id
				}else{
					die("Unable to get parent record id<br>");
				}

			}
			require_once('superfecta_base.php');
			if ($theoriginalnumber !='')
			{
				$multifecta_count = 0;
				foreach($src_array as $source_name)
				{
				if(((!$single_source) || ($single_source == $source_name)) && ((!$param[$this_scheme]['enable_multifecta']) || ($multifecta_id))){
					// We are in non-multifecta mode, or a multifecta, single source, child.  Run this source now.
					$thenumber = $theoriginalnumber;
					$caller_id = '';
					if($debug)
					{
						$start_time = mctime_float();
					}
					$run_param = isset($param[substr($this_scheme,5).'_'.$source_name]) ? $param[substr($this_scheme,5).'_'.$source_name] : array();
					
					if(file_exists("source-".$source_name.".module")) {
						require_once("source-".$source_name.".module");
						$source_class = NEW $source_name;
						$source_class->db = $db;
						$source_class->debug = $debug;
						if(method_exists($source_class, 'get_caller_id')) {
							$caller_id = $source_class->get_caller_id($thenumber,$run_param);
							unset($source_class);
							$caller_id = _utf8_decode($caller_id);
							if(($first_caller_id == '') && ($caller_id != ''))
							{
								$first_caller_id = $caller_id;
								$winning_source = $source_name;
								if($debug)
								{
									$end_time_whole = mctime_float();
								}
							}
						} else {
							print "Function 'get_caller_id' does not exist!<br>\n";
						}
					} else {
						print "Unable to find source '".$source_name."' skipping..<br\>\n";
					}
	
					if($debug)
					{
						if($caller_id != '')
						{
							print "'" . utf8_encode($caller_id)."'<br>\nresult <img src='images/scrollup.gif'> took ".number_format((mctime_float()-$start_time),4)." seconds.<br>\n<br>\n";
						}
						else
						{
							print "result <img src='images/scrollup.gif'> took ".number_format((mctime_float()-$start_time),4)." seconds.<br>\n<br>\n";
						}
					}
					else if($caller_id != '')
					{
						break;
					}
				}elseif(($param[$this_scheme]['enable_multifecta']) && (!$multifecta_id)){
					// We are a Multifecta parent.  Get ready to spawn a child.
					$multifecta_child_start_time = mctime_float();
					$query = "INSERT INTO superfecta_mf_child (
								superfecta_mf_id,
								priority,
								source,
								timestamp_start
							) VALUES (
								".$db->quoteSmart($superfecta_mf_id).",
								".$db->quoteSmart($multifecta_count).",
								".$db->quoteSmart($source_name).",
								".$db->quoteSmart($multifecta_child_start_time)."
							)";
					// Create the child record
					$res2 = $db->query($query);
					if (DB::IsError($res)){
						die("Unable to create child record: " . $res2->getMessage() .  "<br>");
					}
					if($superfecta_mf_child_id = (($amp_conf["AMPDBENGINE"] == "sqlite3") ? sqlite_last_insert_rowid($db->connection) : mysql_insert_id($db->connection)))
					{
						// We have the child's id
						// Spawn the child
						if($debug){
							print "Spawning child $superfecta_mf_child_id: $source_name <br>\n";
						}
						exec('/usr/bin/php ' . (__FILE__) . ' -multifecta_id ' . $superfecta_mf_child_id . ' > /dev/null 2>&1 &');
						//exec('/usr/bin/php ' . (__FILE__) . ' -multifecta_id ' . $superfecta_mf_child_id . ' > log'.$superfecta_mf_child_id.' 2>&1 &');
					}else{
						die("Unable to get child record id<br>");
					}
					$multifecta_count ++;
				} // End if
				} // end foreach
			} 
			else
			{
			 	if($debug)
				{
					print "The CID '".$thenumber_orig."' did not contain number. Lookup stopped <br>";
				}
			}


			//$prefix = ($prefix != '') ? $prefix.':' : '';
			if($spam)
			{
				if(isset($param[$this_scheme]['SPAM_Text_Substitute']) && $param[$this_scheme]['SPAM_Text_Substitute'] == 'Y')
				{
					$first_caller_id = $param[$this_scheme]['SPAM_Text'];
				}
				elseif(!$spam_text)
				{
					$spam_text = $param[$this_scheme]['SPAM_Text'];
				}
			}
		}

		if($first_caller_id != '')
		{
			break;
		}
		// If we are a Multifecta parent, wait for our children to complete, 
		// or for one of our preferences to 'win', before moving on to the next scheme
		if(($theoriginalnumber !='') && $run_this_scheme && ($param[$this_scheme]['enable_multifecta']) && (!$multifecta_id) && ($multifecta_count)){

			if($debug){
				print "Parent took ".number_format((mctime_float()-$multifecta_start_time),4)." seconds to spawn children.<br>\n";
			}
			
			$query = "SELECT superfecta_mf_child_id, priority, cnam, spam_text, spam, source, cached
					FROM superfecta_mf_child
					WHERE superfecta_mf_id = ".$db->quoteSmart($superfecta_mf_id)."
					AND timestamp_cnam IS NOT NULL
					ORDER BY priority
					";
			$loop_limit = 200; // Loop 200 times maximum, just incase our timeout function fails
			$loop_start_time = mctime_float();
			$loop_cur_time = mctime_float();
			$loop_priority_time_limit = $param[$this_scheme]['multifecta_timeout'];
			$loop_time_limit = ($param[$this_scheme]['Curl_Timeout'] + .5); //Give us an extra half second over CURL
			$multifecta_timeout_hit = false;
			while($loop_limit && (($loop_cur_time - $loop_start_time)<=$loop_time_limit)){
				$res2 = $db->query($query);
				if (DB::IsError($res)){
					die("Unable to search for winning child: " . $res2->getMessage() .  "<br>");
				}
				$winning_child_id = false;
				$last_priority = 0;
				$first_caller_id = '';
				$spam_text = '';
				$spam = '';
				$spam_source = '';
				$spam_child_id = false;
				$loop_cur_time = mctime_float();
				while($res2 && ($row2 = $res2->fetchRow(DB_FETCHMODE_ASSOC))){
					// Wait for a winning child, in the order of it's preference
					// Take the first to finish after multifecta_timeout is reached
					if(($row2['priority']==$last_priority) 
						|| ($loop_limit == 1) 
						|| (($loop_cur_time - $loop_start_time)>$loop_time_limit)
						|| (($loop_cur_time - $loop_start_time)>$loop_priority_time_limit)
					){
						if((!$multifecta_timeout_hit) && (($loop_cur_time - $loop_start_time)>$loop_priority_time_limit)){
							$multifecta_timeout_hit = true;
							if($debug){
								print "Multifecta Timeout reached.  Taking first child with a CNAM result.<br>\n";
							}
						}
						// Record the results of any spam sources
						// We dont break out of the loop for spam though.  We'll just keep
						// checking it over and over until we get a cnam or we time-out.
						$spam_text = (($row2['spam_text'])?$row2['spam_text']:$spam_text);
						if($row2['spam_text'] && (!$spam_text)){
							$spam = $row2['spam'];
							$spam_text = $row2['spam_text'];
							$spam_source = $row2['source'];
							$spam_child_id = $row2['superfecta_mf_child_id'];
						}
						// If we hit a cnam result, we are done.  break out of the loop.
						$spam = (($row2['spam_text'])?$row2['spam']:$spam);
						if($row2['cnam'] && (!$first_caller_id)){
							$first_caller_id = $row2['cnam'];
							$winning_child_id = $row2['superfecta_mf_child_id'];
							$winning_source = $row2['source'];
							$cache_found = $row2['cached'];
							break;
						}
						$last_priority ++;
					}
				}
				// We have a cnam, break out of this loop too
				if($first_caller_id){ break; }
				$loop_limit --;
				if($loop_limit && ($loop_cur_time - $loop_start_time)<=$loop_time_limit){
					usleep(50000); // sleep for 1/20 second. Short delay, but should help from taxing the system too much.
				}else{
					if($debug){
						print "Maximum timeout reached.  Will not wait for any more children. <br>\n";
						break;
					}
				}
			}
			if($debug && $loop_cur_time){
				print "Parent waited " . number_format(($loop_cur_time - $loop_start_time),4) . " seconds for children's results. <br>\n";
			}
			if($debug && $first_caller_id){
				print "Winning CNAM child source $winning_child_id: $winning_source, with: $first_caller_id <br>\n";
			}
			if($debug && $spam_text){
				print "Winning SPAM child source $spam_child_id: $spam_source <br>\n";
			}
			if($debug && (!$first_caller_id) && (!$spam_text)){
				print "No winning SPAM or CNAM children found in allotted time. <br>\n";
			}
			$multifecta_parent_end_time = mctime_float();
			$query = "UPDATE superfecta_mf
				SET timestamp_end = ".$db->quoteSmart($multifecta_parent_end_time);
				if($winning_child_id){
					$query .= ",
					winning_child_id = ".$db->quoteSmart($winning_child_id);
				}
				if($spam_child_id){
					$query .= ",
					spam_child_id = ".$db->quoteSmart($spam_child_id);
				}
				$query .= "
				  	WHERE superfecta_mf_id = ".$db->quoteSmart($superfecta_mf_id)."
					";
			$res2 = $db->query($query);
		}
	}
}

//remove unauthorized character in the caller id
if ($first_caller_id !='')
{
	//$first_caller_id = _utf8_decode($first_caller_id);
	$first_caller_id = strip_tags($first_caller_id );
	$first_caller_id = trim ($first_caller_id);
	if ($charsetIA5)
	{
		$first_caller_id = stripAccents($first_caller_id);
	}
	$first_caller_id = preg_replace ( "/[\";']/", "", $first_caller_id);
	//limit caller id to the first 60 char
	$first_caller_id = substr($first_caller_id,0,60);
}

if($debug && (!$multifecta_id))
{
	print "<b>Returned Result would be: ";
	$first_caller_id = utf8_encode($first_caller_id);
}

// Output cnam/spam/prefix result
if(($first_caller_id || $spam_text) && (!$multifecta_id)){

	// If we are not runnign multifecta, or we are a multifecta parent, echo our results to STDOUT
	print (($prefix != '') ? $prefix.':' : '').(($spam_text != '') ? $spam_text.':' : '').$first_caller_id;

}elseif($multifecta_id){

	// If we are a multifecta child, update our child record with our results
	// Update only what we have -- leave the rest null 
	$multifecta_child_cname_time = mctime_float();
	$query = "UPDATE superfecta_mf_child
			SET timestamp_cnam = ".$db->quoteSmart($multifecta_child_cname_time);
			if($first_caller_id){
				$query .= ",
				cnam = ".$db->quoteSmart(trim($first_caller_id));
			}
			if($spam_text){
				$query .= ",
				spam_text = ".$db->quoteSmart($spam_text);
			}
			if($spam){
				$query .= ",
				spam = ".$db->quoteSmart($spam);
			}
			if($cache_found){
				$query .= ",
				cached = 1";
			}
			$query .= "
		  	WHERE superfecta_mf_child_id = ".$db->quoteSmart($multifecta_id)."
			";
	$res = $db->query($query);
	if (DB::IsError($res)){
		die("Unable to update child: " . $res->getMessage() .  "<br>");
	}

	// Reset some variables that will be filled by the winning child
	$spam_text = '';
	$spam = false;
	$first_caller_id = '';
	$winning_child_id = false;
	$multifecta_parent_end_time = false;

	// Now wait for the winning cnam, and then continue on to post-process
	$query = "SELECT mf.winning_child_id, mf.timestamp_end, mf.prefix, mf.scheme, mfc.cnam, mfc.source, mfc.cached, mfs.spam, mfs.spam_text
			FROM superfecta_mf mf
				LEFT OUTER JOIN superfecta_mf_child mfc 
					ON mfc.superfecta_mf_child_id = mf.winning_child_id
				LEFT OUTER JOIN superfecta_mf_child mfs
					ON mfs.superfecta_mf_child_id = mf.spam_child_id
		  	WHERE mf.superfecta_mf_id = ".$db->quoteSmart($multifecta_parent_id)."
			AND mf.timestamp_end IS NOT NULL 
			";

	// Check every second until we get a result
	$loop_limit = 10; // Loop for ~10 seconds before giving up
	while((!$multifecta_parent_end_time) && ($loop_limit)){
		sleep(1); // sleep for 1 second
		$res = $db->query($query);
		if (DB::IsError($res)){
			die("Unable to load winning child: " . $res->getMessage() .  "<br>");
		}
		if($res && ($row = $res->fetchRow(DB_FETCHMODE_ASSOC))){
			$winning_child_id = $row['winning_child_id'];
			$multifecta_parent_end_time = $row['timestamp_end'];
			$prefix = $row['prefix'];
			$first_caller_id = $row['cnam'];
			$spam_text = $row['spam_text'];
			$spam = $row['spam'];
			$winning_source = $row['source'];
			$this_scheme =  $row['scheme'];
			$cache_found = $row['cached'];
		}
		$loop_limit --;
	}
}

if($debug && (!$multifecta_id))
{
	// end of returned result debug output
	print "</b><br>\n";
}

// If we are not a multifecta parent, run the post proccess for this scheme
if((isset($param[$this_scheme])) && ((!$param[$this_scheme]['enable_multifecta']) || ($multifecta_id))){
	//post-processing
	if($debug)
	{
		print "Post CID retrieval processing.<br>\n<br>\n";
	}
	
	$usage_mode = 'post processing';
	foreach($src_array as $source_name)
	{
		// Run the source
		if((!$single_source) || ($single_source == $source_name)){
			$thenumber = $theoriginalnumber;
			$run_param = (isset($param[substr($this_scheme,5).'_'.$source_name]) ? $param[substr($this_scheme,5).'_'.$source_name] : array());
			require_once('superfecta_base.php');
			if(file_exists("source-".$source_name.".module")) {
				require_once("source-".$source_name.".module");
				$source_class = NEW $source_name;
				$source_class->db = $db;
				$source_class->debug = $debug;
				if(method_exists($source_class, 'post_processing')) {
					$caller_id = $source_class->post_processing($cache_found,$winning_source,$first_caller_id,$run_param,$thenumber);
				} else {
					print "Method 'post_processing' doesn't exist<br\>\n"; 
				}
			}
		}
	}
}

if($debug)
{
	$end_time_whole = ($end_time_whole == 0) ? mctime_float() : $end_time_whole;
	print "<br>\nresult <img src='images/scrollup.gif'> took ".number_format(($end_time_whole-$start_time_whole),4)." seconds.</b>";
}

if($multifecta_id){
	$multifecta_child_end_time = mctime_float();
	$query = "UPDATE superfecta_mf_child
			SET timestamp_end = ".$db->quoteSmart($multifecta_child_end_time)."
		  	WHERE superfecta_mf_child_id = ".$db->quoteSmart($multifecta_id)."
			";
	$res = $db->query($query);
	if (DB::IsError($res)){
		die("Unable to update child end time: " . $res->getMessage() .  "<br>");
	}
}

/**
Search an array of area codes against phone number to find one that matches.
Return an array with the area code, area name and remaining phone number
*/
function cisf_find_area ($area_array, $full_number)
{
	$largest_match = 0;
	$match = false;
        foreach ($area_array as $area => $area_code) {
		$area_length = strlen($area_code);
                if((substr($full_number,0,$area_length)==$area_code) && ($area_length > $largest_match)) {
                        $match = array(
				'area'=>$area,
				'area_code'=>$area_code,
				'number'=>substr($full_number,$area_length)
			);
			$largest_match = $area_length;
                }
        }
        return $match;
}

/**
Encode an array for transmission in http request
*/
function cisf_url_encode_array($arr){
	$string = "";
	foreach ($arr as $key => $value) {
		$string .= $key . "=" . urlencode($value) . "&";
	}
	trim($string,"&");
	return $string;
}

/**
Returns the content of a URL.
*/
function get_url_contents($url,$post_data=false,$referrer=false,$cookie_file=false,$useragent=false)
{
	global $debug,$curl_timeout;
	$crl = curl_init();
	if(!$useragent){
		// Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.6) Gecko/20100625 Firefox/3.6.6 ( .NET CLR 3.5.30729)
		$useragent="Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1";
	}
	if($referrer){
		curl_setopt ($crl, CURLOPT_REFERER, $referrer);
	}
	curl_setopt($crl,CURLOPT_USERAGENT,$useragent);
	curl_setopt($crl,CURLOPT_URL,$url);
	curl_setopt($crl,CURLOPT_RETURNTRANSFER,true);
	curl_setopt($crl,CURLOPT_CONNECTTIMEOUT,$curl_timeout);
	curl_setopt($crl,CURLOPT_FAILONERROR,true);
	curl_setopt($crl,CURLOPT_TIMEOUT,$curl_timeout);
	if($cookie_file){
		curl_setopt($crl, CURLOPT_COOKIEJAR, $cookie_file);
		curl_setopt($crl, CURLOPT_COOKIEFILE, $cookie_file);
	}
	if($post_data){
		curl_setopt($crl, CURLOPT_POST, 1); // set POST method
		curl_setopt($crl, CURLOPT_POSTFIELDS, cisf_url_encode_array($post_data)); // add POST fields
	}

	$ret = trim(curl_exec($crl));
	if(curl_error($crl) && $debug)
	{
		print ' '.curl_error($crl).' ';
	}

	//if debug is turned on, return the error number if the page fails.
	if($ret === false)
	{
		$ret = '';
	}
	//something in curl is causing a return of "1" if the page being called is valid, but completely empty.
	//to get rid of this, I'm doing a nasty hack of just killing results of "1".
	if($ret == '1')
	{
		$ret = '';
	}
	curl_close($crl);
	return $ret;
}

function mctime_float()
{
	 list($usec, $sec) = explode(" ", microtime());
	 return ((float)$usec + (float)$sec);
}

/** 
	Match a phone number against an array of patterns
	return array containing
	'pattern' = the pattern that matched
	'number' = the number that matched, after applying rules
	'status' = true if a valid array was supplied, false if not
	
*/

function match_pattern_all($array, $number){

	// If we did not get an array, it's probably a list. Convert it to an array.
	if(!is_array($array)){
		$array =  explode("\n",trim($array));		
	}

	$match = false;
	$pattern = false;
	
	// Search for a match
	foreach($array as $pattern){
		// Strip off any leading underscore
		$pattern = (substr($pattern,0,1) == "_")?trim(substr($pattern,1)):trim($pattern);
		if($match = match_pattern($pattern,$number)){
			break;
		}elseif($pattern == $number){
			$match = $number;
			break;
		}
	}

	// Return an array with our results
	return array(
		'pattern' => $pattern,
		'number' => $match,
		'status' => (isset($array[0]) && (strlen($array[0])>0))
	);
}

/**
	Parses Asterisk dial patterns and produces a resulting number if the match is successful or false if there is no match.
*/

function match_pattern($pattern, $number)
{
	global $debug;
	$pattern = trim($pattern);
	$p_array = str_split($pattern);
	$tmp = "";
	$expression = "";
	$new_number = false;
	$remove = "";
	$insert = "";
	$error = false;
	$wildcard = false;
	$match = $pattern?true:false;
	$regx_num = "/^\[[0-9]+(\-*[0-9])[0-9]*\]/i";
	$regx_alp = "/^\[[a-z]+(\-*[a-z])[a-z]*\]/i";

	// Try to build a Regular Expression from the dial pattern
	$i = 0;
	while (($i < strlen($pattern)) && (!$error) && ($pattern))
	{
		switch(strtolower($p_array[$i]))
		{
			case 'x':
				// Match any number between 0 and 9
				$expression .= $tmp."[0-9]";
				$tmp = "";
				break;
			case 'z':
				// Match any number between 1 and 9
				$expression .= $tmp."[1-9]";
				$tmp = "";
				break;
			case 'n':
				// Match any number between 2 and 9
				$expression .= $tmp."[2-9]";
				$tmp = "";
				break;
			case '[':
				// Find out if what's between the brackets is a valid expression.
				// If so, add it to the regular expression.
				if(preg_match($regx_num,substr($pattern,$i),$matches)
					||preg_match($regx_alp,substr(strtolower($pattern),$i),$matches))
				{
					$expression .= $tmp."".$matches[0];
					$i = $i + (strlen($matches[0])-1);
					$tmp = "";
				}
				else
				{
					$error = "Invalid character class";
				}
				break;
			case '.':
			case '!':
				// Match and number, and any amount of them
				if(!$wildcard){
					$wildcard = true;
					$expression .= $tmp."[0-9]+";
					$tmp = "";
				}else{
					$error = "Cannot have more than one wildcard";
				}
				break;
			case '+':
				// Prepend any numbers before the '+' to the final match
                                // Store the numbers that will be prepended for later use
				if(!$wildcard){
					if($insert){
						$error = "Cannot have more than one '+'";
					}elseif($expression){
						$error = "Cannot use '+' after X,Z,N or []'s";
					}else{
						$insert = $tmp;
						$tmp = "";
					}
				}else{
					$error = "Cannot have '+' after wildcard";
				}
				break;
			case '|':
				// Any numbers/expression before the '|' will be stripped
				if(!$wildcard){
					if($remove){
						$error = "Cannot have more than one '|'";
					}else{
						// Move any existing expression to the "remove" expression
						$remove = $tmp."".$expression;
						$tmp = "";
						$expression = "";
					}
				}else{
					$error = "Cannot have '|' after wildcard";
				}
				break;
			default:
				// If it's not any of the above, is it a number betwen 0 and 9?
				// If so, store in a temp buffer.  Depending on what comes next
				// we may use in in an expression, or a prefix, or a removal expression
				if(preg_match("/[0-9]/i",strtoupper($p_array[$i]))){
					$tmp .= strtoupper($p_array[$i]);
				}else{
					$error = "Invalid character '".$p_array[$i]."' in pattern";
				}
		}
		$i++;
	}
	$expression .= $tmp;
	$tmp = "";
	if($error){
		// If we had any error, report them
		$match = false;
		if($debug){print $error." - position $i<br>\n";}
	}else{
		// Else try out the regular expressions we built
		if($remove){
			// If we had a removal expression, se if it works
			if(preg_match("/^".$remove."/i",$number,$matches)){
				$number = substr($number,strlen($matches[0]));
			}else{
				$match = false;
			}
		}
		// Check the expression for the rest of the number
		if(preg_match("/^".$expression."$/i",$number,$matches)){
			$new_number = $matches[0];
		}else{
			$match = false;
		}
		// If there was a prefix defined, add it.
		$new_number = $insert . "" . $new_number;
		
	}
	if(!$match){
		// If our match failed, return false
		$new_number = false;
	}
	return $new_number;

}

function stripAccents($string)
{
	$string = html_entity_decode($string);
	$string = strtr($string,"���������������������������������������������������������������������","SOZsozYYuAAAAAAACEEEEIIIIDNOOOOOOUUUUYsaaaaaaaceeeeiiiionoooooouuuuyy");
	$string = str_replace(chr(160), ' ', $string);
	return $string;
}

function isutf8($string)
{
	if (!function_exists('mb_detect_encoding')) {
		return false;
	} else {
		return (mb_detect_encoding($string."e")=="UTF-8");// added a character to the string to avoid the mb detect bug
	}
}

function _utf8_decode($string)
{
  	$string= html_entity_decode($string);
  	$tmp = $string;
	$count = 0;
	while (isutf8($tmp))
  	{
  		$tmp = utf8_decode($tmp);
		$count++;
	}

  	for ($i = 0; $i < $count-1 ; $i++)
  	{
    		$string = utf8_decode($string);
  	}
  	return $string;
}
?>
