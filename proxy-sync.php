<?php
use Symfony\Component\Yaml\Yaml;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use GuzzleHttp\Client;
use React\Http\Server as HttpServer;
use React\Socket\Server as SocketServer;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Response;

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__FILE__).'/vendor/autoload.php';

$configFile = isset($argv[1])?realpath($argv[1]) : dirname(__FILE__).'/config.yml';
$config = Yaml::parseFile($configFile);
$pollInterval = (int)$config['orchestrator']['pollInterval'];
if($pollInterval == 0) {
    $pollInterval = 10;
}
$logger = new Logger('proxy-sync');
$logger->pushHandler(new StreamHandler('php://stderr',$logger::ERROR));
$logger->pushHandler(new StreamHandler('php://stdout',$logger::DEBUG));
$logger->info('Welcome to proxy-sync');
$logger->info('Polling interval: '.$pollInterval.' seconds');
$logger->info('Using config file "'.$configFile.'"');
//Poll the Github Orchestrator API and look for masters/slaves
$callOrchestratorApi = function() use ($config, $logger) {

    $servers = new stdClass();
    $servers->masters = [];
    $servers->slaves = [];

    $index = rand(0, count($config['orchestrator']['servers'])-1);
    $client = new Client([
        'base_uri' => $config['orchestrator']['servers'][$index]['url']
    ]);
    $response = $client->request(
        'GET',
        'cluster/alias/'.$config['orchestrator']['clusterAlias'],
        [
            'auth' => [
                $config['orchestrator']['servers'][$index]['username'],
                $config['orchestrator']['servers'][$index]['password']
            ]
        ]
    );
    $body = json_decode($response->getBody());
    if($response->getStatusCode() !== 200) {
        throw new Exception($body['message']);
    }
    unset($client);
    foreach ($body as $server) {
        if($server->IsDowntimed) {
            $logger->debug('Server "'.$server->Key->Hostname.'" is in scheduled downtime until '.$server->DowntimeEndTimestamp.' by '.$server->DowntimeOwner.': ' . $server->DowntimeReason);
        }
        elseif($server->IsLastCheckValid){
            if($server->MasterKey->Hostname == ""){
                $servers->masters[] = $server->Key->Hostname;
                $logger->debug('Master: '.$server->Key->Hostname);
            } elseif($server->Slave_SQL_Running == false || $server->Slave_IO_Running == false) {
                $logger->debug('Slave not replicating: '.$server->Key->Hostname);
            } else {
                $servers->slaves[] = $server->Key->Hostname;
                $logger->debug('Slave: '.$server->Key->Hostname);
            }
        }
    }
    return $servers;
};

//Update the ProxySql config based on Github Orchestrator API data
$updateProxySql = function($servers, $force = false) use ($config, $logger) {
    $changes = false;
    $proxySqlServers = [];
    //Setup PDO connections with each ProxySql server
    foreach ($config['proxysql']['servers'] as $proxySqlServer){
        $proxySqlServers[$proxySqlServer['hostname']] = new PDO("mysql:dbname=main;host={$proxySqlServer['hostname']};port={$proxySqlServer['port']}",
            $proxySqlServer['username'],
            $proxySqlServer['password']);
        $proxySqlServers[$proxySqlServer['hostname']]->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $proxySqlServers[$proxySqlServer['hostname']]->setAttribute(PDO::ATTR_PERSISTENT, true);
    }

    $serversInOrchestrator = array_merge($servers->masters,$servers->slaves);
    sort($serversInOrchestrator);

    //Check for changes
    foreach ($proxySqlServers as $proxySqlHostname => $proxySqlServer){
        $serversInProxySql = [];
        foreach($proxySqlServer->query('SELECT hostname FROM `mysql_servers` ORDER BY hostname') as $server) {
            $serversInProxySql[] = $server['hostname'];
        }
        if($serversInProxySql != $serversInOrchestrator) {
            $changes = true;
            $logger->debug('Detected changes on ProxySQL server '.$proxySqlHostname);
        }
    }
    if($force) {
        $changes = true;
        $logger->debug('Forcing changes');
    }
    if(!$changes){
        $logger->debug('No changes detected');
        return true;
    }
    //Insert data into tables
    foreach ($proxySqlServers as $proxySqlHostname => $proxySqlServer){
        try {
            $proxySqlServer->beginTransaction();
            $proxySqlServer->query('DELETE FROM `mysql_servers`');
            $logger->debug('Deleted ProxySQL config on '.$proxySqlHostname);
            foreach ($servers->masters as $master) {
                $proxySqlServer->query("INSERT INTO `mysql_servers` (`hostgroup_id`,`hostname`,`port`) VALUES ('0','".$master."','3306')");
                $logger->debug('Inserting master '.$master);
            }
            $logger->debug('Inserted masters on ProxySQL server '.$proxySqlHostname);
            foreach ($servers->slaves as $slave) {
                $proxySqlServer->query("INSERT INTO `mysql_servers` (`hostgroup_id`,`hostname`,`port`) VALUES ('1','".$slave."','3306')");
                $logger->debug('Inserting slave '.$slave);
            }
            $logger->debug('Inserted slaves on ProxySQL server '.$proxySqlHostname);
            $proxySqlServer->query('LOAD MYSQL SERVERS FROM MEMORY');
            $proxySqlServer->query('SAVE MYSQL SERVERS TO DISK');
            $logger->debug('Commited changes to ProxySQL server '.$proxySqlHostname);
        } catch (Exception $e ){
            $proxySqlServer->rollback();
        }
    }
    return true;
};

$updateProxySql($callOrchestratorApi());
$loop = React\EventLoop\Factory::create();

//Force ProxySql update based on HTTP call
$server = new HttpServer(function (ServerRequestInterface $request) use ($callOrchestratorApi, $updateProxySql, $logger) {
    $logger->debug('Web request triggered from '.$request->getServerParams()['REMOTE_ADDR']);
    $updateProxySql($callOrchestratorApi(),true);
    return new Response(
        200,
        array(
            'Content-Type' => 'text/plain'
        ),
        "OK\n"
    );
});

$socket = new SocketServer($config['proxy-sync']['binding'], $loop);
$server->listen($socket);

//Periodic ProxySql update
$loop->addPeriodicTimer($pollInterval, function () use ($callOrchestratorApi, $updateProxySql) {
    $updateProxySql($callOrchestratorApi());
});


$loop->run();