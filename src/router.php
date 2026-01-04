<?php
require_once __DIR__ . "/db.php";

/* ================= HELPERS ================= */

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

function method() {
  return strtoupper($_SERVER["REQUEST_METHOD"]);
}

function path() {
  $uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
  return rtrim($uri, "/");
}

function cors() {
  header("Access-Control-Allow-Origin: *");
  header("Access-Control-Allow-Headers: Content-Type, Authorization");
  header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
  if (method() === "OPTIONS") exit;
}

cors();

$m = method();
$path = path();

/* ================= HEALTH ================= */

if ($m === "GET" && $path === "/api/health") {
  json_response([
    "ok" => true,
    "message" => "API working"
  ]);
}

/* ================= SETUP DB (RUN ONCE) ================= */
/* DELETE THIS BLOCK AFTER SUCCESS */

if ($m === "GET" && $path === "/api/setup-db") {
  $pdo = db();

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      email VARCHAR(120) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS exercises (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL UNIQUE,
      calories_per_minute DOUBLE NOT NULL
    )
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS plans (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      name VARCHAR(120) NOT NULL,
      items_json JSON NOT NULL,
      total_time DOUBLE NOT NULL DEFAULT 0,
      total_calories DOUBLE NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(user_id)
    )
  ");

  $pdo->exec("
    INSERT IGNORE INTO exercises (name, calories_per_minute) VALUES
    ('Push-ups', 5.0),
    ('Squats', 8.0),
    ('Plank', 4.0),
    ('HIT', 7.0),
    ('Jumping rope', 10.0)
  ");

  json_response([
    "ok" => true,
    "message" => "Tables created and exercises inserted"
  ]);
}

/* ================= EXERCISES ================= */

if ($m === "GET" && $path === "/api/exercises") {
  $pdo = db();
  $rows = $pdo
    ->query("SELECT id, name, calories_per_minute FROM exercises ORDER BY name")
    ->fetchAll(PDO::FETCH_ASSOC);

  json_response($rows);
}
/* ================= AUTH: REGISTER ================= */
/*
POST /api/register
Body: { "name": "...", "email": "...", "password": "..." }
*/
if ($m === "POST" && $path === "/api/register") {
  $pdo = db();
  $body = read_json();

  $name = trim($body["name"] ?? "");
  $email = trim($body["email"] ?? "");
  $password = (string)($body["password"] ?? "");

  if ($name === "" || $email === "" || strlen($password) < 4) {
    json_response(["error" => "Invalid data"], 400);
  }

  $hash = password_hash($password, PASSWORD_BCRYPT);

  try {
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$name, $email, $hash]);

    json_response([
      "id" => (int)$pdo->lastInsertId(),
      "name" => $name,
      "email" => $email
    ], 201);
  } catch (Exception $e) {
    json_response(["error" => "Email already used"], 409);
  }
}

/* ================= AUTH: LOGIN ================= */
/*
POST /api/login
Body: { "email": "...", "password": "..." }
*/
if ($m === "POST" && $path === "/api/login") {
  $pdo = db();
  $body = read_json();

  $email = trim($body["email"] ?? "");
  $password = (string)($body["password"] ?? "");

  if ($email === "" || $password === "") {
    json_response(["error" => "Invalid data"], 400);
  }

  $stmt = $pdo->prepare("SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1");
  $stmt->execute([$email]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user || !password_verify($password, $user["password_hash"])) {
    json_response(["error" => "Wrong email or password"], 401);
  }

  json_response([
    "id" => (int)$user["id"],
    "name" => $user["name"],
    "email" => $user["email"]
  ]);
}

/* ================= CREATE PLAN ================= */

if ($m === "POST" && $path === "/api/plans") {
  $pdo = db();
  $body = read_json();

  $user_id = (int)($body["user_id"] ?? 0);
  $name = trim($body["name"] ?? "My Plan");
  $items = $body["items"] ?? [];

  if ($user_id <= 0 || !is_array($items) || count($items) === 0) {
    json_response(["error" => "Invalid plan data"], 400);
  }

  $total_time = 0;
  $total_calories = 0;

  foreach ($items as $it) {
    $q = (int)($it["quantity"] ?? 0);
    $t = (float)($it["time"] ?? 0);
    $cpm = (float)($it["caloriesPerMinute"] ?? 0);

    if ($q > 0 && $t > 0 && $cpm > 0) {
      $total_time += $q * $t;
      $total_calories += $q * $t * $cpm;
    }
  }

  $stmt = $pdo->prepare("
    INSERT INTO plans (user_id, name, items_json, total_time, total_calories)
    VALUES (?, ?, ?, ?, ?)
  ");

  $stmt->execute([
    $user_id,
    $name,
    json_encode($items),
    $total_time,
    $total_calories
  ]);

  json_response([
    "id" => (int)$pdo->lastInsertId(),
    "total_time" => $total_time,
    "total_calories" => $total_calories
  ], 201);
}

/* ================= GET PLANS ================= */

if ($m === "GET" && $path === "/api/plans") {
  $pdo = db();
  $user_id = (int)($_GET["user_id"] ?? 0);

  if ($user_id <= 0) {
    json_response(["error" => "user_id is required"], 400);
  }

  $stmt = $pdo->prepare("
    SELECT id, name, items_json, total_time, total_calories, created_at
    FROM plans
    WHERE user_id = ?
    ORDER BY id DESC
  ");

  $stmt->execute([$user_id]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$r) {
    $r["items"] = json_decode($r["items_json"], true);
    unset($r["items_json"]);
  }

  json_response($rows);
}

/* ================= DELETE PLAN ================= */

if ($m === "DELETE" && preg_match("#^/api/plans/(\d+)$#", $path, $mch)) {
  $pdo = db();
  $id = (int)$mch[1];

  $stmt = $pdo->prepare("DELETE FROM plans WHERE id = ?");
  $stmt->execute([$id]);

  json_response(["deleted" => true]);
}

/* ================= NOT FOUND ================= */

json_response(["error" => "Not found"], 404);
