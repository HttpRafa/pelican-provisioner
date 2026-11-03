<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class ServerName
{
    /** Control characters, line separators, private-use characters, text-direction overrides, and the byte-order mark. They can hide or reorder what a name appears to say. */
    private const string UNSAFE = '/[\p{Cc}\p{Cs}\p{Co}\p{Zl}\p{Zp}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    private const string VISIBLE = '/[\p{L}\p{N}\p{P}\p{S}]/u';

    public static function parse(mixed $name): string
    {
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidServerNameException();
        }

        $name = trim($name);

        if (mb_strlen($name) > 255 || preg_match(self::UNSAFE, $name) !== 0 || preg_match(self::VISIBLE, $name) !== 1) {
            throw new InvalidServerNameException();
        }

        return $name;
    }
}
