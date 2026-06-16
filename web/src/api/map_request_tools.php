<?php
//通过tools.php/curl代理访问链接xpath解析数据
// function fetch_steam_item(string $url)
// {
// 	$html = curl_proxy($url);
// 	// 解析 HTML
// 	libxml_use_internal_errors(true);
// 	$doc = new DOMDocument();
// 	@$doc->loadHTML($html);
// 	$xpath = new DOMXPath($doc);
// 	// 1. 检查tag是否包含 "campaign"
// 	$type_nodes = $xpath->query('//div[contains(@class,"detailsStatsContainerRight")]/div[@class="detailsStatRight"]/a');
// 	$is_campaign = false;
// 	foreach ($type_nodes as $node) {
// 		$type_text = strtolower(trim($node->nodeValue));
// 		if (strpos($type_text, 'campaign') !== false) {
// 			$is_campaign = true;
// 			break;
// 		}
// 	}
// 	if (!$is_campaign) return false;
// 	// 2. 获取标题
// 	$title_node = $xpath->query('//*[@class="workshopItemTitle"]')->item(0);
// 	if (!$title_node) return false;
// 	$title = trim($title_node->nodeValue);
// 	// 3. 获取大小
// 	$size = '未知';
// 	$size_xpaths = [
// 		'//*[@id="mainContents"]//div[@class="detailsStatsContainerRight"]/div[@class="detailsStatRight"]',
// 	];
// 	foreach ($size_xpaths as $xp) {
// 		$nodes = $xpath->query($xp);
// 		foreach ($nodes as $node) {
// 			$text = trim($node->nodeValue);
// 			if (preg_match('/[0-9.]+\s*(MB|KB|GB)/i', $text)) {
// 				$size = $text;
// 				break 2;
// 			}
// 		}
// 	}
// 	return [
// 		'title' => $title,
// 		'size'  => $size
// 	];
// }
/**
 * 通过steamworkshopdownloader.io API获取steam item信息
 * @param string $steam_id
 * @return array|false [
 * 'title' => 'Moscow',
 * 'disk_safe' => 'moscow',
 * 'size' => '252912840', //以字节为单位
 * 'downlink' => 'https://cdn.steamusercontent.com/ugc/102172/4EACD668/',
 * 'preview_url'
 * 'description' => 'd.[/list]\n\nMap\n[/list]\n\n',
 * 'subscriptions' => '100',
 * ]
 */
