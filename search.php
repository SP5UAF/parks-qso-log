<?php
// Search moved to index.php - kept for old links/bookmarks.
// Forwards the query string (e.g. search.php?station_call=SP5UAF -> index.php?...).
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: index.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
