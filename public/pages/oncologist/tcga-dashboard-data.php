<?php
session_start();

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

// Authentication
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'oncologist'
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access.'
    ]);

    exit();
}

try {
    // Samples grouped by cancer type
    $samplesQuery = $pdo->query("
        SELECT
            cancer_type,
            COUNT(*) AS sample_count
        FROM tcga_samples
        WHERE cancer_type IS NOT NULL
        GROUP BY cancer_type
        ORDER BY sample_count DESC
        LIMIT 10
    ");

    $samples = $samplesQuery->fetchAll(PDO::FETCH_ASSOC);


    // PASS variants grouped by cancer type
    $passQuery = $pdo->query("
        SELECT
            s.cancer_type,
            COUNT(v.variant_id) AS pass_count
        FROM tcga_samples s
        INNER JOIN tcga_variants v
            ON s.sample_id = v.sample_id
        WHERE s.cancer_type IS NOT NULL
          AND v.filter_status = 'PASS'
        GROUP BY s.cancer_type
        ORDER BY pass_count DESC
        LIMIT 10
    ");

    $passVariants = $passQuery->fetchAll(PDO::FETCH_ASSOC);


    // PASS vs non-PASS variants
    $filterQuery = $pdo->query("
        SELECT
            filter_status,
            COUNT(*) AS variant_count
        FROM tcga_variants
        GROUP BY filter_status
    ");

    $filterCounts = $filterQuery->fetchAll(PDO::FETCH_ASSOC);


    echo json_encode([
        'success' => true,
        'samples_by_cancer_type' => $samples,
        'pass_variants_by_cancer_type' => $passVariants,
        'variant_filter_distribution' => $filterCounts
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve TCGA data.'
    ]);
}
