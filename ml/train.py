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
import pandas as pd


#Load the data and build the training table 
matrix = pd.read_csv("mutation_presence_2000.tsv.gz", sep="\t", index_col=0)
matrix = matrix.dropna()              # remove the 60 NA gene rows
gene_table = matrix.T.astype(int)     # .T = transpose (flip)

# labels: cancer type for each sample_id
samples = pd.read_csv("selected_samples.csv")
labels = samples.set_index("sample_id")["cancer_type"]

# keep only the cancer types I chose; merge colon + rectum
labels = labels.replace({"COAD": "Colorectal", "READ": "Colorectal"})
chosen = ["BRCA", "Colorectal", "SKCM", "LGG", "THCA", "KIRC"]
labels = labels[labels.isin(chosen)]
gene_table = gene_table.loc[labels.index]


gene_table.columns = gene_table.columns.str.split("|").str[0]

print(gene_table.shape)               


print(labels.value_counts())

# Split into train (70%), validation (15%), test (15%) 
from sklearn.model_selection import train_test_split

#1: put 15% aside as the TEST set (final exam)
X_rest, X_test, y_rest, y_test = train_test_split(
    gene_table, labels, test_size=0.15, stratify=labels, random_state=42
)

#2: split the rest into TRAIN and VALIDATION (practice exam)
X_train, X_val, y_train, y_val = train_test_split(
    X_rest, y_rest, test_size=0.15 / 0.85, stratify=y_rest, random_state=42
)

print("\nTrain:", len(X_train), " Validation:", len(X_val), " Test:", len(X_test))
print("\nCancer types in the TEST set:")
print(y_test.value_counts())


from sklearn.feature_selection import chi2

# Remove very rare genes (mutated in fewer than 5 training patients).
# With so few examples, a gene's score would just be luck.
gene_counts = X_train.sum()
common_genes = gene_counts[gene_counts >= 5].index
print("\nGenes left after removing rare ones:", len(common_genes))

# Score every remaining gene with the chi-squared test.
# compares how often each gene is mutated in each cancer type.
scores, p_values = chi2(X_train[common_genes], y_train)
gene_scores = pd.Series(scores, index=common_genes).sort_values(ascending=False)

# Keep the 200 highest-scoring genes
N_GENES = 200
chosen_genes = list(gene_scores.index[:N_GENES])

print("\nTop 15 genes (highest score = most useful):")
print(gene_scores.head(15).round(1))

# Keep only these 200 genes, in the SAME ORDER, in all three groups
X_train = X_train[chosen_genes]
X_val = X_val[chosen_genes]
X_test = X_test[chosen_genes]
print("\nNew training table size:", X_train.shape)


from sklearn.dummy import DummyClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.ensemble import RandomForestClassifier

models = {
    # Always guesses the most common type (BRCA). A real model must beat this.
    "Dummy (baseline)": DummyClassifier(strategy="most_frequent"),
    # Learns a score for every gene-cancer pair. max_iter = how many tries it gets to learn.
    "Logistic regression": LogisticRegression(max_iter=2000, class_weight="balanced"),
    # 300 decision trees that vote.
    "Random forest": RandomForestClassifier(n_estimators=300, class_weight="balanced", random_state=42),
}
# class_weight="balanced" = give small cancer types (like KIRC) more importance

for name, model in models.items():
    model.fit(X_train, y_train)          # .fit() = the actual training
    print("Trained:", name)


from sklearn.metrics import accuracy_score, f1_score

print("\nValidation results:")
for name, model in models.items():
    val_pred = model.predict(X_val)
    acc = accuracy_score(y_val, val_pred)
    f1m = f1_score(y_val, val_pred, average="macro")
    print(f"{name:22s}  accuracy = {acc:.2f}   macro F1 = {f1m:.2f}")


from sklearn.metrics import classification_report, confusion_matrix

chosen_model = models["Logistic regression"]
baseline = models["Dummy (baseline)"]

test_pred = chosen_model.predict(X_test)     # the model's guesses for the 161 test patients
base_pred = baseline.predict(X_test)

print("\n===== FINAL TEST RESULTS =====")
print("Baseline accuracy: ", round(accuracy_score(y_test, base_pred), 2))
print("Our model accuracy:", round(accuracy_score(y_test, test_pred), 2))
print("Weighted F1:       ", round(f1_score(y_test, test_pred, average="weighted"), 2))
print("Macro F1:          ", round(f1_score(y_test, test_pred, average="macro"), 2))


