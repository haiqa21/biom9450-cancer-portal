"""Baseline cancer-type classifier: which cancer type does a patient's profile point to?

    python ml/train.py                       # from mutations (default)
    python ml/train.py --features expression # from gene expression
CAncer type classifier from gene mutations (6 cancer types). Run from the TCGA data folder: python
3 path/to/ml/train_mutation.py, Split: 70 % train 15% validation/ 15% test (stratified), plus 5-fols Cv.
Saves: model.json, model_metrics.json, confusion_matrix.png, confusion_matrix.csv.
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
names = {"BRCA": "Breast", "COAD": "Colorectal", "READ": "Colorectal",
         "SKCM": "Melanoma", "LGG": "Glioma", "THCA": "Thyroid", "KIRC": "Kidney"}
labels = labels.replace(names)
chosen = ["Breast", "Colorectal", "Melanoma", "Glioma", "Thyroid", "Kidney"]
labels = labels[labels.isin(chosen)]
gene_table = gene_table.loc[labels.index]


gene_table.columns = gene_table.columns.str.split("|").str[0]

print(gene_table.shape)               


print(labels.value_counts())

# Split into train (70%), validation (15%), test (15%) 
from sklearn.model_selection import train_test_split

#1: put 15% aside as the TEST set 

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
    # Always guesses the most common type (Breast). A real model must beat this.
    
    "Dummy (baseline)": DummyClassifier(strategy="most_frequent"),
    
    # Learns a score for every gene-cancer pair. max_iter = how many tries it gets to learn.
    
    "Logistic regression": LogisticRegression(max_iter=2000, class_weight="balanced"),
    
    # 300 decision trees that vote.
    
    "Random forest": RandomForestClassifier(n_estimators=300, class_weight="balanced", random_state=42),
}
# class_weight="balanced" = give small cancer types (like Kidney) more importance

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

test_pred = chosen_model.predict(X_test)     # the model's guesses for the 107 test patients
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


from sklearn.metrics import precision_recall_fscore_support

class_names = list(chosen_model.classes_)
cm = confusion_matrix(y_test, test_pred, labels=class_names)

#Precision, recall and F1 come stright from scikit-learn

precision, recall, f1, support = precision_recall_fscore_support(
    y_test, test_pred, labels=class_names, zero_division=0)

total = cm.sum()
specificity = []
for i in range(len(class_names)):
    tp = cm[i,i] #daigonal: correctly found 
    fn = cm[i,:].sum() - tp # rest of the role missing
    fp = cm[:, i].sum() - tp # rest of the column: wrongly called this type
    tn = total - tp - fn - fp # everyone else
    specificity.append(tn / (tn + fp))

metrics_table = pd.DataFrame({
    "precision" : precision,
    "recall (sensitivity)" : recall,
    "specificity": specificity,
    "F1":f1,
    "patients": support,
}, index=class_names).round(2)
                             
print("=== Per cancer type (test set)====")
print(metrics_table)

#Confusion matrix as counts AND as row percentages (each row adds up to 100%)

pd.set_option("display.width",200)
pd.set_option("display.max_columns",None)
print("ConfusionMatrix(counts):rows = true type, columns = predicted type")
print(pd.DataFrame(cm, index=class_names, columns=class_names))

row_pct = (cm / cm.sum(axis=1, keepdims=True) * 100).round(0).astype(int)
print("\nConfusion matrix (% of each true type)")
print(pd.DataFrame(row_pct, index=class_names, columns=class_names))

#========5 Fold Cross Validation as the result jumped from 73->78 ======
#StratifiedkFold does the cutting into 5 balanced groups

from sklearn.model_selection import StratifiedKFold
from sklearn.base import clone 
import numpy as np

# Same gene selection as before, packed into a function so each fold can redo it

def pick_genes(X, y, n=200):
    counts = X.sum()
    common = counts[counts >= 5].index          # drop rare genes
    s, _ = chi2(X[common], y)                   # score genes
    return list(pd.Series(s, index=common).sort_values(ascending=False).index[:n])

folds = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
cv_scores = {"Logistic regression": [], "Random forest": []}

#cancer-type mix for each fold, for website 
fold_info = []

for fold, (train_idx, val_idx) in enumerate(folds.split(X_rest, y_rest), start=1):
    X_tr, X_va = X_rest.iloc[train_idx], X_rest.iloc[val_idx]
    y_tr, y_va = y_rest.iloc[train_idx], y_rest.iloc[val_idx]
    
    fold_info.append({"fold": fold, "n_train": int(len(train_idx)), "n_val": int(len(val_idx)),
                      "val_mix": {c: int(n) for c, n in y_va.value_counts().items()}})
   
    genes = pick_genes(X_tr, y_tr)              # chosen from THIS fold's training patients only
    for name in cv_scores:
        m = clone(models[name])                 # a fresh, untrained copy of the model
        m.fit(X_tr[genes], y_tr)
        pred = m.predict(X_va[genes])
        cv_scores[name].append(f1_score(y_va, pred, average="macro"))
    print(f"Fold {fold}: LR macro F1 = {cv_scores['Logistic regression'][-1]:.2f}   "
          f"RF macro F1 = {cv_scores['Random forest'][-1]:.2f}")

print("\n===== 5-fold cross-validation (train + validation patients only) =====")
for name, s in cv_scores.items():
    print(f"{name:22s} macro F1 = {np.mean(s):.2f} ± {np.std(s):.2f}")


#======== ROC?AOC confidence threshols and model metrices ==

from sklearn.metrics import roc_auc_score, roc_curve
from sklearn.preprocessing import label_binarize

val_prob = chosen_model.predict_proba(X_val) #one probability per cencer type 
val_top = val_prob.max(axis=1) #how sure the model is about its top guess 
val_right = chosen_model.predict(X_val) == y_val.values

threshold = 0.5                                  # fallback if nothing reaches the goal
for t in np.arange(0.30, 0.95, 0.05):
    sure = val_top >= t
    if sure.sum() > 0 and val_right[sure].mean() >= 0.90:
        threshold = round(float(t), 2)
        break
print(f"\nConfidence threshold (chosen on validation): {threshold}")

#Testing the threshold 

test_prob = chosen_model.predict_proba(X_test)
test_top = test_prob.max(axis=1)
test_right = test_pred == y_test.values
sure = test_top >= threshold
print(f"Confident predictions: {sure.sum()} of {len(y_test)} test patients, "
      f"accuracy {test_right[sure].mean():.2f}")
print(f"Flagged as uncertain:  {(~sure).sum()} patients, "
      f"accuracy {test_right[~sure].mean():.2f}")

#-- One vs rest ROC and AUC on the test set 

y_test_bin = label_binarize(y_test, classes=class_names) #1 = this type, 0 = any other 
auc = {}
roc_points = {}
print("AUC per cancer type (test set, one vs rest):")
for i, cancer in enumerate(class_names):
    auc[cancer] = round(float(roc_auc_score(y_test_bin[:,i],test_prob[:,i])),3)
    fpr, tpr, _ = roc_curve(y_test_bin[:,i],test_prob[:,i])
    roc_points[cancer] = {"fpr": [round(float(v),3)for v in fpr],
                          "tpr": [round(float(v),3)for v in tpr]}
    print(f" {cancer:12s} AUC = {auc[cancer]:.2f}")
macro_auc = round(float(np.mean(list(auc.values()))),3)
print(f" Macro-average AUC = {macro_auc:.2f}")

#== Save everything the webiste needs into model_metrics.json
import json 

metrics = { "note": "Evaluated on held-out TCGA test patients, not clinically validated.",
    "n_test_patients": int(len(y_test)),
    "cancer_types": class_names,
    "accuracy": round(float(accuracy_score(y_test, test_pred)), 3),
    "baseline_accuracy": round(float(accuracy_score(y_test, base_pred)), 3),
    "macro_f1": round(float(f1_score(y_test, test_pred, average="macro")), 3),
    "per_type": metrics_table.reset_index(names="cancer_type").to_dict(orient="records"),
    "confusion_counts": cm.tolist(),
    "confusion_percent": row_pct.tolist(),
    "cross_validation": {name: {"mean_macro_f1": round(float(np.mean(s)), 3),
                                "sd": round(float(np.std(s)), 3),
                                "folds": [round(float(v), 3) for v in s]}
                         for name, s in cv_scores.items()},
    "cv_folds": fold_info,
    "auc": auc,
    "macro_auc": macro_auc,
    "roc": roc_points,
    "confidence_threshold": threshold,
    "test_confident": {"n": int(sure.sum()), "accuracy": round(float(test_right[sure].mean()), 3)},
    "test_flagged": {"n": int((~sure).sum()), "accuracy": round(float(test_right[~sure].mean()), 3)},
}
with open("model_metrics.json", "w") as f:
    json.dump(metrics, f, indent=2)
print(" Saved model_metrics.json")


model_data = {
    "genes": chosen_genes,                          # the 200 genes, in the order the website MUST use
    "cancer_types": list(chosen_model.classes_),    # the 6 cancer types
    "weights": {},                                   # 200 gene scores for each cancer type
    "bias": {},
    "confidence_threshold": threshold,
    # one starting score for each cancer type
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


labels_order = list(chosen_model.classes_)
display_names = labels_order


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