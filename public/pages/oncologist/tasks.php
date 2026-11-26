<?php
session_start();

// Only logged-in oncologists can access this file
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'oncologist') {
    http_response_code(403);
    exit('Access denied.');
}

// Get the logged-in oncologist's user ID
$user_id = $_SESSION['user_id'];

// Connect to the existing CancerInsight database
require_once __DIR__ . '/../../includes/db.php';

// Handle adding a new task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {

    $task_title = trim($_POST['task_title'] ?? '');
    $task_description = trim($_POST['task_description'] ?? '');
    $due_date = $_POST['due_date'] ?? '';
    $patient_id = $_POST['patient_id'] ?? '';

    // Title is required
    if ($task_title === '') {
        exit('Task title is required.');
    }

    // Convert empty fields to NULL
    $task_description = $task_description !== '' ? $task_description : null;
    $due_date = $due_date !== '' ? $due_date : null;
    $patient_id = $patient_id !== '' ? (int)$patient_id : null;

        // Insert the task into the database
    $stmt = $pdo->prepare("
        INSERT INTO oncologist_tasks
        (user_id, patient_id, task_title, task_description, due_date)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $user_id,
        $patient_id,
        $task_title,
        $task_description,
        $due_date
    ]);

    // Return to the dashboard after adding the task
    header('Location: oncologist-dashboard.php');
    exit;
}
