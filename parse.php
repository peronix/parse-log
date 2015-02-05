<?php
require_once 'vendor/autoload.php';
use GeoIp2\Database\Reader;

date_default_timezone_set('America/Los_Angeles');

header('Content-Type: text/plain');

$stats = array();
$unique_ips = array();
$session_dates = array();

$log_regex = '/^(\S+) (\S+) (\S+) (\S+) \[([^:]+:\d+:\d+:\d+ [^\]]+)\] \"(\S+) (.*?) (\S+)\" (\S+) (\S+) "([^"]*)" "([^"]*)"$/';
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
			'REMOTEADDR' => $matches[2],
			'SESSION' => $matches[3],
			'USERNAME' => $matches[4],
			'DATETIME' => $matches[5],
			'METHOD' => $matches[6],
			'REQUESTURI' => $matches[7],
			'PROTO' => $matches[8],
			'STATUSCODE' => $matches[9],
			'SIZE' => $matches[10],
			'REFERRER' => $matches[11],
			'USERAGENT' => $matches[12],
		);

		$request = trim($log['REQUESTURI'], '/');

		if(strlen($log['SESSION']) < 32 || (substr($request, 0, 2) != 'bs' && substr($request, 0, 8) != 'register')){
			continue;
		}

		$ref = parse_url($log['REFERRER']);
		$host = implode('.', array_slice(explode('.', $ref['host']), 0, 3));
		$form_path = $host.$ref['path'];

		if(substr($request, 0, 2) == 'bs' && $log['STATUSCODE'] == 200){
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
			// $stats[$form_path]['referral_sources'][$log['REFERRER']] = isset($stats[$form_path]['referral_sources'][$log['REFERRER']]) 
			// 	? $stats[$form_path]['referral_sources'][$log['REFERRER']]+1 : 1;
			$session_dates[$form_path][$log['SESSION']]['start'] = $time;
			if(empty($stats[$form_path]['log_start']) || $stats[$form_path]['log_start'] > $time)
				$stats[$form_path]['log_start'] = $time;
			if(empty($stats[$form_path]['log_end']) || $stats[$form_path]['log_end'] < $time)
				$stats[$form_path]['log_end'] = $time;
			if(empty($unique_ips[$form_path][$log['REMOTEADDR']])){
				$unique_ips[$form_path][$log['REMOTEADDR']] = array(
					'views' => 1,
					'transactions' => 0
				);
			}else{
				$unique_ips[$form_path][$log['REMOTEADDR']]['views'] += 1;
			}
		}else
		if(substr($request, 0, 8) == 'register' && $log['STATUSCODE'] == 200) {
			$session_dates[$form_path][$log['SESSION']]['end'] = strtotime($log['DATETIME']);
			if(empty($unique_ips[$form_path][$log['REMOTEADDR']])){
				$unique_ips[$form_path][$log['REMOTEADDR']] = array(
					'views' => 0,
					'transactions' => 1
				);
			}else{
				$unique_ips[$form_path][$log['REMOTEADDR']]['transactions'] += 1;
			}
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
	$reader = new Reader('GeoLite2-City.mmdb');
	foreach($unique_ips as $form_path => $x){
		$stats[$form_path]['unique_visitors'] = count($x);
		foreach($x as $ip => $y){
			$record = $reader->city($ip);
			$location = $record->country->isoCode
				.'-'.$record->mostSpecificSubdivision->isoCode
				.'-'.$record->postal->code;
			if(empty($stats[$form_path]['map'][$location])){
				$stats[$form_path]['map'][$location] = array(
					'views' => $y['views'],
					'transactions' => $y['transactions'],
					'lat' => $record->location->latitude,
					'lng' => $record->location->longitude
				);
			}else{
				$stats[$form_path]['map'][$location]['views'] += $y['views'];
				$stats[$form_path]['map'][$location]['transactions'] += $y['transactions'];
			}
			if($y['transactions']){
				$stats[$form_path]['conversions'] = isset($stats[$form_path]['conversions'])
					? $stats[$form_path]['conversions']+1 : 1;
			}
		}
	}

	var_dump($stats);

	$db = new PDO('mysql:host=localhost;dbname=webconnex', 'root', '');
	$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

	$pub_sel = $db->prepare("select account_id, form_id from published where path = ?");
	$stats_sel = $db->prepare("select * from form_stats where form_id = ?");
	$ref_sel = $db->prepare("select * from form_stats_ref where form_id = ? and referrer = ?");
	$map_sel = $db->prepare("select * from form_stats_map where form_id = ? and location = ?");

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

	$map_ins = $db->prepare("insert into form_stats_map (account_id, form_id, location, lat, lng, views, transactions) "
		."VALUES (:account_id, :form_id, :location, :lat, :lng, :views, :transactions)");
	$map_upd = $db->prepare("update form_stats_map set views = :views, transactions = :transactions where id = :id");

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
		foreach($data['map'] as $location => $location_data){
			$map_sel->execute(array($pub_row['form_id'], $location));
			$map_row = $map_sel->fetch();
			if(empty($map_row)){
				$map_data = array(
					':account_id' => $pub_row['account_id'],
					':form_id' => $pub_row['form_id'],
					':location' => $location,
					':lat' => $location_data['lat'],
					':lng' => $location_data['lng'],
					':views' => $location_data['views'],
					':transactions' => $location_data['transactions']
				);
				$map_ins->execute($map_data);
			}else{
				$map_data = array(
					':views' => $map_row['views']+$location_data['views'],
					':transactions' => $map_row['transactions']+$location_data['transactions'],
					':id' => (int)$map_row['id']
				);
				$map_upd->execute($map_data);
			}
		}
		// foreach($data['referral_sources'] as $ref => $count){
		// 	$ref_sel->execute(array($pub_row['form_id'],$ref));
		// 	$ref_row = $ref_sel->fetch();
		// 	if(empty($ref_row)){
		// 		$ref_data = array(
		// 			':account_id' => $pub_row['account_id'],
		// 			':form_id' => $pub_row['form_id'],
		// 			':referrer' => $ref,
		// 			':count' => $count
		// 		);
		// 		$ref_ins->execute($ref_data);
		// 	}else{
		// 		$ref_data = array(
		// 			':count' => $ref_row['count']+$count,
		// 			':id' => (int)$ref_row['id'],
		// 		);
		// 		$ref_upd->execute($ref_data);
		// 	}
		// }
	}
}