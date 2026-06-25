<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo = getPDO();

$withDetails = isset($_GET['with_details']);

if ($withDetails) {
    $rows = $pdo->query("
        SELECT e.employee_id, e.name, e.email,
               GROUP_CONCAT(DISTINCT s.abbreviation ORDER BY s.name SEPARATOR ', ') AS sites,
               GROUP_CONCAT(DISTINCT c.name         ORDER BY c.name SEPARATOR ', ') AS campuses,
               COUNT(DISTINCT a.asset_id) AS asset_count
        FROM employees e
        LEFT JOIN employee_sites es   ON es.employee_id = e.employee_id
        LEFT JOIN sites s             ON s.site_id = es.site_id
        LEFT JOIN employee_campuses ec ON ec.employee_id = e.employee_id
        LEFT JOIN campuses c          ON c.campus_id = ec.campus_id
        LEFT JOIN assets a            ON a.assigned_employee_id = e.employee_id
        GROUP BY e.employee_id, e.name, e.email
        ORDER BY e.name
    ")->fetchAll();
} else {
    $rows = $pdo->query("SELECT employee_id, name, email FROM employees ORDER BY name")->fetchAll();
}

echo json_encode($rows);
