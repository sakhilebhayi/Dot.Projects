<?php

namespace App\Support\Exports;

/**
 * A zip archive of stored (uncompressed) files, written by hand so an export never depends on the
 * host's zip extension. Office files and every unzip tool read stored entries.
 */
final class Zip
{
    /** @param  array<string, string>  $files  path inside the archive => contents */
    public static function build(array $files): string
    {
        [$time, $date] = self::dosStamp();
        $body = '';
        $central = '';
        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $size = strlen($data);
            $offset = strlen($body);
            $fields = pack('vvvvvVVVvv', 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0);
            $body .= pack('V', 0x04034B50).$fields.$name.$data;
            $central .= pack('V', 0x02014B50).pack('v', 20).$fields.pack('vvvVV', 0, 0, 0, 0, $offset).$name;
        }

        return $body.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($central), strlen($body), 0);
    }

    /** @return array{0: int, 1: int} the current time and date in the MS-DOS format zip uses */
    private static function dosStamp(): array
    {
        $t = getdate();

        return [($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2), (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday']];
    }
}
