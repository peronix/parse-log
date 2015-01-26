<?php

date_default_timezone_set('America/Los_Angeles');

$stats = array();
$unique_ips = array();
$session_dates = array();
$referral_sources = array();

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
		
		$path_parts = explode('/', trim($log['REQUESTURI'], '/'));
		$request = $path_parts[0];

		if($request != 'form' && $request != 'register'){
			continue;
		}

		$domain = $path_parts[1];
		$form_url = $path_parts[2];

		$domain_parts = explode(".", $domain);
		$subdomain = $domain_parts[0];
		$product = $domain_parts[1].'.'.$domain_parts[2];

		$form_identifier = $subdomain.'.'.$form_url;

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

			$stats[$product][$form_identifier]['page_views'] = isset($stats[$product][$form_identifier]['page_views'])
				? $stats[$product][$form_identifier]['page_views']+1 : 1;
			$stats[$product][$form_identifier][$browser_type.'_clients'] = isset($stats[$product][$form_identifier][$browser_type.'_clients'])
				? $stats[$product][$form_identifier][$browser_type.'_clients']+1 : 1;
			$unique_ips[$product][$form_identifier][$log['REMOTEADDR']] = isset($unique_ips[$product][$form_identifier][$log['REMOTEADDR']])
				? $unique_ips[$product][$form_identifier][$log['REMOTEADDR']] : 0;
			$session_dates[$product][$form_identifier][$log['SESSION']]['start'] = strtotime($log['DATETIME']);
			$stats[$product][$form_identifier]['referral_sources'][$log['REFERRER']] = isset($stats[$product][$form_identifier]['referral_sources'][$log['REFERRER']]) 
				? $stats[$product][$form_identifier]['referral_sources'][$log['REFERRER']]+1 : 1;
		}else
		if($request == 'register' && $log['STATUSCODE'] == 200) {
			$session_dates[$product][$form_identifier][$log['SESSION']]['end'] = strtotime($log['DATETIME']);
			$unique_ips[$product][$form_identifier][$log['REMOTEADDR']] = isset($unique_ips[$product][$form_identifier][$log['REMOTEADDR']])
				? $unique_ips[$product][$form_identifier][$log['REMOTEADDR']]+1 : 1;
		}
	}
	foreach($session_dates as $product => $x){
		foreach($x as $form_identifier => $y){
			foreach($y as $session => $dates){
				if(!empty($dates['start']) && !empty($dates['end'])){
					$transaction_time = $dates['end']-$dates['start'];
					$stats[$product][$form_identifier]['transactions'] = isset($stats[$product][$form_identifier]['transactions'])
						? $stats[$product][$form_identifier]['transactions']+1 : 1;
					$stats[$product][$form_identifier]['transaction_time'] = isset($stats[$product][$form_identifier]['transaction_time'])
						? $stats[$product][$form_identifier]['transaction_time']+$transaction_time : $transaction_time;
				}
			}
		}
	}
	foreach($unique_ips as $product => $x){
		foreach($x as $form_identifier => $y){
			$stats[$product][$form_identifier]['unique_visitors'] = count($y);
			foreach($y as $ip => $t){
				if($t){
					$stats[$product][$form_identifier]['conversions'] = isset($stats[$product][$form_identifier]['conversions'])
						? $stats[$product][$form_identifier]['conversions']+1 : 1;
				}
			}
		}
	}
	fclose($handle);
}
echo '<pre>';
print_r($stats);
echo '</pre>';