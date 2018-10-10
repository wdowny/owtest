<?php
define("CRLF", "\r\n"); echo CRLF;
define("NAMETAG", "-test-ymedyanik");
$aws_phar_path = __DIR__.'/aws.phar';
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
    'region' => 'ap-southeast-1',
    'version' => '2016-11-15',
    'profile' => 'default'
]);

// Проверяем наличие Security Group, при необходимости создаём, запоминаем ID.
$res = $ec2Client->describeSecurityGroups([
    'Filters' => [
        ['Name' => 'group-name', 'Values' => ['sgroup'.NAMETAG]]
    ],
]);

if (count($res->get('SecurityGroups'))) {
    echo 'Security Group already exists! Skipping creation.'.CRLF;
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

$res = $ec2Client->runInstances(array(
    'ImageId'        => 'ami-08569b978cc4dfa10',
    'MinCount'       => 1,
    'MaxCount'       => 1,
    'InstanceType'   => 't2.micro',
    'KeyName'        => 'kayo',
    'SecurityGroups' => ['sgroup'.NAMETAG],
    'ClientToken'    => '2018-10-10-test-task-03', // Обеспечивает создание инстанса в единственном экземпляре
));



/* Commands to install 
 * 
 * sudo yum install -y git
 * sudo yum install -y php71
 * 
 */