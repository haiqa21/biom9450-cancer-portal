# Database

`schema.sql` creates the empty `cancerinsight` database. Its contents are built from the
TCGA 2,000 matched profiles dataset by two scripts and written to `database/generated/`.
That folder is **gitignored** (about 75 MB), so each person builds it, or copies it from
someone who has.

## Build the contents (once, or whenever the scripts change)

1. Unzip `TCGA_2000_matched_profiles.zip` anywhere inside `ml/` (the same place `ml/train.py` looks).
2. From the repository folder:

   ```
   python database/build/import_tcga.py      # ~20 s, standard library only
   pip install pandas scikit-learn
   python ml/predict_all.py                  # ~5 min, trains both models with 5-fold cross-validation
   ```

   This writes:

   | File | Contents |
   |---|---|
   | `01_tcga_data.sql` | demo staff, 33 cancer types, genes, mutations, patients, diagnoses, samples |
   | `02_predictions.sql` | 2 predictions per patient (mutation-v1, expression-v2) with all 33 probabilities |
   | `03_demo_reviews.sql` | **synthetic** feedback so the pages have something to show (optional) |
   | `import_map.csv` | ids used by `predict_all.py` |

## Load it into MySQL (XAMPP)

From the repository folder, in a terminal (XAMPP's MySQL must be running):

```
C:\xampp\mysql\bin\mysql -u root < database\schema.sql
C:\xampp\mysql\bin\mysql -u root < database\generated\01_tcga_data.sql
C:\xampp\mysql\bin\mysql -u root < database\generated\02_predictions.sql
C:\xampp\mysql\bin\mysql -u root < database\generated\03_demo_reviews.sql
```

`schema.sql` drops and recreates the database, so this also resets it. The files are too
large for phpMyAdmin's default upload limit; use the command line.

## Demo logins

All demo accounts use the password **CancerInsight1!** (choose the matching role on the login page).

| Role | Email |
|---|---|
| Researcher | `iris.calloway@cancerinsight.test`, `daniel.osei@…`, `sofia.petrova@…` |
| Oncologist | `amara.okafor@cancerinsight.test` and 11 others (see `professionals`) |

## What is real and what is synthetic

The dataset has no clinical table, but the schema needs some clinical fields:

- **From the dataset:** cancer type, the TCGA sample code, every mutation (GRCh37), allele frequencies.
- **From the team's models (`ml/train.py`):** predictions and probabilities. Each patient is
  predicted by a model that never saw them during training (5-fold cross-validation).
- **Synthetic:** patient names and birthdates, diagnosis dates, the staff accounts, which
  oncologist looks after which patient, and the feedback in `03_demo_reviews.sql`.
  Sex, country and stage are left as *unknown*.

The build scripts' docstrings list every place the data didn't fit the schema and how it was handled.
