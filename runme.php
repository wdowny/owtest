<?php
set_time_limit(0);
ob_implicit_flush();

iamApache();

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


// вытаскиваем текущий коммит
function getCurrentCommit() {
    $t = file_get_contents(__DIR__.'/.git/HEAD'); if (!$t) return false;
    $ref = trim(str_replace('ref: ', '', $t));
    return trim(file_get_contents(__DIR__.'/.git/' . $ref));
}

// вытаскиваем нагрузку (для простоты берём из ОС)
function getResourceUsage() {
    $pid = getmypid(); 
    return `ps -p {$pid} -o%cpu,%mem,rss`; 
}

/* TODO
 * 
 * check GIT commit not from current but from script directory
 * (also aws.phar deployment)
 * 
 * hardcode SSH keypait into the script
 * 
 * SSH subsystem - install git, php, pull
 * 
 * git afterpull hook
 * 
 * daemonize script
 */