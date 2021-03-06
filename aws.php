<?php
if (count(getopt('', ['http']))) {

set_time_limit(0);
ob_implicit_flush();

iamApache(); die();

}

define("CRLF", "\r\n"); echo CRLF;
define("NAMETAG", "-test-ymedyanik");
$aws_phar_path = __DIR__.'/aws.phar';
$ssh_private_key = __DIR__.'/id_rsa';
$ssh_public_key = __DIR__.'/id_rsa.pub';

// Команды для выполнения внутри инстанса
// Упрощённо, без проверок на наличие монтирования / занятость тома / прочих
$command_set = <<<COUT
sudo mkfs -F /dev/sdb
sudo mount /dev/sdb /mnt
sudo yum install -y git
sudo yum install -y php71
sudo chmod 777 /mnt
git clone https://github.com/wdowny/owtest.git /mnt/owtest
touch /mnt/owtest/.git/hooks/post-merge
chmod +x /mnt/owtest/.git/hooks/post-merge
echo '#!/bin/bash' >> /mnt/owtest/.git/hooks/post-merge
echo 'sudo kill -9 $(pgrep -f aws.php)' >> /mnt/owtest/.git/hooks/post-merge
echo 'sudo nohup php /mnt/owtest/aws.php --http &' >> /mnt/owtest/.git/hooks/post-merge
COUT;

// Начало работы

// Тащим SDK для PHP. Упрощённо, без проверки на битый/неправильный phar, отсутствие классов и проч.
if (file_exists($aws_phar_path)) {
    include_once($aws_phar_path);
} else {
    echo "Trying to download AWS SDK... ";
    if ( file_put_contents($aws_phar_path, file_get_contents("https://docs.aws.amazon.com/aws-sdk-php/v3/download/aws.phar")) )
            echo "OK".CRLF;
        else
            echo "somethig wrong".CRLF;        
    if (! @include_once($aws_phar_path)) die('Cannot load AWS SDK!'.CRLF);
}
use Aws\Ec2\Ec2Client;

$ec2Client = new Ec2Client([
    'region' => 'eu-west-1',
    'version' => '2016-11-15',
    'profile' => 'default'
]);

// Генерим SSH ключ для нашего инстанса, кладём пару ключей "под себя" (в каталог скрипта)
$ssh_keyname = 'key'.NAMETAG;

$res = $ec2Client->describeKeyPairs([
    'Filters' => [
        ['Name' => 'key-name', 'Values' => ['key'.NAMETAG]]
    ],
]);
if (count($res->get('KeyPairs'))) {
    echo 'SSH key already exists, skipping.'.CRLF;
} else {
    echo 'Writing SSH key for new account'.CRLF;
    $res = $ec2Client->createKeyPair(['KeyName' => $ssh_keyname]);
    file_put_contents($ssh_private_key, $res['KeyMaterial']);
    chmod($ssh_private_key, 0600);
    `ssh-keygen -y -f $ssh_private_key > $ssh_public_key`;
}

// Проверяем наличие Security Group, при необходимости создаём, запоминаем ID.
$res = $ec2Client->describeSecurityGroups([
    'Filters' => [
        ['Name' => 'group-name', 'Values' => ['sgroup'.NAMETAG]]
    ],
]);

if (count($res->get('SecurityGroups'))) {
    echo 'Security Group already exists! Skipping.'.CRLF;
} else {
    $res = $ec2Client->createSecurityGroup(array(
        'GroupName'   => 'sgroup'.NAMETAG,
        'Description' => 'test (Y. Medyanik)'
    ));
    $sgID = $res->get('GroupId');
    $ec2Client->authorizeSecurityGroupIngress(array(
        'GroupName'     => 'sgroup'.NAMETAG,
        'IpPermissions' => [
            [
                'IpProtocol' => 'tcp',
                'FromPort'   => 80,
                'ToPort'     => 80,
                'IpRanges'   => [ ['CidrIp' => '0.0.0.0/0'] ],
            ],
            [
                'IpProtocol' => 'tcp',
                'FromPort'   => 22,
                'ToPort'     => 22,
                'IpRanges'   => [ ['CidrIp' => '0.0.0.0/0'] ],
            ]
        ]
    ));
}
echo 'Waiting for applying changes... '; sleep(3); echo 'Going to create instance.'.CRLF;
$res = $ec2Client->runInstances([
    'ImageId'        => 'ami-047bb4163c506cd98',
    'MinCount'       => 1,
    'MaxCount'       => 1,
    'InstanceType'   => 't2.micro',
    'KeyName'        => $ssh_keyname,
    'SecurityGroups' => ['sgroup'.NAMETAG],
    'ClientToken'    => '2018-10-11-test-task-29', // Обеспечивает создание инстанса в единственном экземпляре
]);

$instances = $res->get('Instances');
$avZone = $instances[0]['Placement']['AvailabilityZone'];
$xvdCount = count($instances[0]['BlockDeviceMappings']);
$iID = $instances[0]['InstanceId'];
echo $iID.CRLF;

$ec2Client->createTags([
        'Resources' => [$iID],
        'Tags' =>[ ['Key' => 'test-owner', 'Value' => 'Yuriy Medyanik'] ],
    ]); 

