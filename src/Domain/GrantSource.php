<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

enum GrantSource: string
{
    case Override = 'override';
    case Default = 'default';
}
