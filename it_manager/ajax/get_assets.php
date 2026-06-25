<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo = getPDO();

if (isset($_GET['next_tag'])) {
    echo json_encode(['next_tag' => generateAssetTag($pdo)]);
    exit;
}

$search   = '%' . trim($_GET['search']   ?? '') . '%';
$category = (int)($_GET['category'] ?? 0);
$status   = trim($_GET['status']   ?? '');

$sql = "
    SELECT a.asset_id, a.asset_tag, a.make, a.model, a.serial_number, a.status,
           ac.name AS category_name,
           e.name  AS employee_name,
           l.name  AS location_name,
           c.name  AS campus_name
    FROM assets a
    LEFT JOIN asset_categories ac ON ac.category_id = a.category_id
    LEFT JOIN employees e ON e.employee_id = a.assigned_employee_id
    LEFT JOIN locations l ON l.location_id = a.assigned_location_id
    LEFT JOIN campuses c ON c.campus_id = l.campus_id
    WHERE (a.asset_tag LIKE ? OR a.make LIKE ? OR a.model LIKE ? OR a.serial_number LIKE ?)
";
$params = [$search, $search, $search, $search];

if ($category) { $sql .= " AND a.category_id = ?"; $params[] = $category; }
if ($status)   { $sql .= " AND a.status = ?";       $params[] = $status;   }

$sql .= " ORDER BY a.asset_tag";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll());
