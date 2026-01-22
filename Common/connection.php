<?php
// Common/connection.php

require_once __DIR__ . '/../vendor/autoload.php'; // For Dotenv

use Dotenv\Dotenv;

// Load .env from project root
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Get DB credentials from .env
$servername = $_ENV['DB_HOST'] ?? 'localhost';
$username   = $_ENV['DB_USER'] ?? 'root';
$password   = $_ENV['DB_PASS'] ?? '';
$dbname     = $_ENV['DB_NAME'] ?? 'restaurant';
$dbport     = $_ENV['DB_PORT'] ?? 3306;  // Optional, defaults to 3306

// Create connection with port support
$conn = new mysqli($servername, $username, $password, $dbname, (int)$dbport);

// Check connection
if ($conn->connect_error) {
    // In production, hide details — log instead
    error_log("Database Connection failed: " . $conn->connect_error);
    die("Connection failed. Please try again later.");
}

// Set timezone to Nepal (UTC+5:45)
$conn->query("SET time_zone = '+05:45'");
?>