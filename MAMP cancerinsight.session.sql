UPDATE cancerinsight.patients SET ai_consent = 1 WHERE patient_id = 3;
INSERT INTO cancerinsight.users (username, email, password_hash, role) VALUES ('test_onc', 'onc@test.local', 'x', 'professional');
SELECT patient_id, ai_consent FROM cancerinsight.patients WHERE patient_id IN (2, 3);