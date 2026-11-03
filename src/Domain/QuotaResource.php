<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

enum QuotaResource: string
{
    case Cpu = 'cpu';
    case Memory = 'memory';
    case Disk = 'disk';
    case Servers = 'servers';
}
