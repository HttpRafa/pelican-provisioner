<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

enum ProvisioningFailure: string
{
    case Forbidden = 'forbidden';
    case EggNotAllowed = 'egg_not_allowed';
    case Quota = 'quota_exceeded';
    case NoNode = 'no_viable_node_found';
    case NoAllocation = 'no_viable_allocation_found';
    case Variables = 'invalid_variables';
    case Busy = 'busy';
    case InvalidSettings = 'invalid_settings';
    case InvalidName = 'invalid_name';
    case Unknown = 'unknown_server_creation_error';
}
