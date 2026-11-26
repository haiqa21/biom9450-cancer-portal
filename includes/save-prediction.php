<?php
/* Runs the model for one patient and saves the result.
*-THe model only run if the patient opted in to AI where patient ID consent = 1.
* Every run is saved fpr prediction (who, when which model),
*rediction results (a probability per cancer type) and 
*prediction mutations the patients mutation the model used.
* Calling page must already have checked that the signed in uer is oncologist
*/


require_once __DIR__ . '/predictor.php';
// saved in the predictions.model used so results can be traced to the model that made them.
//Change it whenever the model.json is trained.
const MODEL_VERSION = 'logreg-mutations-v1';
//Retuens ok => True, 'prediction_id'=> 12, 'result'=> [...]]
// or [ok => false, 'error' => safe message for the user ]

function run_and_save_prediction(PDO $pdo, int $patientId, int$userId): array
{

//Consent check on the server. Hiding a button is not enough.
$stmt = $pdo->prepare('SELECT ai_consent FROM patients WHERE patient_id =?');
$stmt->execute([$patientId]);
$patient = $stmt->fetch();
if ($patient === false){
    return['ok'=> false, 'error' => 'Patient not found.'];
}

if ((int)$patient['ai_consent'] !== 1){
    return ['ok' => false, 'error' => 'This patient has not agreed to AI analysis, so the model 
    was not run'];
}

//The patients mutations; gene name, mutation id and allele frequency
$stmt = $pdo->prepare(
    'SELECT g.gene_symbol, m.mutation_id, MAX(pm.allele_frequency) AS allele_frequency
         FROM samples s
         JOIN patient_mutations pm ON pm.sample_id = s.sample_id
         JOIN mutations m          ON m.mutation_id = pm.mutation_id
         JOIN genes g              ON g.gene_id = m.gene_id
         WHERE s.patient_id = ?
         GROUP BY g.gene_symbol, m.mutation_id'
    );
    $stmt->execute([$patientId]);
    $mutations = $stmt->fetchAll();
    if(!$mutations){
        return ['ok'=> false, 'error' => 'This patient has no recorded muatations, so the model cannot run.'];
    }
    $result = predict_cancer_type(array_unique(array_column($mutations,'gene_symbol')));
    if ($result === null){
        return ['ok'=> false, 'error' => 'The prediction model is not available right now.'];

    }
     // Save everything in ONE transaction: either all rows are saved or none 
     try{
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO predictions (patient_id, user_id, model_used) 
VALUES (?,?,?)');
        $stmt->execute([$patientId, $userId, MODEL_VERSION]);
        $predictionId = (int)$pdo->lastInsertId();

        // cancer name is id example: 'Giloma' => 4

        $typeIds = $pdo->query('SELECT cancer_name, cancer_type_id FROM cancer_types')
        ->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt = $pdo->prepare(
            'INSERT INTO prediction_results (prediction_id,cancer_type_id,prediction_probability)VALUES(?,?,?)'
        );
        foreach ($result['probabilities'] as $type => $probability){
            if(!isset($typeIds[$type])){
                throw new RuntimeException("Cancer type '$type' is 
                not in cancer_types");
                }
                $stmt->execute([$predictionId, $typeIds[$type],round($probability,4)]);
        }

        //only the mutations in genes the model knows where actually used 
        $used = array_fill_keys($result['genes_recognised'], true);
        $stmt = $pdo->prepare(
            'INSERT into prediction_mutations (prediction_id, mutation_id, allele_frequency) VALUES (?,?,?)'
        );

        foreach ($mutations as $m){
            if (isset($used[$m['gene_symbol']])){
                $stmt->execute([$predictionId,(int)$m['mutation_id'],
                $m['allele_frequency']]);
            }
        }
        $pdo->commit();
     }catch(Throwable $e){
        if ($pdo->inTransaction()){
            $pdo->rollBack();
        }
        error_log('Saving prediction failed:' . $e->getMessage());
        return ['ok'=> false,'error'=> 'The prediction could not be saved. Please try once again later'];

     }
     return ['ok' => true, 'prediction_id'=>$predictionId, 'result'=>$result];
}