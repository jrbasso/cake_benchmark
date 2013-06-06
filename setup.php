<?php

require 'vendor/autoload.php';

define('SECURITY_GROUP_NAME', 'cake_benchmark');
define('KEY_PAIR_NAME', 'Cake Benchmark');
define('INSTANCE_NAME', 'Cake Benchmark');

use Aws\Common\Aws;
use Aws\Ec2\Exception\Ec2Exception;

// Create a service building using shared credentials for each service
$aws = Aws::factory(__DIR__ . '/config.php');

$ec2Client = $aws->get('ec2');

// Create the security group
echo "Creating security group... ";
try {
	$securityGroups = $ec2Client->describeSecurityGroups(array('GroupNames' => array(SECURITY_GROUP_NAME)));
	$groupId = $securityGroups['SecurityGroups'][0]['GroupId'];
} catch (Ec2Exception $e) {
	if ($e->getExceptionCode() === 'InvalidGroup.NotFound') {
		try {
			$response = $ec2Client->createSecurityGroup(array(
				'GroupName' => SECURITY_GROUP_NAME,
				'Description' => 'CakePHP Benchmark'
			));
			$groupId = $response['GroupId'];
		} catch (Ec2Exception $e) {
			// @todo ?
			throw $e;
		}
	} else {
		throw $e;
	}
}
echo "OK\n";

// Give permissions to access thru web
echo "Giving permission to access on port 80... ";
try {
	$ec2Client->authorizeSecurityGroupIngress(array(
		'GroupId' => $groupId,
		'IpPermissions' => array(
			array(
				'IpProtocol' => 'tcp',
				'FromPort' => 80,
				'ToPort' => 80,
				'IpRanges' => array(
					array('CidrIp' => '0.0.0.0/0')
				)
			)
		)
	));
} catch (Ec2Exception $e) {
	// Ignore if duplicated
	if ($e->getExceptionCode() !== 'InvalidPermission.Duplicate') {
		throw $e;
	}
}
echo "OK\n";

// Give permissions to access thru console
echo "Giving permission to access on port 22... ";
try {
	$ec2Client->authorizeSecurityGroupIngress(array(
		'GroupId' => $groupId,
		'IpPermissions' => array(
			array(
				'IpProtocol' => 'tcp',
				'FromPort' => 22,
				'ToPort' => 22,
				'IpRanges' => array(
					array('CidrIp' => '0.0.0.0/0')
				)
			)
		)
	));
} catch (Ec2Exception $e) {
	// Ignore if duplicated
	if ($e->getExceptionCode() !== 'InvalidPermission.Duplicate') {
		throw $e;
	}
}
echo "OK\n";

echo "Setting key pair... ";
if (!file_exists(__DIR__ . '/aws_rsa')) {
	exec('ssh-keygen -t rsa -b 2048 -f ' . escapeshellarg(__DIR__ . '/aws_rsa') . ' -N ""');
}
try {
	$ec2Client->describeKeyPairs(array('KeyNames' => array(KEY_PAIR_NAME)));
} catch (Ec2Exception $e) {
	$ec2Client->importKeyPair(array(
		'KeyName' => KEY_PAIR_NAME,
		'PublicKeyMaterial' => file_get_contents(__DIR__ . '/aws_rsa.pub')
	));
}
echo "OK\n";

echo "Creating instance... ";
$instances = $ec2Client->describeInstances(array('Filter' => array('Name' => INSTANCE_NAME)))->toArray();
$instanceExists = false;
if (!empty($instances['Reservations'])) {
	foreach ($instances['Reservations'] as $reservation) {
		foreach ($reservation['Instances'] as $instance) {
			if ($instance['State']['Name'] === 'running') {
				$instanceExists = true;
				break 2;
			}
		}
	}
}

if (!$instanceExists) {
	$instance = $ec2Client->runInstances(array(
		'ImageId' => 'ami-8785eeee',
		'MinCount' => 1,
		'MaxCount' => 1,
		'KeyName' => KEY_PAIR_NAME,
		'SecurityGroupIds' => array($groupId),
		'InstanceType' => 't1.micro',
	));

	$ec2Client->createTags(array(
		'Resources' => array($instance['Instances'][0]['InstanceId']),
		'Tags' => array(
			array('Key' => 'Name', 'Value' => INSTANCE_NAME)
		)
	));
}
echo "OK\n";

echo "Getting instance hostname... ";
$tries = 60;
$instanceHostname = '';
while ($tries-- > 0) {
	$instances = $ec2Client->describeInstances(array('Filter' => array('Name' => INSTANCE_NAME)))->toArray();
	if (!empty($instances['Reservations'])) {
		foreach ($instances['Reservations'] as $reservation) {
			foreach ($reservation['Instances'] as $instance) {
				if ($instance['State']['Name'] === 'running') {
					$instanceHostname = $instance['PublicDnsName'];
					break 3;
				}
			}
		}
	}
	sleep(1);
}
echo $instanceHostname, "\n";

echo "Connecting to the box... ";
while (!$sshConnection = @ssh2_connect($instanceHostname)) {
	echo "FAIL ";
	sleep(10);
}
ssh2_auth_pubkey_file($sshConnection, 'root', __DIR__ . '/aws_rsa.pub', __DIR__ . '/aws_rsa');
echo "OK\n";

echo "Executing the basic setup (this may take a few minutes)... ";
function remoteExec($conn, $command) {
	$stream = ssh2_exec($conn, $command);
	$errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
	stream_set_blocking($errorStream, true);
	stream_set_blocking($stream, true);
	stream_get_contents($stream);
	stream_get_contents($errorStream);
	fclose($stream);
	fclose($errorStream);
}

remoteExec($sshConnection, 'add-apt-repository ppa:ondrej/php5 -y');
remoteExec($sshConnection, 'apt-get update && apt-get upgrade -y');
remoteExec($sshConnection, 'apt-get install -y debconf-utils');
remoteExec($sshConnection, 'echo "mysql-server-5.5 mysql-server/root_password_again password root" | debconf-set-selections');
remoteExec($sshConnection, 'echo "mysql-server-5.5 mysql-server/root_password password root" | debconf-set-selections');
remoteExec($sshConnection, 'apt-get install -q -y apache2 php5 mysql-server git');

echo "OK\n";

echo "Cloning the repo on the box... ";
remoteExec($sshConnection, 'cd /var/www && git clone --recurse-submodules https://github.com/jrbasso/cake_benchmark.git');
echo "OK\n";

echo "Installing dependencies for the test... ";
remoteExec($sshConnection, 'apt-get install -q -y php-pear php5-dev make unzip');
remoteExec($sshConnection, 'pecl install xdebug');
ssh2_scp_send($sshConnection, __DIR__ . '/contrib/1-xdebug.ini', '/etc/php5/conf.d/1-xdebug.ini');
remoteExec($sshConnection, 'service apache2 restart');
remoteExec($sshConnection, 'wget https://webgrind.googlecode.com/files/webgrind-release-1.0.zip -O /tmp/webgrind-release-1.0.zip');
remoteExec($sshConnection, 'unzip /tmp/webgrind-release-1.0.zip -d /var/www/');
echo "OK\n";

echo "Server startup done. You can access at http://{$instanceHostname}\n";