print()
print(classification_report(y_test, test_pred, zero_division=0))

# Confusion matrix: rows = TRUE type, columns = PREDICTED type
# Numbers on the diagonal are correct; everything else is a mix-up
cm = confusion_matrix(y_test, test_pred, labels=chosen_model.classes_)
pd.set_option("display.width", 200)
print(pd.DataFrame(cm, index=chosen_model.classes_, columns=chosen_model.classes_))
pd.DataFrame(cm, index=chosen_model.classes_, columns=chosen_model.classes_).to_csv("confusion_matrix.csv")

import json

model_data = {
    "genes": chosen_genes,                          # the 200 genes, in the order the website MUST use
    "cancer_types": list(chosen_model.classes_),    # the 9 cancer types
    "weights": {},                                   # 200 gene scores for each cancer type
    "bias": {},                                      # one starting score for each cancer type
    "test_results": {                                # so the website can show how good the model is
        "accuracy": round(float(accuracy_score(y_test, test_pred)), 3),
        "macro_f1": round(float(f1_score(y_test, test_pred, average="macro")), 3),
        "n_test_patients": int(len(y_test)),
    },
}

# coef_ holds the learned gene scores: one row per cancer type, one number per gene
for i, cancer in enumerate(chosen_model.classes_):
    model_data["weights"][cancer] = [round(float(w), 4) for w in chosen_model.coef_[i]]
    model_data["bias"][cancer] = round(float(chosen_model.intercept_[i]), 4)

with open("model.json", "w") as f:
    json.dump(model_data, f, indent=2)
print("\nSaved model.json")

# predict a pretend patient BY HAND, exactly as PHP will
import math

def predict(patient_genes):
    # 1. 0/1 list in the same gene order as model.json
    x = [1 if g in patient_genes else 0 for g in model_data["genes"]]
    # 2. score for each cancer type = bias + sum of (gene score x 0/1)
    scores = {}
    for cancer in model_data["cancer_types"]:
        w = model_data["weights"][cancer]
        scores[cancer] = model_data["bias"][cancer] + sum(wi * xi for wi, xi in zip(w, x))
    # 3. softmax: turn scores into probabilities that add up to 1
    total = sum(math.exp(s) for s in scores.values())
    probs = {c: math.exp(s) / total for c, s in scores.items()}
    top3 = sorted(probs.items(), key=lambda kv: -kv[1])[:3]
    print(patient_genes, "->", [(c, round(p, 2)) for c, p in top3])

predict(["APC", "KRAS", "TP53"])
predict(["IDH1", "ATRX", "TP53"])
predict(["BRAF"])

# draw the confusion matrix as a picture
import matplotlib.pyplot as plt
from sklearn.metrics import ConfusionMatrixDisplay


names = {"BRCA": "Breast", "Colorectal": "Colorectal", "KIRC": "Kidney",
         "LGG": "Glioma", "LUAD": "Lung", "PRAD": "Prostate",
         "SKCM": "Melanoma", "THCA": "Thyroid", "UCEC": "Uterine"}
labels_order = list(chosen_model.classes_)
display_names = [names[c] for c in labels_order]


fig, axes = plt.subplots(1, 2, figsize=(16, 7))

# Left: how many patients
ConfusionMatrixDisplay.from_predictions(
    y_test, test_pred, labels=labels_order, display_labels=display_names,
    cmap="Blues", colorbar=False, ax=axes[0])
axes[0].set_title("Number of test patients")

# Right: normalize="true" turns each row into percentages (each row adds up to 100%)
ConfusionMatrixDisplay.from_predictions(
    y_test, test_pred, labels=labels_order, display_labels=display_names,
    normalize="true", values_format=".0%", cmap="Blues", colorbar=False, ax=axes[1])
axes[1].set_title("% of each true cancer type (diagonal = recall)")

for ax in axes:
    ax.set_xlabel("Predicted cancer type")
    ax.set_ylabel("True cancer type")
    ax.tick_params(axis="x", labelrotation=45)   # tilt names so they don't overlap

acc = accuracy_score(y_test, test_pred)
fig.suptitle(f"Logistic regression, test set ({len(y_test)} patients, accuracy {acc:.0%})", fontsize=14)
plt.tight_layout()
plt.savefig("confusion_matrix.png", dpi=200)   
print("Saved confusion_matrix.png")
plt.show()                  