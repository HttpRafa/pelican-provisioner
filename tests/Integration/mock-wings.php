<?php

declare(strict_types=1);

// Stands in for Wings. /tmp/wings-status holds the HTTP status to answer with, and the node's token id makes the answer count as coming from that node.

$status = (int) (@file_get_contents('/tmp/wings-status') ?: 204);

file_put_contents('/tmp/mock-wings.log', $_SERVER['REQUEST_METHOD'].' '.$_SERVER['REQUEST_URI'].' '.$status."\n", FILE_APPEND);

header('User-Agent: Pelican Wings/v1.0.0 (id:'.(@file_get_contents('/tmp/wings-token-id') ?: 'unknown').')');
http_response_code($status);
