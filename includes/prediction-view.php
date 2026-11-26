<?php
/* Loads one saved prediction so a page can show it:
 * the probability for each cancer type, the confidence flag, and the genes
 * behind the call. Used by the researcher comparison page and the clinician view.*/

require_once __DIR__ . '/predictor.php';   // load_model() for the threshold and gene weights

// Returns the prediction as an array, or null when it doesn't exist or the patient hasn't agreed to 
//AI use (researchers must not see it then).
function load_prediction_view(PDO $pdo, int $predictionId): ?array
{
    //The prediction itself, only for patients who opted in
    $stmt = $pdo->prepare(
        'SELECT pr.prediction_id, pr.patient_id, pr.model_used, pr.created_at
         FROM predictions pr
         JOIN patients p ON p.patient_id = pr.patient_id
         WHERE pr.prediction_id = ? AND p.ai_consent = 1'
    );
    $stmt->execute([$predictionId]);
    $prediction = $stmt->fetch();
    if ($prediction === false) {
        return null;
    }

    // probability for each cancer type, highest first
    $stmt = $pdo->prepare(
        'SELECT ct.cancer_name, r.prediction_probability
         FROM prediction_results r
         JOIN cancer_types ct ON ct.cancer_type_id = r.cancer_type_id
         WHERE r.prediction_id = ?
         ORDER BY r.prediction_probability DESC'
    );
    $stmt->execute([$predictionId]);
    $probabilities = [];
    foreach ($stmt->fetchAll() as $row) {
        $probabilities[$row['cancer_name']] = (float)$row['prediction_probability'];
    }
    if (!$probabilities) {
        return null;
    }
    $topType = array_key_first($probabilities);
    $confidence = $probabilities[$topType];

    // The genes the model used for this patient (saved in prediction_mutations)
    $stmt = $pdo->prepare(
        'SELECT DISTINCT g.gene_symbol
         FROM prediction_mutations pm
         JOIN mutations m ON m.mutation_id = pm.mutation_id
         JOIN genes g     ON g.gene_id = m.gene_id
         WHERE pm.prediction_id = ?'
    );
    $stmt->execute([$predictionId]);
    $inSample = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

    // Gene weights for the top type: this patient's genes that pushed it up
    //    come first, then the strongest other genes, 10 in total
    $model = load_model();
    $genes = [];
    $threshold = 0.6;
    if ($model !== null && isset($model['weights'][$topType])) {
        $threshold = (float)($model['confidence_threshold'] ?? 0.6);
        $weights = array_combine($model['genes'], $model['weights'][$topType]);
        arsort($weights);                                   // strongest first
        $mine = $others = [];
        foreach ($weights as $gene => $weight) {
            if ($weight <= 0) {
                break;                                      // only genes that push towards this type
            }
            if (isset($inSample[$gene])) {
                $mine[] = ['gene' => $gene, 'weight' => round($weight, 3), 'in_sample' => true];
            } else {
                $others[] = ['gene' => $gene, 'weight' => round($weight, 3), 'in_sample' => false];
            }
        }
        $genes = array_slice(array_merge($mine, $others), 0, 10);
    }

    return [
        'prediction_id' => (int)$prediction['prediction_id'],
        'patient_id'    => (int)$prediction['patient_id'],
        'model_used'    => $prediction['model_used'],
        'created_at'    => $prediction['created_at'],
        'probabilities' => $probabilities,          
        'top_type'      => $topType,
        'confidence'    => $confidence,
        'threshold'     => $threshold,
        'uncertain'     => $confidence < $threshold,
        'genes'         => $genes,                    // [['gene' => 'TTN', 'weight' => 0.31, 'in_sample' => true], ...]
    ];
}