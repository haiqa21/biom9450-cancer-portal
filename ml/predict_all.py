"""Give every patient a model prediction, using the two models from ml/train.py.

    python ml/predict_all.py
    python ml/predict_all.py --data path/to/TCGA_2000_matched_profiles

Run database/build/import_tcga.py first (it writes database/generated/import_map.csv).
Needs: pip install pandas scikit-learn

Writes (generated, gitignored):
    database/generated/02_predictions.sql    predictions, prediction_results, prediction_mutations

HOW THE PREDICTIONS ARE MADE
Each model is the same pipeline as ml/train.py. To predict a patient fairly, the model must
not have seen that patient while training, so predictions are made with 5-fold
cross-validation: the patients are split into 5 groups, and each group is predicted by a
model trained on the other 4. Every patient gets the full probability for all 33 types.

    mutation-v1     mutations model (older, about 45% accurate)
    expression-v2   expression model (newer, about 95% accurate)

Each patient gets one prediction from each, recorded under the oncologist looking after
them (from import_map.csv), so the portal can compare model versions. created_at is the
real time each model was run by this script. No feedback is created: the feedback table
starts empty and fills up as people use the portal.
"""
import argparse
import csv
import datetime as dt
from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import StratifiedKFold, cross_val_predict
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "database" / "generated"
SEED = 42
BATCH = 1000

# Same settings as ml/train.py
MIN_SAMPLES_WITH_MUTATION = 10
TOP_VARIABLE_GENES = 3000

MODELS = {
    # model_used value: (feature kind, id offset so each prediction id is unique)
    "mutation-v1": ("mutation", 0),
    "expression-v2": ("expression", 2000),
}

def find(data_dir: Path, name: str) -> Path:
    matches = [m for m in sorted(data_dir.rglob(name)) if "__MACOSX" not in m.parts]
    if not matches:
        raise SystemExit(f"Can't find {name} under {data_dir}. Pass --data with the dataset folder.")
    return matches[0]


def read_matrix(path: Path, samples: pd.DataFrame) -> pd.DataFrame:
    """Gene x sample matrix as in train.py: float32 values, rows with any missing value dropped."""
    dtypes = {sample_id: "float32" for sample_id in samples["sample_id"]}
    matrix = pd.read_csv(path, sep="\t", index_col=0, dtype=dtypes)
    assert matrix.columns.tolist() == samples["sample_id"].tolist()
    return matrix.dropna(axis=0)


def features(kind: str, data: Path, samples: pd.DataFrame) -> pd.DataFrame:
    """The same feature matrix train.py builds (samples x genes)."""
    if kind == "mutation":
        X = read_matrix(find(data, "mutation_presence_2000.tsv.gz"), samples).T
        return X.loc[:, X.sum(axis=0) >= MIN_SAMPLES_WITH_MUTATION]
    X = np.log1p(read_matrix(find(data, "expression_2000.tsv.gz"), samples).clip(lower=0)).T
    return X.loc[:, X.var().sort_values(ascending=False).index[:TOP_VARIABLE_GENES]]


def model_for(kind: str):
    if kind == "mutation":
        return LogisticRegression(C=0.1, max_iter=1000, class_weight="balanced")
    return make_pipeline(StandardScaler(), LogisticRegression(C=0.01, max_iter=2000, class_weight="balanced"))


def sql(value) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, (int, float)):
        return repr(value)
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


def insert(handle, table, columns, rows):
    head = f"INSERT INTO {table} ({', '.join(columns)}) VALUES\n"
    batch = []
    for row in rows:
        batch.append("(" + ", ".join(sql(v) for v in row) + ")")
        if len(batch) == BATCH:
            handle.write(head + ",\n".join(batch) + ";\n")
            batch = []
    if batch:
        handle.write(head + ",\n".join(batch) + ";\n")


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--data", type=Path, default=ROOT / "ml")
    args = parser.parse_args()

    map_path = OUT / "import_map.csv"
    if not map_path.exists():
        raise SystemExit("Run database/build/import_tcga.py first: database/generated/import_map.csv is missing.")
    with open(map_path, newline="") as f:
        patients = {row["sample_code"]: row for row in csv.DictReader(f)}

    samples = pd.read_csv(find(args.data, "selected_samples.csv"))
    y = samples["cancer_type"].to_numpy()
    folds = StratifiedKFold(n_splits=5, shuffle=True, random_state=SEED)

    cancer_ids = {row["cancer_code"]: int(row["cancer_type_id"]) for row in patients.values()}

    prediction_rows, result_rows = [], []
    mutation_genes = []
    for model_used, (kind, offset) in MODELS.items():
        X = features(kind, args.data, samples)
        print(f"{model_used}: {X.shape[1]} genes, cross-validating 5 folds...")
        if kind == "mutation":
            mutation_genes = [g.split("|")[0] for g in X.columns]
        model = model_for(kind)
        proba = cross_val_predict(model, X, y, cv=folds, method="predict_proba")
        run_at = dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S")      # when this model was actually run
        classes = np.unique(y)                       # cross_val_predict orders columns like this
        predicted = classes[proba.argmax(axis=1)]
        print(f"  out-of-fold accuracy {np.mean(predicted == y):.3f}")

        for i, sample_code in enumerate(samples["sample_id"]):
            p = patients[sample_code]
            prediction_id = int(p["patient_id"]) + offset
            prediction_rows.append((prediction_id, int(p["patient_id"]), int(p["doctor_user_id"]), model_used, run_at))
            # DECIMAL(5,4): round, and keep every type (the comparison page shows all 33)
            for code, value in zip(classes, proba[i]):
                result_rows.append((prediction_id, cancer_ids[code], round(float(value), 4)))

    OUT.mkdir(parents=True, exist_ok=True)
    target = OUT / "02_predictions.sql"
    with open(target, "w", encoding="utf-8", newline="\n") as handle:
        handle.write("-- Generated by ml/predict_all.py. Load after 01_tcga_data.sql.\n"
                     "USE cancerinsight;\nSET FOREIGN_KEY_CHECKS = 0;\nSET autocommit = 0;\n\n")
        insert(handle, "predictions", ["prediction_id", "patient_id", "user_id", "model_used", "created_at"], prediction_rows)
        insert(handle, "prediction_results", ["prediction_id", "cancer_type_id", "prediction_probability"], result_rows)

        # prediction_mutations: the mutations the mutation model actually used for each patient,
        # i.e. the patient's mutations in the model's genes. Done in SQL from patient_mutations.
        handle.write("\n-- genes the mutation model uses\n"
                     "CREATE TEMPORARY TABLE model_genes (gene_symbol VARCHAR(50) PRIMARY KEY);\n")
        insert(handle, "model_genes", ["gene_symbol"], ((g,) for g in sorted(set(mutation_genes))))
        handle.write("""
INSERT INTO prediction_mutations (prediction_id, mutation_id, allele_frequency)
SELECT p.prediction_id, pm.mutation_id, pm.allele_frequency
FROM predictions p
JOIN samples s           ON s.patient_id = p.patient_id
JOIN patient_mutations pm ON pm.sample_id = s.sample_id
JOIN mutations m         ON m.mutation_id = pm.mutation_id
JOIN genes g             ON g.gene_id = m.gene_id
JOIN model_genes mg      ON mg.gene_symbol = g.gene_symbol
WHERE p.model_used = 'mutation-v1';
DROP TEMPORARY TABLE model_genes;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;
""")
    print(f"Wrote {target.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
