<?php
  require_once('php-asmanager.php');
	require_once('findmefollow-define-config.php');

  // if (count($argv) == 1 || count($argv) > 3) die('Just testing for passing args,
	// command: php filename.php grpnum newgrplist');	// 1 because execute with php filename.php argv

	// $grpnum = $argv[1];
	// $newgrplist = $argv[2];

  $grpnum = '1002'; // for testing data
	$newgrplist = "1002-444555666#";

	// define values forf non-exists findmefollow grpnum
	$usedefaultset = FALSE;
	$defaultvalues = "'$grpnum', 'ringallv2', '20', '', '$newgrplist', '0', 'from-did-direct,$grpnum,1', '', '0', ".
	"'', '0', '0', 'Ring'";
	$defaultcolumns = "grpnum, strategy, grptime, grppre, grplist, annmsg_id, postdest, dring, remotealert_id, ".
	"needsconf, toolate_id, pre_ring, ringing";
	$defaultconfigs = array('prering' => '0',
													'grptime' => '20',
													'grpconf' => 'DISABLED',
													'ddial' => 'DIRECT',
													'changecid' => 'default',
													'fixedcid' => '',
													'needsconf' => '',
 											);


	change_followmelist($grpnum, $newgrplist, $defaultcolumns, $defaultvalues, $defaultconfigs, $usedefaultset);

	// changing $grplist with specific $grpnum
	function change_followmelist($grpnum, $newgrplist, $defaultcolumns, $defaultvalues, $defaultconfigs, $usedefaultset) {
		$astman = connect_ast_manager();

		// check if connect AsteriskManager successfully
		if ($astman->connected()) {
			$conn = connect_database();

			// get temporary data use for INSERT later
			$query = "SELECT * FROM `findmefollow` WHERE grpnum = '$grpnum'";
			$result = mysql_query($query, $conn);

			if (!$result) {
	     	die('Could not select data: ' . mysql_error());

	   	} elseif (mysql_num_rows($result) === 0) {	// incse grpnum not found
				// 		die('Findmefollow\'s grpnum: ' . $grpnum . ' is not exists');

				// create default values for sql insert command
				$columns = $defaultcolumns;
				$values = $defaultvalues;
 				$usedefaultset = TRUE;

			} else {	// incase grpnum found
				while ($fmefollow = mysql_fetch_assoc($result)) {
					// change grplist values to new values
					$fmefollow['grplist'] = $newgrplist;

					// get columns name in mysql way
					$columns = implode(", ",array_keys($fmefollow));

					// get values in mysql way
					$escaped_values = array_map('mysql_real_escape_string', array_values($fmefollow));
					$values  = "'" . implode("', '", $escaped_values) . "'";

				}
			}

			// delete extentions in database
			$query = "DELETE FROM `findmefollow` WHERE grpnum = '$grpnum'";
			$result = mysql_query($query, $conn);
			if (!$result) {
				die('Could not delete data: ' . mysql_error());
			}

			// delete config in asterisk, skipping it if grpnum not found
			if (!$usedefaultset) {
				$astman->database_deltree("AMPUSER/".$grpnum."/followme");
			}

			// insert extentions to database
			$query = "INSERT INTO `findmefollow` ($columns) VALUES ($values)";
			$result = mysql_query($query, $conn);
			if (!$result) {
				die('Could not insert data: ' . mysql_error());
			}

			// insert config in asterisk, just change grplist
			$astman->database_put("AMPUSER",$grpnum."/followme/grplist",$newgrplist);

			// config values below change based on if grpnum was found in database or not

			$astman->database_put("AMPUSER",$grpnum."/followme/prering",$usedefaultset ?
			$defaultconfigs['prering']: $fmefollow['prering']);
			$astman->database_put("AMPUSER",$grpnum."/followme/grptime",$usedefaultset ?
			$defaultconfigs['grptime']: $fmefollow['grptime']);

			if (!$usedefaultset) {
				$needsconf = isset($fmefollow['needsconf'])?$fmefollow['needsconf']:'';
			} else {
				$needsconf = isset($defaultconfigs['needsconf'])?$defaultconfigs['needsconf']:'';
			}

			$confvalue = ($needsconf == 'CHECKED')?'ENABLED':'DISABLED';
			$astman->database_put("AMPUSER",$grpnum."/followme/grpconf",$confvalue);

			$ddial = isset($defaultconfigs['ddial'])?$defaultconfigs['ddial']:'';
			$ddialvalue = ($ddial == 'CHECKED')?'EXTENSION':'DIRECT';
			$astman->database_put("AMPUSER",$grpnum."/followme/ddial",$ddialvalue);

			$astman->database_put("AMPUSER",$grpnum."/followme/changecid",$defaultconfigs['changecid']);

		  $fixedcid = preg_replace("/[^0-9\+]/" ,"", trim($defaultconfigs['fixedcid']));
			$astman->database_put("AMPUSER",$grpnum."/followme/fixedcid",$fixedcid);


		} else {
			echo "Can't connect to AsteriskManager, plese check again your AsteriskManager info in config file!";
		}
	}

	// connect to AsteriskManager
	function connect_ast_manager() {
		// initial astman object from class
		$astman = new AGI_AsteriskManager();
		// connect to AsteriskManager
		$astman->connect(AST_HOST, AST_USER, AST_PASS);

		return $astman;
	}

	// connect to asterisk database
	function connect_database() {
   	$conn = mysql_connect(DB_HOST, DB_USER, DB_PASS);

   	if (!$conn) {
     	die('Could not connect: ' . mysql_error());
   	}

		if (!mysql_select_db(DB_NAME, $conn)) {
	    die('Could select database: ' . mysql_error());
		}

   	return $conn;
	}


	// MISSING FUNCTION FOR php-asmanager.php

	function parse_amportal_conf($filename) {
					$file = file($filename);
					foreach ($file as $line) {
									if (preg_match("/^\s*([a-zA-Z0-9_]+)=([a-zA-Z0-9 .&-@=_!<>\"\']+)\s*$/",$line,$matches)) {
													$conf[ $matches[1] ] = $matches[2];
									}
					}
					return $conf;
	}

	function parse_asterisk_conf($filename) {
		//TODO: Should the correction of $amp_conf be passed by refernce and optional?
		//
		global $amp_conf;
		$conf = array();

		$convert = array(
			'astetcdir'    => 'ASTETCDIR',
			'astmoddir'    => 'ASTMODDIR',
			'astvarlibdir' => 'ASTVARLIBDIR',
			'astagidir'    => 'ASTAGIDIR',
			'astspooldir'  => 'ASTSPOOLDIR',
			'astrundir'    => 'ASTRUNDIR',
			'astlogdir'    => 'ASTLOGDIR'
		);

		$file = file($filename);
		foreach ($file as $line) {
			if (preg_match("/^\s*([a-zA-Z0-9]+)\s* => \s*(.*)\s*([;#].*)?/",$line,$matches)) {
				$conf[ $matches[1] ] = rtrim($matches[2],"/ \t");
			}
		}

		// Now that we parsed asterisk.conf, we need to make sure $amp_conf is consistent
		// so just set it to what we found, since this is what asterisk will use anyhow.
		//
		foreach ($convert as $ast_conf_key => $amp_conf_key) {
			if (isset($conf[$ast_conf_key])) {
				$amp_conf[$amp_conf_key] = $conf[$ast_conf_key];
			}
		}
		return $conf;
	}

 ?>
