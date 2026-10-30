<?php

/**
 * Builds a large contacts-upload.xlsx for worker/queue tests.
 * Usage: php scripts/generate-contacts-upload-xlsx.php [rows]
 */

require __DIR__.'/../vendor/autoload.php';

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

ini_set('memory_limit', '512M');

$rows = max(1, (int) ($argv[1] ?? 500000));
$dir = __DIR__.'/../storage/app/testing';

if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
    fwrite(STDERR, "Could not create {$dir}\n");
    exit(1);
}

$path = $dir.'/contacts-upload-'.$rows.'.xlsx';
$started = microtime(true);

$writer = new Writer;
$writer->openToFile($path);
$writer->addRow(Row::fromValues([
    'first_name',
    'last_name',
    'email',
    'gender',
    'phone_number',
    'date_of_birth',
    'region',
    'city',
    'gps_address',
    'address',
    'dnd_status',
]));

$regions = ['Greater Accra', 'Ashanti', 'Northern', 'Western', 'Eastern'];
$cities = ['Accra', 'Kumasi', 'Tamale', 'Takoradi', 'Koforidua'];
$genders = ['male', 'female'];

for ($i = 0; $i < $rows; $i++) {
    $nine = (string) (244000000 + $i);
    $writer->addRow(Row::fromValues([
        'Test',
        'User'.$i,
        'user'.$i.'@example.com',
        $genders[$i % 2],
        '0'.$nine,
        '', // leave DOB empty so the group unique-dob index does not skip almost every row
        $regions[$i % 5],
        $cities[$i % 5],
        'GA-'.str_pad((string) ($i % 1000), 3, '0', STR_PAD_LEFT).'-'.str_pad((string) ($i % 10000), 4, '0', STR_PAD_LEFT),
        'House '.$i,
        'inactive',
    ]));

    if ($i > 0 && $i % 50000 === 0) {
        fwrite(STDOUT, "wrote {$i} rows\n");
    }
}

$writer->close();

$bytes = filesize($path);
$seconds = round(microtime(true) - $started, 1);

fwrite(STDOUT, $path."\n");
fwrite(STDOUT, 'rows='.$rows.' size_bytes='.$bytes.' size_mb='.round($bytes / 1048576, 2).' seconds='.$seconds."\n");