function fetch_steam_item_by_api($steam_id)
{
	$url = 'https://steamworkshopdownloader.io/api/details/file';
	$headers = [
		'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
		'Origin: https://steamworkshopdownloader.io',
		'Referer: https://steamworkshopdownloader.io/',
		'Content-Type: application/x-www-form-urlencoded',
	];
	$post_fields = '[' . $steam_id . ']';
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_POSTFIELDS => $post_fields,
		CURLOPT_ENCODING => '',  // 支持 gzip/deflate/br/zstd
		CURLOPT_TIMEOUT => 10,
	]);
	$response = curl_exec($ch); //[ {"title":"Moscow",} ]
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	// curl_close($ch); //PHP8.4+会自动关闭
	if ($http_code !== 200 || !$response) {
		return false;
	}
	$json = json_decode($response, true);
	if (!is_array($json) || !isset($json[0])) return false;
	$item = $json[0];
	// {
	//     "title":"Moscow",
	//     "filename":"moscow.vpk",
	//     "title_disk_safe":"moscow",
	//     "file_size":"252912840",
	//     "file_url":"https://cdn.steamusercontent.com/ugc/10237922614266676172/4E9FDB44AD0E4242F9678D684482EE93B0ACD668/",
	//     "preview_url":"https://images.steamusercontent.com/ugc/13925408907420949368/09544A4FFE50CD25A2AD44A7E527B9D852E57BC5/",
	//     "file_description":"d.[/list]\n\nMap\n[/list]\n\n",
	//     "time_created":1749365722,
	//     "time_updated":1750450321,
	//     "num_comments_public":135,
	//     "subscriptions":16278,
	//     "favorited":1475,
	//     "views":29686,
	//     "tags":[{"tag":"Campaigns","adminonly":false},{"tag":"",""}]
	// }
	// 检查是否含有 Campaign 标签
	$tags = [];
	$is_map = false;
	if (isset($item['tags']) && is_array($item['tags'])) {
		foreach ($item['tags'] as $tag) {
			if (strtolower($tag['tag']) === 'campaigns') $is_map = true;
			$tags[] = $tag['tag'];
		}
	}
	//检测title_disk_safe是否存在
	if (!isset($item['title_disk_safe']) || $item['title_disk_safe'] === '') {
		$item['title_disk_safe'] = str_replace('.vpk', '', $item['filename']);
	}
	// 返回结果
	return [
		'app_name'=> $item['app_name'],
		'is_map' => $is_map,
		'version' => $item['time_updated'],
		'tags' => $tags,
		'title' => $item['title'],
		'disk_safe' => $item['title_disk_safe'],
		'downlink' => $item['file_url'],
		'size' => $item['file_size'],
		'preview_url' => $item['preview_url'],
		'description' => $item['file_description'],
		'subscriptions' => $item['subscriptions'],
	];
}
// maps表
// | Field       | Type                          | Null | Key | Default               | Extra                                              |
// |-------------|-------------------------------|------|-----|-----------------------|----------------------------------------------------|
// | id          | int unsigned                 | NO   | PRI | NULL                  | auto_increment                                    |
// | size        | int unsigned                 | YES  |     |                       | 以字节为单位，最大2047MB                          |
// | title       | varchar(256)                 | YES  |     |                       |                                                    |
// | link        | varchar(256)                 | YES  |     |                       |                                                    |
// | description | text                         | YES  |     |                       |                                                    |
// | img_urls    | text                         | YES  |     |                       |                                                    |
// | preview_url | varchar(256)                 | YES  |     |                       |                                                    |                                              |
// | records     | json                         | YES  |     |                       | api无法获取                                             |
// | status      | enum('abandon','updating','active') | NO   |     | abandon               |                                                    |
// | downlink    | varchar(256)                 | YES  |     | NULL                  |                                                    |
// | steam_id    | bigint unsigned              | NO   | UNI | NULL                  |                                                    |
// | created_at  | datetime                     | NO   |     | CURRENT_TIMESTAMP     | DEFAULT_GENERATED                                 |
// | updated_at  | datetime                     | NO   |     | CURRENT_TIMESTAMP     | DEFAULT_GENERATED on update CURRENT_TIMESTAMP     |
// | disk_safe   | varchar(256)                 | YES  |     |                       |                                                    |
// map_requests表
// | Field       | Type                          | Null | Key | Default               | Extra                                              |
// |-------------|-------------------------------|------|-----|-----------------------|----------------------------------------------------|
// | id          | int unsigned                 | NO   | PRI | NULL                  | auto_increment                                    |
// | steam_id    | bigint unsigned              | NO   | MUL |                       |                                                    |
// | title       | varchar(256)                 | YES  |     |                       |                                                    |
// | downlink    | varchar(256)                 | YES  |     |                       |                                                    |
// | status      | enum('rejected','pending','approved') | NO   |     | rejected              |                                                    |
// | explaination| text                         | YES  |     |                       |                                                    |
// | created_at  | datetime                     | NO   |     | CURRENT_TIMESTAMP     | DEFAULT_GENERATED                                 |
// | updated_at  | datetime                     | NO   |     | CURRENT_TIMESTAMP     | DEFAULT_GENERATED on update CURRENT_TIMESTAMP     |
// | description | text                         | YES  |     |                       |                                                    |
// | size        | int unsigned                 | YES  |     |                       | 以字节为单位，最大2047MB                                      |
// | link        | varchar(256)                 | YES  |     |                       |                                                    |
// | preview_url    | varchar(256)                         | YES  |     |                       |                                                    |
// | disk_safe   | varchar(256)                 | YES  |     |                       |                                                    |
// select id,steam_id,title,status from map_requests where steam_id=;
// select id,steam_id,title,status from maps where status='updating';
//检查maps\map_requests中是否已有地图
function fetch_db_item($pdo, $steam_id)
{
	//先看map_requests表中是否已有审核，多一条m_rid方便后续绑定用户
	// 只看pending是因为已批准和拒绝都是过时的，除非审核时间太长导致构造
	$stmt = $pdo->prepare("
		SELECT id,status,title,disk_safe,link,created_at,updated_at,size 
		FROM map_requests WHERE steam_id = ? and status = 'pending'
	");
	$stmt->execute([$steam_id]);
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	if ($result) return $result;
	//后看maps表中是否已有
	$stmt = $pdo->prepare("
		SELECT id,version,status,title,disk_safe,downlink,size,link,created_at,updated_at
		FROM maps WHERE steam_id = ?
	");
	$stmt->execute([$steam_id]);
	//steam_id有unique约束，所以只会返回一条记录
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	if ($result) return $result;
	return false;
}
function fetch_map_request($pdo, $request_id=null, $steam_id = null, $status = null)
{
	if ($request_id != null) {
		try {
			$stmt = $pdo->prepare("SELECT * FROM map_requests WHERE id = ?");
			$stmt->execute([$request_id]);
			return array_success($stmt->fetch(PDO::FETCH_ASSOC));
		} catch (PDOException $e) {
			return array_error($e->getMessage());
		}
	} elseif ($steam_id != null) {
		try {
			if($status!=null){
				$stmt = $pdo->prepare("SELECT * FROM map_requests WHERE steam_id = ? AND status = ?");
				$stmt->execute([$steam_id, $status]);
			} else{
				$stmt = $pdo->prepare("SELECT * FROM map_requests WHERE steam_id = ?");
				$stmt->execute([$steam_id]);
			}
			return array_success($stmt->fetch(PDO::FETCH_ASSOC));
		} catch (PDOException $e) {
			return array_error($e->getMessage());
		}
	} else {
		return array_error('fetch_map_request: steam_id和request_id不能同时为null');
	}
}
/**
 * 
 * 构建完整map_requests
 */
function build_map_request($steam_id){
	//id,created_at,updated_at(自动填充)
		//1.1已经确定的in_maps,steam_id,link,status
		$map_request['in_maps'] = false;
		$map_request['steam_id'] = $steam_id;
		$map_request['link'] = "https://steamcommunity.com/sharedfiles/filedetails/?id={$steam_id}";
		$map_request['status'] = 'pending';
		//1.2数据库或api获取信息后填充title,version,description,disk_safe,downlink,size,preview_url
		//1.2.1先从历史申请记录中查询地图信息
		// $result = fetch_map_request($pdo, null, $steam_id);
		// if ($result['success'] && !empty($result['data'])){//有则直接使用历史请求的地图信息
		// 	$map_info = $result['data'];
		// } else { //没有缓存，从steam页面获取地图信息title,size等	
			
		// }
		//1.2.1不看缓存，直接看api
		$map_info = fetch_steam_item_by_api($steam_id); 
		if (!$map_info) return array_error("无法从数据库或api获取对应的地图{$steam_id}信息");
		if($map_info['app_name']!='Left 4 Dead 2')$map_info['explaination'].='该物品对应的游戏'.$map_info['app_name'].'不是Left 4 Dead 2';
		//1.2.2填充信息
		$map_request['title'] = $map_info['title'];
		$map_request['version'] = $map_info['version'];
		$map_request['description'] = $map_info['description'];
		$map_request['disk_safe'] = $steam_id;
		$map_request['downlink'] = $map_info['downlink'];
		$map_request['size'] = $map_info['size'];
		$map_request['preview_url'] = $map_info['preview_url'];
		$map_request['is_map'] = $map_info['is_map'];
		$map_request['explaination'] = $map_info['explaination'] ?? '';
		$map_request['subscriptions'] = $map_info['subscriptions'] ?? '';
		//!!!下面不合理，应交给外部调用
		//1.2.3确定explaination，请求的文件不是地图（有时候地图前置不是campaign）
		if(!$map_request['is_map']){//在请求中添加说明explaination
			$map_request['explaination'] .= '这不是地图文件，请点击steam链接仔细鉴别';
		}
	return array_success($map_request);
}
/**
 * 绑定申请与用户
 * @return array ['success'=>true,'data'=>user_request_id] ['success'=>false,'message'=>'']
 */
function bind_user_to_request($pdo, $request_id, $user_id)
{
	$stmt = $pdo->prepare("INSERT INTO map_request_users (request_id, user_id) VALUES (?, ?)");
	try {
		$stmt->execute([$request_id, $user_id]);
	} catch (PDOException $e) {
		return array_error($e->getMessage());
	}
	return array_success($pdo->lastInsertId());
}
/**
 * 查找请求关联的用户
 * @param PDO $pdo
 * @param int $request_id
 */
function fetch_users_by_request($pdo, $request_id){
	$stmt = $pdo->prepare("SELECT user_id FROM map_request_users WHERE request_id = ?");
	$stmt->execute([$request_id]);
	return array_success($stmt->fetchAll(PDO::FETCH_COLUMN));
}
/**
 * 插入map_requsts
 * 
 * @param PDO $pdo
 * @param array $request 
 * in_maps,steam_id,link,status,
 * title,version,description,disk_safe,downlink,size,preview_url,
 * is_map,explaination
 * @return array ['success'=>true,'data'=>request_id] ['success'=>false,'message'=>'']
*/
function insert_map_request($pdo, $request)
{
	$stmt = $pdo->prepare("insert into map_requests(
			in_maps,steam_id,link,status,
			title,version,description,disk_safe,downlink,size,preview_url,
			is_map,explaination,subscriptions
			)
			values (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
		");
		try{
			$stmt->execute([
				intval($request['in_maps']),
				$request['steam_id'],
				$request['link'],
				$request['status'],
				$request['title'],
				$request['version'],
				$request['description'],
				$request['disk_safe'],
				$request['downlink'],
				$request['size'],
				$request['preview_url'],
				intval($request['is_map']),
				$request['explaination'],
				$request['subscriptions']
			]);
		} catch (PDOException $e) {
		  return array_error("插入请求失败".$e->getMessage());
		}
	$request_id = $pdo->lastInsertId();
	return array_success($request_id);
}
/**
 * 插入maps
 * 
 * @param PDO $pdo
 * @param array $map
 * steam_id,link,status,
 * title,version,description,disk_safe,downlink,size,preview_url,
 * is_map
 * @return array ['success'=>true,'data'=>map_id] ['success'=>false,'message'=>'']
*/
function insert_map($pdo, $map)
{
	$stmt = $pdo->prepare("insert into maps(
			steam_id,link,status,
			title,version,description,disk_safe,downlink,size,preview_url,
			is_map
			)
			values (?,?,?,?,?,?,?,?,?,?,?)
		");
		try{
			$stmt->execute([
				$map['steam_id'],
				$map['link'],
				$map['status'],
				$map['title'],
				$map['version'],
				$map['description'],
				$map['disk_safe'],
				$map['downlink'],
				$map['size'],
				$map['preview_url'],
				(int)$map['is_map'],
			]);
		} catch (PDOException $e) {
		  return array_error($e->getMessage());
		}
	$map_id = $pdo->lastInsertId();
	return array_success($map_id);
}
/**
 * 更新maps
 * 
 * @param PDO $pdo
 * @param array $map
 * steam_id,link,status,
 * title,version,description,disk_safe,downlink,size,preview_url,
 * is_map
 * @return array ['success'=>true,'data'=>map_id] ['success'=>false,'message'=>'']
*/
function update_map_info($pdo, $map)
{
	$stmt = $pdo->prepare("
		update maps set
		steam_id=?,link=?,title=?,version=?,description=?,
		disk_safe=?,downlink=?,size=?,preview_url=?,is_map=?
		where id=?
	");
		try{
			$stmt->execute([
				$map['steam_id'],$map['link'],$map['title'],$map['version'],$map['description'],
				$map['disk_safe'],$map['downlink'],$map['size'],$map['preview_url'],(int)$map['is_map'],
				$map['id']
			]);
		} catch (PDOException $e) {
		  return array_error($e->getMessage());
		}
	$map_id = $pdo->lastInsertId();
	return array_success($map_id);
}
/**
 * 检查下载链接是否失效
 * @return ['valid': bool, 'error': string]
 */
function isDownloadLinkValid($url, $timeout = 5) {
    $ch = curl_init($url);
    
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,      // HEAD 请求，不下载内容
        CURLOPT_FOLLOWLOCATION => true,  // 跟随重定向
        CURLOPT_MAXREDIRS => 5,      // 最大重定向次数
        CURLOPT_TIMEOUT => $timeout, // 超时时间
        CURLOPT_SSL_VERIFYPEER => true,  // 验证 SSL
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LinkChecker/1.0)',
        CURLOPT_RETURNTRANSFER => true,
    ]);
    
    curl_exec($ch);
    
    // 检查是否有错误
    if (curl_errno($ch)) return ['valid' => false, 'error' => curl_error($ch)];
    
    // 获取 HTTP 状态码
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // 2xx 表示有效
    $isValid = ($httpCode >= 200 && $httpCode < 400);
    
    return [
        'valid' => $isValid,
        'http_code' => $httpCode,
        'error' => $isValid ? null : "HTTP {$httpCode}"
    ];
}
// /**
//  * 确保请求下载信息非空
//  * 空则通过steam_id在map_requests表或api查
//  * @param PDO $pdo
//  * @param array $request ['id','steam_id']
//  * @return array ['success'=>true','data'=>['disk_safe','size','downlink']] ['success'=>false,'message'=>'']
//  */
// function ensure_request_info($pdo,$request){
//   //1.信息完整，直接返回
// 	if (
// 		$request['id'] && 
// 		$request['steam_id'] && 
// 		$request['downlink'] && 
// 		$request['disk_safe'] && 
// 		$request['size']
// 	) return array_success($request);
// 	//2.信息不完整，依据steam_id从可信的地方获取
// 	if(!$request['steam_id']) return array_error('steam_id为空,无法获取信息');
// 	$steam_id = $request['steam_id'];
// 	//2.1 数据库-已批准的请求
// 	$stmt = $pdo->prepare("SELECT downlink,disk_safe,size FROM map_requests WHERE steam_id=? AND status='approved'");
// 	$result = exec_stmt($stmt,$steam_id);
// 	$stmt = $result['data'];
// 	if($stmt && $stmt->rowCount() > 0){
// 		$info = $stmt->fetch(PDO::FETCH_ASSOC);
// 	} else {//2.2 api
//     	$info = fetch_steam_item_by_api($steam_id);
// 		if(!$info)return array_error('无法从api获取数据');
// 		if($info['app_name']!='Left 4 Dead 2')return array_error('不是有效的L4D2地图');
// 	}
// 	//两个地方都查不到，报错
//   if (!$info || empty($info['downlink']) || empty($info['disk_safe'])|| empty($info['size'])) {
//       return array_error("无法从数据库或api填充信息。");
//   }
// 	//3.填充信息并返回
// 	$request['downlink'] = $info['downlink'];
// 	$request['disk_safe'] = $info['disk_safe'];
// 	$request['size'] = $info['size'];
//   return array_success($request);
// }
