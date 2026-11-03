<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class ServerName
{
    public static function parse(mixed $name): string
    {
        if (!is_string($name)) {
            throw new InvalidServerNameException();
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new InvalidServerNameException();
        }

        return $name;
    }
}
