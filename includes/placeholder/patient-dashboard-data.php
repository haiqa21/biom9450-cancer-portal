<?php
// PLACEHOLDER DATA: never commit. includes/placeholder/ is in .gitignore.
// Fake patient Emma Walsh from the CancerInsight demo data, in the same shape as the
// database queries in public/pages/patient-dashboard.php. Delete this folder to use the real database.

return [
    'user' => ['user_id' => 20, 'name' => 'Emma Walsh', 'role' => 'patient'],
    'patient' => ['patient_id' => 6, 'first_name' => 'Emma', 'last_name' => 'Walsh', 'birthdate' => '1958-12-03', 'sex' => 'female'],
    'diagnoses' => [
        ['cancer_name' => 'Breast', 'date_of_diagnosis' => '2022-02-05', 'stage' => 'II', 'status' => 'remission'],
    ],
    'samples' => [
        ['sample_code' => 'CI-001071_tumour_20240708', 'date_detected' => '2024-07-08', 'mutation_count' => 23],
    ],
    'mutations' => [
        ['gene_symbol' => 'ENSG00000047617', 'chromosome' => '12', 'genomic_position' => 6008367, 'original_allele' => 'C', 'altered_allele' => 'A', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.5604', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000106328', 'chromosome' => '7', 'genomic_position' => 127244841, 'original_allele' => 'G', 'altered_allele' => 'T', 'mutation_type' => 'downstream', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.3186', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000114757', 'chromosome' => '3', 'genomic_position' => 179534264, 'original_allele' => 'C', 'altered_allele' => 'G', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.2699', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000095951', 'chromosome' => '6', 'genomic_position' => 12168358, 'original_allele' => 'T', 'altered_allele' => 'G', 'mutation_type' => 'downstream', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.3397', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000144278', 'chromosome' => '2', 'genomic_position' => 155036024, 'original_allele' => 'C', 'altered_allele' => 'T', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.105', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000176597', 'chromosome' => '3', 'genomic_position' => 182968382, 'original_allele' => 'G', 'altered_allele' => 'A', 'mutation_type' => 'upstream', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.5665', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000197176', 'chromosome' => '14', 'genomic_position' => 98101759, 'original_allele' => 'G', 'altered_allele' => 'A', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.5379', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000106278', 'chromosome' => '7', 'genomic_position' => 121609956, 'original_allele' => 'T', 'altered_allele' => 'C', 'mutation_type' => 'downstream', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.3819', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000169607', 'chromosome' => '2', 'genomic_position' => 113509334, 'original_allele' => 'A', 'altered_allele' => 'G', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.4814', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000110693', 'chromosome' => '11', 'genomic_position' => 16009494, 'original_allele' => 'C', 'altered_allele' => 'G', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.3082', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000169744', 'chromosome' => '4', 'genomic_position' => 16603813, 'original_allele' => 'T', 'altered_allele' => '-', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.2497', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000124181', 'chromosome' => '20', 'genomic_position' => 39794641, 'original_allele' => 'A', 'altered_allele' => 'C', 'mutation_type' => 'upstream', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.5117', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000136811', 'chromosome' => '9', 'genomic_position' => 131251251, 'original_allele' => 'A', 'altered_allele' => 'G', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.4231', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000196358', 'chromosome' => '9', 'genomic_position' => 135109813, 'original_allele' => 'C', 'altered_allele' => 'T', 'mutation_type' => 'downstream', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.1928', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000188312', 'chromosome' => '9', 'genomic_position' => 95282263, 'original_allele' => 'T', 'altered_allele' => 'A', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.3163', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000087095', 'chromosome' => '17', 'genomic_position' => 26385690, 'original_allele' => 'G', 'altered_allele' => 'A', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.1768', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000168702', 'chromosome' => '2', 'genomic_position' => 142784794, 'original_allele' => 'A', 'altered_allele' => '-', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.3438', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000170522', 'chromosome' => '4', 'genomic_position' => 111088859, 'original_allele' => 'A', 'altered_allele' => 'T', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.5661', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000132549', 'chromosome' => '8', 'genomic_position' => 100141215, 'original_allele' => 'T', 'altered_allele' => 'A', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.1656', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000143479', 'chromosome' => '1', 'genomic_position' => 206821945, 'original_allele' => 'C', 'altered_allele' => 'T', 'mutation_type' => 'missense', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.3496', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000180230', 'chromosome' => '10', 'genomic_position' => 121758609, 'original_allele' => 'G', 'altered_allele' => 'A', 'mutation_type' => 'upstream', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.3469', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ENSG00000134640', 'chromosome' => '11', 'genomic_position' => 92704677, 'original_allele' => 'C', 'altered_allele' => 'T', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.4442', 'sample_code' => 'CI-001071_tumour_20240708'],
        ['gene_symbol' => 'ESR1', 'chromosome' => '6', 'genomic_position' => 152185965, 'original_allele' => 'T', 'altered_allele' => 'C', 'mutation_type' => 'intron', 'clinical_significance' => 'unknown', 'allele_frequency' => '0.1861', 'sample_code' => 'CI-001071_tumour_20240708'],
    ],
];
