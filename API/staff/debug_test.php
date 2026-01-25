<?php
header('Content-Type: application/json');
echo json_encode([
    'status' => 'alive_minimal',
    'time' => date('c'),
    'microtime' => microtime(true)
]);  