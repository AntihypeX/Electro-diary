<?php
require_once __DIR__ . "/../api/cabinet.php";
header("Cache-Control: no-store");
$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    exit();
}
$_SESSION["csrf_token"] ??= bin2hex(random_bytes(32));
$id = $_SESSION["user_id"] ?? $user["id"];
$db = connectDB();
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["image"])) {
    $row = cabQuery(
        $db,
        "SELECT mime,content FROM public.user_avatars WHERE auth_id=?",
        [$id],
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        exit();
    }
    header("Content-Type: " . $row["mime"]);
    header("X-Content-Type-Options: nosniff");
    echo base64_decode($row["content"]);
    exit();
}
header("Content-Type: application/json; charset=utf-8");
try {
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if (
            !is_string($_POST["csrf"] ?? null) ||
            !hash_equals($_SESSION["csrf_token"], $_POST["csrf"])
        ) {
            throw new RuntimeException("Обновите страницу.");
        }
        if (($_POST["action"] ?? "") === "logout") {
            logout();
            header("Location: index.php");
            exit();
        }
        $f = $_FILES["avatar"] ?? null;
        if (
            !$f ||
            $f["error"] !== UPLOAD_ERR_OK ||
            $f["size"] > 2097152 ||
            !is_uploaded_file($f["tmp_name"])
        ) {
            throw new RuntimeException("Выберите изображение до 2 МБ.");
        }
        $info = @getimagesize($f["tmp_name"]);
        if (
            !$info ||
            !in_array(
                $info["mime"],
                ["image/jpeg", "image/png", "image/webp"],
                true,
            ) ||
            $info[0] > 2048 ||
            $info[1] > 2048
        ) {
            throw new RuntimeException(
                "Нужны PNG, JPEG или WebP, до 2048 × 2048 пикселей.",
            );
        }
        cabQuery(
            $db,
            "INSERT INTO public.user_avatars(auth_id,mime,content) VALUES (?,?,?) ON CONFLICT(auth_id) DO UPDATE SET mime=EXCLUDED.mime,content=EXCLUDED.content",
            [
                $id,
                $info["mime"],
                base64_encode(file_get_contents($f["tmp_name"])),
            ],
        );
    }
    echo json_encode([
        "ok" => true,
        "csrf" => $_SESSION["csrf_token"],
        "exists" => (bool) cabQuery(
            $db,
            "SELECT auth_id FROM public.user_avatars WHERE auth_id=?",
            [$id],
        )->fetchColumn(),
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(
        [
            "error" =>
                $e instanceof PDOException
                    ? "Примените миграцию 04-cabinets.sql."
                    : $e->getMessage(),
        ],
        JSON_UNESCAPED_UNICODE,
    );
}
