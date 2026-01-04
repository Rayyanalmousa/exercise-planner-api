<?php
require_once __DIR__ . "/db.php";

/* ---------- Helpers ---------- */

function json_response($data, int $code = 200) {
  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($data);
  exit;
}

function read_json() {
  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function path() {
  $uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
  return rtrim($uri, "/");
}

function method() {
  return strtoupper($_SERVER["REQUEST_METHOD"]);
}

function cors() {
  header("Access-Control-Allow-Origin: *");
  header("Access-Control-Allow-Headers: Content-Type, Authorization");
  header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
  if (method() === "OPTIONS") exit;
}

cors();

$path = path();
$m = method();

/* ---------- DEBUG (temporary) ---------- */
/* Visit /api/debug-env to confirm Railway env vars */
if ($m === "GET" && $path === "/api/debug-env") {
  json_response([
    "DB_HOST" => getenv("DB_HOST") ?: null,
    "DB_PORT" => getenv("DB_PORT") ?: null,
    "DB_NAME" => getenv("DB_NAME") ?: null,
    "DB_USER" => getenv("DB_USER") ?: null,
    "DB_PASS_set" => getenv("DB_PASS") ? true : false,
  ]);
}

/* ---------- Health ---------- */
if ($m === "GET" && $path === "/api/health") {
  json_response([
    "ok" => true,
    "message" => "API working"
  ]);
}

/* ---------- Exercises ---------- */
if ($m === "GET" && $path === "/api/exercises") {
  $pdo = db();
  $rows = $pdo->query(
    "SELECT id, name, calories_per_minute FROM exercises ORDER BY name"
  )->fetchAll();
  json_response($rows);
}

/* ---------- Create Plan ---------- */
/*
POST /api/plans
Body:
{
  "user_id": 1,
  "name": "My Plan",
  "items": [
    {
      "name": "Push-ups",
      "quantity": 2,
      "time": 3,
      "caloriesPerMinute": 5
    }
  ]
}
*/
if ($m === "POST" && $path === "/api/plans") {
  $pdo = db();
  $body = read_json();

  $user_id = (int)($body["user_id"] ?? 0);
  $name = trim($body["name"] ?? "My Plan");
  $items = $body["items"] ?? [];

  if ($user_id <= 0 || !is_array($items) || count($items) === 0) {
    json_response(["error" => "Invalid plan data"], 400);
  }

  $total_time = 0.0;
  $total_calories = 0.0;

  foreach ($items as $it) {
    $q = (int)($it["quantity"] ?? 0);
    $t = (float)($it["time"] ?? 0);
    $cpm = (float)($it["caloriesPerMinute"] ?? 0);
    if ($q > 0 && $t > 0 && $cpm > 0) {
      $total_time += ($t * $q);
      $total_calories += ($cpm * $t * $q);
    }
  }

  $items_json = json_encode($items);

  $stmt = $pdo->prepare(
    "INSERT INTO plans (user_id, name, items_json, total_time, total_calories)
     VALUES (?, ?, ?, ?, ?)"
  );
  $stmt->execute([
    $user_id,
    $name,
    $items_json,
    $total_time,
    $total_calories
  ]);

  json_response([
    "id" => (int)$pdo->lastInsertId(),
    "total_time" => $total_time,
    "total_calories" => $total_calories
  ], 201);
}

/* ---------- Get Plans ---------- */
if ($m === "GET" && $path === "/api/plans") {
  $pdo = db();
  $user_id = (int)($_GET["user_id"] ?? 0);

  if ($user_id <= 0) {
    json_response(["error" => "user_id is required"], 400);
  }

  $stmt = $pdo->prepare(
    "SELECT id, name, items_json, total_time, total_calories, created_at
     FROM plans
     WHERE user_id = ?
     ORDER BY id DESC"
  );
  $stmt->execute([$user_id]);
  $rows = $stmt->fetchAll();

  foreach ($rows as &$r) {
    $r["items"] = json_decode($r["items_json"], true);
    unset($r["items_json"]);
  }

  json_response($rows);
}

/* ---------- Delete Plan ---------- */
if ($m === "DELETE" && preg_match("#^/api/plans/(\d+)$#", $path, $matches)) {
  $pdo = db();
  $id = (int)$matches[1];

  $stmt = $pdo->prepare("DELETE FROM plans WHERE id = ?");
  $stmt->execute([$id]);

  json_response(["deleted" => true]);
}

/* ---------- Not Found ---------- */
json_response(["error" => "Not found"], 404);
