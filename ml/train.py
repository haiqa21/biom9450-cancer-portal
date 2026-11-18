"""Baseline cancer-type classifier: which cancer type does a patient's profile point to?

    python ml/train.py                       # from mutations (default)
    python ml/train.py --features expression # from gene expression

Setup:
    1. Unzip TCGA_2000_matched_profiles.zip anywhere inside ml/data/
       (mutation mode needs mutation_presence_2000.tsv.gz + selected_samples.csv,
        expression mode needs expression_2000.tsv.gz + selected_samples.csv)
    2. pip install pandas scikit-learn joblib
    3. Run one of the commands above. The model is saved to ml/model/.

Measured on a stratified 80/20 split of the 2,000 samples (33 cancer types):
    mutations  -> about 45% accuracy
    expression -> about 95% accuracy
"""
import argparse
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, classification_report, f1_score
from sklearn.model_selection import train_test_split
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler

HERE = Path(__file__).resolve().parent
DATA = HERE / "data"
MODEL_DIR = HERE / "model"

MIN_SAMPLES_WITH_MUTATION = 10   
TOP_VARIABLE_GENES = 3000        


def find(name: str) -> Path:
    """Locate a dataset file anywhere under ml/data/."""
    matches = list(DATA.rglob(name))
    if not matches:
        raise SystemExit(f"Can't find {name} under {DATA}. Unzip the TCGA dataset into ml/data/ first.")
    return matches[0]


def read_matrix(filename: str, samples: pd.DataFrame) -> pd.DataFrame:
    """Read a gene x sample matrix. float32 for the sample columns only (the first column is gene names)."""
    dtypes = {sample_id: "float32" for sample_id in samples["sample_id"]}
    matrix = pd.read_csv(find(filename), sep="\t", index_col=0, dtype=dtypes)
    assert matrix.columns.tolist() == samples["sample_id"].tolist()   
    return matrix.dropna(axis=0)   


def load(kind: str):
    samples = pd.read_csv(find("selected_samples.csv"))

    if kind == "mutation":
        X = read_matrix("mutation_presence_2000.tsv.gz", samples).T      
        X = X.loc[:, X.sum(axis=0) >= MIN_SAMPLES_WITH_MUTATION]
    else:
        X = np.log1p(read_matrix("expression_2000.tsv.gz", samples).clip(lower=0)).T
        X = X.loc[:, X.var().sort_values(ascending=False).index[:TOP_VARIABLE_GENES]]

    y = samples.set_index("sample_id").loc[X.index, "cancer_type"]
    return X, y


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--features", choices=["mutation", "expression"], default="mutation")
    kind = parser.parse_args().features

    X, y = load(kind)
    print(f"{kind}: {X.shape[0]} samples, {X.shape[1]} genes, {y.nunique()} cancer types")

    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, stratify=y, random_state=42)

    if kind == "mutation":
        model = LogisticRegression(C=0.1, max_iter=1000, class_weight="balanced")
    else:
        model = make_pipeline(StandardScaler(), LogisticRegression(C=0.01, max_iter=2000, class_weight="balanced"))
    model.fit(X_train, y_train)

    pred = model.predict(X_test)
    print(f"\nAccuracy : {accuracy_score(y_test, pred):.3f}")
    print(f"Macro F1 : {f1_score(y_test, pred, average='macro'):.3f}   (treats every cancer type equally)")
    print("\nPer cancer type:")
    print(classification_report(y_test, pred, zero_division=0))

    MODEL_DIR.mkdir(exist_ok=True)
    path = MODEL_DIR / f"cancer_type_{kind}.joblib"
    joblib.dump({"model": model, "genes": X.columns.tolist(), "features": kind}, path)
    print(f"Saved model to {path}")


if __name__ == "__main__":
    main()