<?php

declare(strict_types=1);

namespace VibrantHub;

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function login(string $email, string $password): bool
    {
        self::startSession();
        $db = Database::connection();
        $stmt = $db->prepare("SELECT * FROM admins WHERE email = :email AND is_active = 1 LIMIT 1");
        $stmt->execute(["email" => $email]);
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($password, $admin["password_hash"])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION["admin_id"] = (int) $admin["id"];
        $_SESSION["admin_name"] = $admin["name"];
        $_SESSION["admin_email"] = $admin["email"];
        $_SESSION["admin_role"] = $admin["role"];
        return true;
    }

    public static function check(): bool
    {
        self::startSession();
        return isset($_SESSION["admin_id"]);
    }

    public static function admin(): ?array
    {
        self::startSession();
        if (!self::check()) {
            return null;
        }
        return [
            "id" => $_SESSION["admin_id"],
            "name" => $_SESSION["admin_name"],
            "email" => $_SESSION["admin_email"],
            "role" => $_SESSION["admin_role"]
        ];
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }

    public static function requireAdmin(): void
    {
        if (!self::check()) {
            header("Location: /admin/login.php");
            exit;
        }
    }
}
