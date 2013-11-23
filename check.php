<?php
ini_set('max_execution_time', 0);
ini_set('memory_limit', '2G');

require_once ('lib/ini.php');

	/////////////////////////////////////////////////////////////////////////
	// initialize
	$ini = INI::read('config.ini');
	$config = $ini['CONFIG'];

	$logPath = dirname(__FILE__) . '/log';
	@mkdir($logPath, 0755, TRUE);

	
	$file['list'] = $logPath . '/list.txt';
	$file['list_md5'] = $logPath . '/list.txt.md5';
	$file['list_diff'] = $logPath . '/list.txt.diff';
	
	
	/////////////////////////////////////////////////////////////////////////
	// read previous info.
	if (file_exists($file['list'])) {
		$prev_list = json_decode( file_get_contents($file['list']), TRUE );
		rename($file['list'], $file['list'] . '.' . date('Ymd'));
	}
	
	if (file_exists($file['list_md5'])) {
		$prev_list_md5 = file_get_contents($file['list_md5']);
		rename($file['list_md5'], $file['list_md5'] . '.' . date('Ymd'));
	}
	
	if (file_exists($file['list_diff'])) {
		rename($file['list_diff'], $file['list_diff'] . '.' . date('Ymd'));
	}	
	/////////////////////////////////////////////////////////////////////////
	// scan dir
	$dir = new RecursiveDirectoryIterator($config['WEB_ROOT']);
	$iter = new RecursiveIteratorIterator($dir);

	$json = array();
	foreach (new RegexIterator($iter, $config['FILE_PATTERN'], RecursiveRegexIterator::GET_MATCH) as $filename=>$current) {
		
		if ($config['IGNORE_PATTERN']!='' && preg_match($config['IGNORE_PATTERN'], $filename)) {
			
			echo "\n<BR> ignore: $filename";
			continue;
		
		}
		
		echo "\n<BR> {$filename}";
		$json[iconv("BIG-5","UTF-8",$filename)] =  array(
					'size' => filesize($filename),
					'mtime' => filemtime($filename),
					'md5' => md5(file_get_contents($filename))
					);
    }
	
	file_put_contents($file['list'], json_encode($json));
	
	$md5 = md5( file_get_contents($file['list']) );
	file_put_contents($file['list_md5'], $md5);
	
	/////////////////////////////////////////////////////////////////////////
	// compare
		
	if ($prev_list_md5=='' || !$prev_list) exit;
	if ($md5 == $prev_list_md5) exit;
	//echo "\n prev_list_md5, md5: $prev_list_md5, $md5";
	
	
	$diff['add'] = rdiff($json, $prev_list);
	$diff['remove'] = rdiff($prev_list, $json);
	
	$logmsg = '';
	// decrease
	foreach($diff['remove'] as $s) {
		$logmsg[] = "- {$s}";

	}
	
	// increase
	foreach($diff['add'] as $s) {
		$logmsg[] = "+ {$s}";
	}

	file_put_contents($file['list_diff'], join("\n", $logmsg));
		
	if ($config['SENDMAIL']==1) {
		sendMail($config['FROM'], $config['TO'],  $config['SUBJECT'], "<P>{$config['BODY']}</P><P>" . join("<BR />", $logmsg) . "</P>");
	}
	
exit;
	
/////////////////////////////////////////////////////////////////////////
// functions

function rdiff (&$arr1, &$arr2)
{
	$out = array();
	foreach($arr1 as $key => $a1) {
		
		if (!$arr2[$key]) {
			$out[] = $key;
			unset($arr1[$key]);
			continue;
		}
		
		if (array_diff_assoc($a1, $arr2[$key])) {
			$out[] = "(modify) $key";
			
			unset($arr1[$key]);
			unset($arr2[$key]);
		}
	}
  
	return $out;
}

function sendMail($from, $to, $subject, $msg, $attach = array())
{
	require(dirname(__FILE__) . '/lib/PHPMailer/class.phpmailer.php');
	$ini = INI::read('config.ini');
	$config = $ini['MAIL'];
	
	$mail = new PHPMailer(true); // the true param means it will throw exceptions on errors, which we need to catch
	
	if ($config['USE_SMTP']==1) {
		
		$mail->IsSMTP();

		$mail->Host = $config['SMTP_HOST'];
		$mail->Port = ($config['SMTP_PORT']=='') ? 25 : $config['SMTP_PORT'];
		
		if ($config['SMTP_USER']!='') {
			$mail->SMTPAuth = TRUE;
			$mail->Username = $config['SMTP_USER'];
			$mail->Password = $config['SMTP_PWD'];
			if ($config['SMTP_SECURE']) $mail->SMTPSecure = $config['SMTP_SECURE'];
		}
	} else {
		$mail->IsSendmail();
	}

	
	// from
	if( is_array($from) )
	{	$fromName = $from['name']; 		$fromEMail = $from['email']; 	}
	else
		$fromName = $fromEMail = $from;

	// to
	if( is_array($to) )
	{	$toName = $to['name']; 			$toEMail = $to['email']; 	}
	else
		$toName = $toEMail = $to;
		

	try {
		$mail->CharSet="UTF-8";

		$mail->AddReplyTo($fromEMail, $fromName);
		$mail->AddAddress($toEMail, $toName);
		$mail->SetFrom($fromEMail, $fromName);
		$mail->Subject = $subject;
		$mail->AltBody = 'To view the message, please use an HTML compatible email viewer!'; // optional - MsgHTML will create an alternate automatically
		$mail->MsgHTML($msg);

		// proc attachment
		foreach($attach as $type => $att) {
			if (isset($att['cid'])) 
				$mail->AddEmbeddedImage($att['path'], $att['cid'], $att['name']);
			else
				$mail->AddAttachment($att['path'], $att['name']);
		}

		echo "\n send to {$toName} &lt;{$toEMail}&gt;";
		$mail->Send();
	  
		return true;
	} catch (phpmailerException $e) {
		echo "\r\nMail Error: " . $e->errorMessage(); //Pretty error messages from PHPMailer
	  return false;
	} catch (Exception $e) {
		echo "\r\nMail Error: " . $e->getMessage(); //Boring error messages from anything else!
	  return false;
	}
}
