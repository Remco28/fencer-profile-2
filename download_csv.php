<?php
ob_start();          // ensure no stray whitespace is sent first
session_start();
$data = $_SESSION['csv'] ?? [];

if (!$data) {
    echo "No CSV data available. Go back and generate links first.";
    exit;
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="fencers.csv"');

$fp = fopen('php://output', 'w');
fputcsv($fp, ['Name','Club','Event','Rating','URL']);
foreach ($data as $row) {
    fputcsv($fp, [
        $row['name'],
        $row['club'],
        $row['event'],
        $row['rating'],
        $row['url']
    ]);
}
fclose($fp);
?>