do {
    echo 'Getting public IPv4... '; sleep(5);
    $res = $ec2Client->describeInstances([
        'Filters' => [
            ['Name' => 'instance-id', 'Values' => [$iID]]
        ]
    ]);
    $instances = $res->get('Reservations')[0]['Instances'];
    $ipAddress = $instances[0]['NetworkInterfaces'][0]['Association']['PublicIp'];
} while (!$ipAddress); // или waitUntilInstanceRunning
echo $ipAddress.CRLF;

do {
    $sshConn = @ssh2_connect($ipAddress); echo '.';
} while (!$sshConn);
ssh2_auth_pubkey_file($sshConn, 'ec2-user', $ssh_public_key, $ssh_private_key);

if ($xvdCount<2) {
    echo 'Creating volume...'.CRLF;
    $resVolume = $ec2Client->createVolume([
        'AvailabilityZone' => $avZone,
        'Size'             => 1,
        'VolumeType'       => 'standard',
    ]);
    sleep(5); // There's a better way
    echo 'Attaching volume...'.CRLF;
    $volID = $resVolume['VolumeId'];
    $resAttach = $ec2Client->attachVolume([
        'Device'     => '/dev/sdb',
        'InstanceId' => $iID,
        'VolumeId'   => $volID
    ]);
    sleep(10); // There's a better way
    echo 'Additional volume attached.'.CRLF;
    foreach (explode("\n", $command_set) as $v) {
        sleep(2); ssh2_exec($sshConn, $v); echo '.'; // По-хорошему, надо проверять результаты.
    }
} else {
    echo 'Volume already exists, skipping.'.CRLF;
}

ssh2_exec($sshConn, 'sudo nohup php /mnt/owtest/aws.php --http &');

$url='http://ops:works@'.$ipAddress.'/';

$res = @file_get_contents($url);
if ($res) {
    echo 'Rocket launch successful! HTTP returns:'.CRLF.CRLF.$res.CRLF.CRLF;
    echo 'Check URL: '.$url.CRLF;
} else {
    echo 'Sorry, something goes wrong...'.CRLF;
}





// Блок определений

// Вытаскиваем текущий коммит
function getCurrentCommit() {
    $t = file_get_contents(__DIR__.'/.git/HEAD'); if (!$t) return false;
    $ref = trim(str_replace('ref: ', '', $t));
    return trim(file_get_contents(__DIR__.'/.git/' . $ref));
}

// Вытаскиваем нагрузку (для простоты берём из ОС)
function getResourceUsage() {
    $pid = getmypid(); 
    return `ps -p {$pid} -o%cpu,%mem,rss`; 
}

// Наш веб-сервер

function iamApache () {
    
$bind_address = '0.0.0.0';
$bind_port = 80;
$http_user='ops';
$http_pass='works';

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($sock, $bind_address, $bind_port);
socket_listen($sock, 3);
$i = 0;
do {
    $conn = socket_accept($sock);
    $headers = []; $last_header = ''; $prev_header = '';

    do { // читаем HTTP-запрос от клиента
        $buffer = socket_read($conn, 8192, PHP_NORMAL_READ);
        if ($buffer === false) die("Socket error!");
        $prev_header = $last_header;
        $last_header = trim($buffer);
        if ($last_header) {
            if (strstr($last_header, ': '))
                    list($h, $t) = explode(': ', $last_header);
                else
                    list($h, $t) = [$last_header, ''];
                        
            $headers[strtolower(trim($h))] = $t;
        }
        if ($last_header == false && $prev_header == false) break;
    } while (true);
    $i++; // счётчик числа ответов. Чтоб был.
    $authorized = false;
    // проверяем авторизацию
    if (isset($headers['authorization']) && strstr($headers['authorization'], 'Basic')) {
        $auth_pair = base64_decode(trim(str_replace('Basic', '', $headers['authorization'])));
        if ($auth_pair == sprintf('%s:%s', $http_user, $http_pass)) $authorized = true;
    }
    if ($authorized) {
        $payload  = "PID: " . getmypid() . "\r\n";
        $payload .= "Requests completed: ". $i . "\r\n";
        $payload .= ($current_commit = getCurrentCommit()) ? ("Git commit: " . $current_commit . "\r\n") : ("Cannot get Git status!\r\n");
        $payload .= ($res_usage = getResourceUsage()) ? ("Resource usage:\r\n" . $res_usage . "\r\n") : ("Cannot get CPU/mem usage");
        $response = "HTTP/1.1 200 OK\r\n\r\n" . $payload . "\r\n";
    } else {
        $resp_headers = "HTTP/1.1 401 Access Denied\r\n";
        $resp_headers .= "WWW-Authenticate: Basic realm=\"AREA 51\"\r\n";
//        $resp_headers .= "Content-Length: 0\r\n";
        $payload = '401 UnAuthorized!';
        $response = $resp_headers . "\r\n" . $payload . "\r\n";
    }
    socket_write($conn, $response, strlen($response));
    socket_read($conn, 8192, PHP_NORMAL_READ);
    socket_shutdown($conn, 2); socket_close($conn); 
} while (true);

socket_close($sock);
return true;
}