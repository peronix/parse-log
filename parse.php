<?php

date_default_timezone_set('America/Los_Angeles');

header('Content-Type: text/plain');

$stats = array();
$unique_ips = array();
$session_dates = array();

$log_regex = '/^(\S+) (\S+) (\S+) \[([^:]+:\d+:\d+:\d+ [^\]]+)\] \"(\S+) (.*?) (\S+)\" (\S+) (\S+) "([^"]*)" "([^"]*)"$/';
$tablet_regex = '/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i';
$mobile_regex = '/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile)/i';
$mobile_agents = array(
	'w3c ','acs-','alav','alca','amoi','audi','avan','benq','bird','blac',
	'blaz','brew','cell','cldc','cmd-','dang','doco','eric','hipt','inno',
	'ipaq','java','jigs','kddi','keji','leno','lg-c','lg-d','lg-g','lge-',
	'maui','maxo','midp','mits','mmef','mobi','mot-','moto','mwbp','nec-',
	'newt','noki','palm','pana','pant','phil','play','port','prox',
	'qwap','sage','sams','sany','sch-','sec-','send','seri','sgh-','shar',
	'sie-','siem','smal','smar','sony','sph-','symb','t-mo','teli','tim-',
	'tosh','tsm-','upg1','upsi','vk-v','voda','wap-','wapa','wapi','wapp',
	'wapr','webc','winw','winw','xda ','xda-');
