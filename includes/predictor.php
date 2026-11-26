<?php
/* runs the trained cancer type model in php. 
/* same maths as predicted() in ml/train_mutation.py
/* 1. turn the patients mutated gene into 0/1 in the models gene order 
/* 2. score for each cancer type = bias + sum of weight x 0/1
/* 3. softmax: turn the scores into probabilities that add up to 1*/


// Loads model.json once. Returns the model as array, or null if it is missing or broken.

function load_model(): ?array
{
    static $model = false;

    if($model === false){
        $model = null;
        $file = __DIR__ . '/../ml/model.json'; // fixed path and never from the URL
        if (is_readable($file)){
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data) && isset($data['genes'], $data['cancer_types'], $data['weights'], $data['bias'])) {
                $model = $data;
            }
        }
    }
    if ($model === null){
        error_log('predictor: ml/model.json is missing or invalid');  }
        return $model;
}

//Predicts the cancer tyoe from a list of mutated gene symbols 
// return null if model cant be loaded 
function predict_cancer_type(array $patientGenes): ?array
{
    $model = load_model();
    if ($model === null) {
        return null;
    }

    //0/1 for each of the models 200 genes 
    $hasGene = array_fill_keys($patientGenes, true);   // fast "is this gene mutated?" lookup
    $x = [];
    $recognised = [];
    foreach ($model['genes'] as $gene) {
        $x[] = isset($hasGene[$gene]) ? 1 : 0;
        if (isset($hasGene[$gene])) {
            $recognised[] = $gene;
        }
    }

    // one score per cancer type 
    $scores = [];
    foreach ($model['cancer_types'] as $type) {
        $score = $model['bias'][$type];
        foreach ($model['weights'][$type] as $i => $weight) {
            $score += $weight * $x[$i];
        }
        $scores[$type] = $score;
    }
    // softmax. subtracting the largest score first stop exp() overflowing;
    //it does not chaneg the result.
    $max = max($scores);
    $total = 0.0;
    foreach ($scores as $score) {
        $total += exp($score - $max);
    }
    $probabilities = [];
    foreach ($scores as $type => $score) {
        $probabilities[$type] = exp($score - $max) / $total;
    }
    arsort($probabilities);                      // highest first, names kept

    $top = array_key_first($probabilities);
    $confidence = $probabilities[$top];
    $threshold = (float)($model['confidence_threshold'] ?? 0.6);

    //the patient gene that pushes te top type up most (poitive weight)
    $topGenes = [];
    foreach ($model['genes'] as $i => $gene) {
        $weight = $model['weights'][$top][$i];
        if ($x[$i] === 1 && $weight > 0) {
            $topGenes[$gene] = $weight;
        }
    }
    arsort($topGenes);
    $topGenes = array_slice($topGenes, 0, 5, true);

    return [
        'probabilities'    => $probabilities,             // e.g. ['Colorectal' => 0.92, ...]
        'top_type'         => $top,
        'confidence'       => $confidence,
        'threshold'        => $threshold,
        'uncertain'        => $confidence < $threshold || !$recognised,
        'genes_recognised' => $recognised,                // patient genes the model knows
        'top_genes'        => $topGenes,                  // gene => weight, max 5
    ];
}