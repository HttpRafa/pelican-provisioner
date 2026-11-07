<?php

declare(strict_types=1);

// Turns the configuration the panel generates for the node into one that works when Wings runs in Docker.
// Reads that YAML on stdin and the existing Docker subnets as arguments, and prints the result.

require '/var/www/html/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$stackDir = rtrim((string) getenv('STACK_DIR'), '/');
$subnet = (string) (getenv('WINGS_SUBNET') ?: '10.213.0.0/16');

if ($stackDir === '' || $stackDir[0] !== '/') {
    fwrite(STDERR, "STACK_DIR must be the absolute path of the docker directory.\n");

    exit(1);
}

/** @return array{int, int}|null */
function cidr_range(string $cidr): ?array
{
    if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $parts) !== 1) {
        return null;
    }

    $address = ip2long($parts[1]);
    $bits = (int) $parts[2];

    if ($address === false || $bits > 32) {
        return null;
    }

    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
    $start = $address & $mask;

    return [$start, $start | (~$mask & 0xFFFFFFFF)];
}

$wanted = cidr_range($subnet);

if ($wanted === null || $wanted[1] - $wanted[0] < 4) {
    fwrite(STDERR, "WINGS_SUBNET must be an IPv4 network such as 10.213.0.0/16.\n");

    exit(1);
}

foreach (array_slice($argv, 1) as $existing) {
    $range = cidr_range($existing);

    if ($range !== null && $range[0] <= $wanted[1] && $wanted[0] <= $range[1]) {
        fwrite(STDERR, "WINGS_SUBNET $subnet overlaps the Docker network $existing. Set WINGS_SUBNET to a free range.\n");

        exit(1);
    }
}

$config = Yaml::parse((string) stream_get_contents(STDIN));

if (!is_array($config)) {
    fwrite(STDERR, "The panel did not return a node configuration.\n");

    exit(1);
}

$gateway = long2ip($wanted[0] + 1);

// Wings calls the panel over the compose network. The browser keeps using APP_URL, which is also the only origin allowed to open the console.
$config['allowed_origins'] = [(string) $config['remote']];
$config['remote'] = 'http://panel';

$config['system']['tmp_directory'] = $stackDir.'/wings/tmp';
$config['system']['user']['passwd']['directory'] = $stackDir.'/wings/etc';
$config['system']['machine_id']['directory'] = $stackDir.'/wings/etc/machine-id';

$config['docker']['network'] = [
    'name' => 'pelican-provisioner-dev-servers',
    'network_mode' => 'pelican-provisioner-dev-servers',
    'interface' => $gateway,
    'interfaces' => ['v4' => ['subnet' => long2ip($wanted[0]).'/'.explode('/', $subnet)[1], 'gateway' => $gateway]],
];

echo Yaml::dump($config, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