if (($handle = fopen("http.log", "r")) !== FALSE) {
	while (($data = fgets($handle)) !== FALSE) {
		preg_match($log_regex, $data, $matches);
		if(count($matches) == 0){
			continue;
		}
		$log = array(
			'REMOTEADDR' => $matches[1],
			'SESSION' => $matches[2],
			'USERNAME' => $matches[3],
			'DATETIME' => $matches[4],
			'METHOD' => $matches[5],
			'REQUESTURI' => $matches[6],
			'PROTO' => $matches[7],
			'STATUSCODE' => $matches[8],
			'SIZE' => $matches[9],
			'REFERRER' => $matches[10],
			'USERAGENT' => $matches[11],
		);
		
		$path = $log['REQUESTURI'];
		$path_parts = explode('/', trim($path, '/'));
		$request = $path_parts[0];

		if($request != 'form' && $request != 'register'){
			continue;
		}

		$form_path = $path_parts[1].'/'.$path_parts[2];

		if($request == 'form' && $log['STATUSCODE'] == 200){
			$tablet_browser = 0;
			$mobile_browser = 0;
			$browser_type = 'desktop';

			if (preg_match($tablet_regex, strtolower($log['USERAGENT']))) {
		    	$tablet_browser++;
			}
			if (preg_match($mobile_regex, strtolower($log['USERAGENT']))) {
			    $mobile_browser++;
			}
			if (in_array(strtolower(substr($log['USERAGENT'], 0, 4)),$mobile_agents)) {
			    $mobile_browser++;
			}
			if ($tablet_browser > 0) {
				$browser_type = 'tablet';
			}
			else if ($mobile_browser > 0) {
				$browser_type = 'mobile';
			}

			$time = strtotime($log['DATETIME']);

			$stats[$form_path]['page_views'] = isset($stats[$form_path]['page_views'])
				? $stats[$form_path]['page_views']+1 : 1;
			$stats[$form_path][$browser_type.'_clients'] = isset($stats[$form_path][$browser_type.'_clients'])
				? $stats[$form_path][$browser_type.'_clients']+1 : 1;
			$stats[$form_path]['referral_sources'][$log['REFERRER']] = isset($stats[$form_path]['referral_sources'][$log['REFERRER']]) 
				? $stats[$form_path]['referral_sources'][$log['REFERRER']]+1 : 1;
			$unique_ips[$form_path][$log['REMOTEADDR']] = isset($unique_ips[$form_path][$log['REMOTEADDR']])
				? $unique_ips[$form_path][$log['REMOTEADDR']] : 0;
			$session_dates[$form_path][$log['SESSION']]['start'] = $time;
			if(empty($stats[$form_path]['log_start']) || $stats[$form_path]['log_start'] > $time)
				$stats[$form_path]['log_start'] = $time;
			if(empty($stats[$form_path]['log_end']) || $stats[$form_path]['log_end'] < $time)
				$stats[$form_path]['log_end'] = $time;
		}else
		if($request == 'register' && $log['STATUSCODE'] == 200) {
			$session_dates[$form_path][$log['SESSION']]['end'] = strtotime($log['DATETIME']);
			$unique_ips[$form_path][$log['REMOTEADDR']] = isset($unique_ips[$form_path][$log['REMOTEADDR']])
				? $unique_ips[$form_path][$log['REMOTEADDR']]+1 : 1;
		}
	}

	fclose($handle);

	foreach($session_dates as $form_path => $x){
		foreach($x as $session => $dates){
			if(!empty($dates['start']) && !empty($dates['end'])){
				$transaction_time = $dates['end']-$dates['start'];
				$stats[$form_path]['transactions'] = isset($stats[$form_path]['transactions'])
					? $stats[$form_path]['transactions']+1 : 1;
				$stats[$form_path]['transaction_time'] = isset($stats[$form_path]['transaction_time'])
					? $stats[$form_path]['transaction_time']+$transaction_time : $transaction_time;
			}
		}
	}
	foreach($unique_ips as $form_path => $x){
		$stats[$form_path]['unique_visitors'] = count($x);
		foreach($x as $ip => $y){
			if($y){
				$stats[$form_path]['conversions'] = isset($stats[$form_path]['conversions'])
					? $stats[$form_path]['conversions']+1 : 1;
			}
		}
	}
	
	$db = new PDO('mysql:host=localhost;dbname=webconnex', 'root', '');
	$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

	$pub_sel = $db->prepare("select account_id, form_id from published where path = ?");
	$stats_sel = $db->prepare("select * from form_stats where form_id = ?");
	$ref_sel = $db->prepare("select * from form_stats_ref where form_id = ? and referrer = ?");

	$stats_ins = $db->prepare("insert into form_stats (log_start, log_end, account_id, form_id, page_views, "
		."desktop_clients, tablet_clients, mobile_clients, transactions, transaction_time, unique_visitors, conversions) "
		."VALUES (:log_start, :log_end, :account_id, :form_id, :page_views, :desktop_clients, :tablet_clients, :mobile_clients, "
		.":transactions, :transaction_time, :unique_visitors, :conversions)");
	$stats_upd = $db->prepare("update form_stats set log_start = :log_start, log_end = :log_end, page_views = :page_views, "
		."desktop_clients = :desktop_clients, tablet_clients = :tablet_clients, mobile_clients = :mobile_clients, "
		."transactions = :transactions, transaction_time = :transaction_time, unique_visitors = :unique_visitors, conversions = :conversions "
		."where id = :id");

	$ref_ins = $db->prepare("insert into form_stats_ref (account_id, form_id, referrer, count) VALUES (:account_id, :form_id, :referrer, :count)");
	$ref_upd = $db->prepare("update form_stats_ref set count = :count where id = :id");

	foreach($stats as $form_path => $data){
		$form_path = 'sota.givingfuel.com/mobile-giving-form';
		$pub_sel->execute(array($form_path));
		$pub_row = $pub_sel->fetch();
		if(empty($pub_row)){
			continue;
		}
		$stats_sel->execute(array($pub_row['form_id']));
		$stats_row = $stats_sel->fetch();
		if(empty($stats_row)){
			$stats_data = array(
				':log_start' => date("Y-m-d H:i:s", $data['log_start']),
				':log_end' => date("Y-m-d H:i:s", $data['log_end']),
				':account_id' => $pub_row['account_id'],
				':form_id' => $pub_row['form_id'],
				':page_views' => $data['page_views'],
				':desktop_clients' => !empty($data['desktop_clients']) ? $data['desktop_clients'] : 0,
				':tablet_clients' => !empty($data['tablet_clients']) ? $data['tablet_clients'] : 0,
				':mobile_clients' => !empty($data['mobile_clients']) ? $data['mobile_clients'] : 0,
				':transactions' => $data['transactions'],
				':transaction_time' => $data['transaction_time'],
				':unique_visitors' => $data['unique_visitors'],
				':conversions' => $data['conversions'],
			);
			$stats_ins->execute($stats_data);
		}else{
			$stats_data = array(
				':log_start' => $data['log_start'] < strtotime($stats_row['log_start']) ? date("Y-m-d H:i:s", $data['log_start']) : $stats_row['log_start'],
				':log_end' => $data['log_end'] > strtotime($stats_row['log_end']) ? date("Y-m-d H:i:s", $data['log_end']) : $stats_row['log_end'],
				':page_views' => $data['page_views']+$stats_row['page_views'],
				':desktop_clients' => !empty($data['desktop_clients']) ? $data['desktop_clients']+$stats_row['desktop_clients'] : $stats_row['desktop_clients'],
				':tablet_clients' => !empty($data['tablet_clients']) ? $data['tablet_clients']+$stats_row['tablet_clients'] : $stats_row['tablet_clients'],
				':mobile_clients' => !empty($data['mobile_clients']) ? $data['mobile_clients']+$stats_row['mobile_clients'] : $stats_row['mobile_clients'],
				':transactions' => $data['transactions']+$stats_row['transactions'],
				':transaction_time' => $data['transaction_time']+$stats_row['transaction_time'],
				':unique_visitors' => $data['unique_visitors']+$stats_row['unique_visitors'],
				':conversions' => $data['conversions']+$stats_row['conversions'],
				':id' => (int)$stats_row['id'],
			);
			$stats_upd->execute($stats_data);
		}
		foreach($data['referral_sources'] as $ref => $count){
			$ref_sel->execute(array($pub_row['form_id'],$ref));
			$ref_row = $ref_sel->fetch();
			if(empty($ref_row)){
				$ref_data = array(
					':account_id' => $pub_row['account_id'],
					':form_id' => $pub_row['form_id'],
					':referrer' => $ref,
					':count' => $count
				);
				$ref_ins->execute($ref_data);
			}else{
				$ref_data = array(
					':count' => $ref_row['count']+$count,
					':id' => (int)$ref_row['id'],
				);
				$ref_upd->execute($ref_data);
			}
		}
	}
}