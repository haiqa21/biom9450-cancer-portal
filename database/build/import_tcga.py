"""Build the CancerInsight database contents from the TCGA 2,000 matched profiles.

    python database/build/import_tcga.py
    python database/build/import_tcga.py --data path/to/TCGA_2000_matched_profiles

Reads the dataset folder (searched for under ml/ by default, the same place
ml/train.py looks) and writes, following database/schema.sql as it stands:

    database/generated/01_tcga_data.sql   all rows below, ready to load into MySQL
    database/generated/import_map.csv     per patient ids, used by ml/predict_all.py

Both files are generated, large and gitignored: rebuild them with this script.
Python standard library only, so no installs are needed.

WHAT COMES FROM THE DATASET
    cancer_types       the 33 TCGA cancer types (code in cancer_name, full name in description)
    genes              every gene symbol in the mutation records, with its chromosome
    mutations          every distinct variant (GRCh37), Variant_Classification as mutation_type
    samples            one per patient, sample_code = TCGA sample id (e.g. TCGA-04-1357-01A)
    patient_mutations  which sample carries which variant, allele_frequency = t_alt_count / t_depth
    patient_diagnoses  the cancer type of each patient

WHAT IS SYNTHETIC (the dataset has no clinical table, but the schema requires these)
    patients.first_name, last_name, birthdate      generated, fixed random seed
    patients.country                               'Unknown'
    patients.sex                                   'unknown'   (not in the dataset)
    patient_diagnoses.date_of_diagnosis            generated date in 2025-26
    patient_diagnoses.stage / status               'unknown' / 'active'
    users + professionals                          12 demo oncologists, 3 demo researchers,
                                                   all with the password DEMO_PASSWORD below

WHERE THE DATA DOESN'T FIT THE CURRENT SCHEMA (handled, and counted in the output)
    - The same variant recorded more than once for one sample (about 27,600 records):
      patient_mutations allows one row per sample + variant, so the duplicates are
      merged, keeping the highest allele frequency.
    - Reference alleles longer than VARCHAR(255) (26 large deletions): stored as NULL.
    - Y_RNA and snoU13 appear on several chromosomes but genes.gene_symbol is unique:
      stored once, with the chromosome they appear on most.
    - A few symbols differ only in upper/lower case (C15ORF37 / C15orf37). MySQL treats
      those as the same name, so they are merged into one gene.
    - The FILTER (PASS or not) of each record has no column, so all records are kept,
      the same definition the ML mutation matrix uses.
"""
import argparse
import csv
import datetime as dt
import gzip
import random
import sys
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]          # the repository folder
OUT = ROOT / "database" / "generated"

DEMO_PASSWORD = "CancerInsight1!"
# password_hash('CancerInsight1!', PASSWORD_DEFAULT) from PHP; password_verify() accepts it
DEMO_PASSWORD_HASH = "$2y$10$LODFz9r9V5p3psdXIxlxMOuKE4396hPob6WlQL.gEhlL7qbi/M0me"

SEED = 42
BATCH = 1000          # rows per INSERT statement

# TCGA code -> full name (GDC study names, from the dataset guide, section 10)
CANCER_NAMES = {
    "ACC": "Adrenocortical carcinoma",
    "BLCA": "Bladder urothelial carcinoma",
    "BRCA": "Breast invasive carcinoma",
    "CESC": "Cervical squamous cell carcinoma and endocervical adenocarcinoma",
    "CHOL": "Cholangiocarcinoma",
    "COAD": "Colon adenocarcinoma",
    "DLBC": "Lymphoid neoplasm diffuse large B-cell lymphoma",
    "ESCA": "Esophageal carcinoma",
    "GBM": "Glioblastoma multiforme",
    "HNSC": "Head and neck squamous cell carcinoma",
    "KICH": "Kidney chromophobe",
    "KIRC": "Kidney renal clear cell carcinoma",
    "KIRP": "Kidney renal papillary cell carcinoma",
    "LAML": "Acute myeloid leukemia",
    "LGG": "Brain lower grade glioma",
    "LIHC": "Liver hepatocellular carcinoma",
    "LUAD": "Lung adenocarcinoma",
    "LUSC": "Lung squamous cell carcinoma",
    "MESO": "Mesothelioma",
    "OV": "Ovarian serous cystadenocarcinoma",
    "PAAD": "Pancreatic adenocarcinoma",
    "PCPG": "Pheochromocytoma and paraganglioma",
    "PRAD": "Prostate adenocarcinoma",
    "READ": "Rectum adenocarcinoma",
    "SARC": "Sarcoma",
    "SKCM": "Skin cutaneous melanoma",
    "STAD": "Stomach adenocarcinoma",
    "TGCT": "Testicular germ cell tumours",
    "THCA": "Thyroid carcinoma",
    "THYM": "Thymoma",
    "UCEC": "Uterine corpus endometrial carcinoma",
    "UCS": "Uterine carcinosarcoma",
    "UVM": "Uveal melanoma",
}

# Organ groups (same as includes/research.php). Each group gets its own demo oncologist(s),
# and a patient is looked after by an oncologist from their cancer's group.
GROUPS = {
    "breast_gyn":  ["BRCA", "OV", "UCEC", "UCS", "CESC"],
    "urinary":     ["KIRC", "KIRP", "KICH", "BLCA", "PRAD", "TGCT"],
    "digestive":   ["COAD", "READ", "STAD", "ESCA", "PAAD", "LIHC", "CHOL"],
    "lung_hn":     ["LUAD", "LUSC", "MESO", "HNSC", "THYM"],
    "endocrine":   ["THCA", "ACC", "PCPG", "SARC"],
    "brain":       ["GBM", "LGG"],
    "skin_eye":    ["SKCM", "UVM"],
    "blood":       ["LAML", "DLBC"],
}
GROUP_OF = {code: group for group, codes in GROUPS.items() for code in codes}

# Demo staff. Invented names; institutions are fictional.
ONCOLOGISTS = [
    # first, last, speciality, institution, group
    ("Amara", "Okafor", "Breast and gynaecological oncology", "Harbourside Cancer Centre", "breast_gyn"),
    ("Lena", "Hartmann", "Gynaecological oncology", "Northgate University Hospital", "breast_gyn"),
    ("Rafael", "Moreno", "Urological oncology", "Harbourside Cancer Centre", "urinary"),
    ("Priya", "Raman", "Genitourinary oncology", "Westbrook Medical Centre", "urinary"),
    ("Tomas", "Novak", "Gastrointestinal oncology", "Northgate University Hospital", "digestive"),
    ("Mei", "Tanaka", "Hepatobiliary and pancreatic oncology", "Westbrook Medical Centre", "digestive"),
    ("Samuel", "Adeyemi", "Thoracic oncology", "Harbourside Cancer Centre", "lung_hn"),
    ("Clara", "Lindqvist", "Head and neck oncology", "Northgate University Hospital", "lung_hn"),
    ("Hugo", "Laurent", "Endocrine and sarcoma oncology", "Westbrook Medical Centre", "endocrine"),
    ("Nadia", "Haddad", "Neuro-oncology", "Northgate University Hospital", "brain"),
    ("Ethan", "Brooks", "Melanoma and ocular oncology", "Harbourside Cancer Centre", "skin_eye"),
    ("Yusuf", "Demir", "Haematological oncology", "Westbrook Medical Centre", "blood"),
]
RESEARCHERS = [
    ("Iris", "Calloway", "Cancer genomics", "Harbourside Cancer Centre"),
    ("Daniel", "Osei", "Biostatistics", "Northgate University Hospital"),
    ("Sofia", "Petrova", "Machine learning in oncology", "Westbrook Medical Centre"),
]

FIRST_NAMES = ["Alex", "Sam", "Jordan", "Taylor", "Morgan", "Casey", "Riley", "Jamie", "Avery", "Quinn",
               "Charlie", "Robin", "Drew", "Elliot", "Harper", "Kai", "Logan", "Noa", "Parker", "Rowan",
               "Sage", "Skyler", "Emerson", "Finley", "Hayden", "Jesse", "Kendall", "Marley", "Reese", "Shay"]
LAST_NAMES = ["Abbott", "Barker", "Chen", "Dalton", "Ellis", "Fraser", "Garcia", "Hughes", "Iqbal", "Jensen",
              "Kowalski", "Lambert", "Mason", "Nguyen", "Ortiz", "Patel", "Quinlan", "Rossi", "Silva", "Turner",
              "Usman", "Vargas", "Walsh", "Xu", "Young", "Zimmerman", "Bennett", "Cooper", "Dixon", "Evans"]


# ---------------------------------------------------------------------------
# small helpers
# ---------------------------------------------------------------------------

def find(data_dir: Path, name: str) -> Path:
    """First file called `name` anywhere under data_dir (the dataset may be unzipped one level deeper)."""
    matches = sorted(data_dir.rglob(name))
    matches = [m for m in matches if "__MACOSX" not in m.parts]
    if not matches:
        sys.exit(f"Can't find {name} under {data_dir}. Pass --data with the dataset folder.")
    return matches[0]


def sql(value) -> str:
    """One value as a MySQL literal."""
    if value is None:
        return "NULL"
    if isinstance(value, (int, float)):
        return repr(value)
    text = str(value).replace("\\", "\\\\").replace("'", "\\'")
    return f"'{text}'"


class SqlWriter:
    """Writes multi-row INSERTs, BATCH rows at a time, so MySQL loads them quickly."""

    def __init__(self, handle):
        self.handle = handle

    def comment(self, text: str):
        self.handle.write(f"\n-- {text}\n")

    def insert(self, table: str, columns: list, rows):
        head = f"INSERT INTO {table} ({', '.join(columns)}) VALUES\n"
        batch, count = [], 0
        for row in rows:
            batch.append("(" + ", ".join(sql(v) for v in row) + ")")
            count += 1
            if len(batch) == BATCH:
                self.handle.write(head + ",\n".join(batch) + ";\n")
                batch = []
        if batch:
            self.handle.write(head + ",\n".join(batch) + ";\n")
        return count


# ---------------------------------------------------------------------------
# main
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--data", type=Path, default=ROOT / "ml",
                        help="folder containing the unzipped dataset (searched recursively)")
    args = parser.parse_args()
    rng = random.Random(SEED)
    OUT.mkdir(parents=True, exist_ok=True)

    # --- samples and patients ------------------------------------------------
    with open(find(args.data, "selected_samples.csv"), newline="") as f:
        samples = list(csv.DictReader(f))          # already in sample order, one per patient
    unknown = {s["cancer_type"] for s in samples} - CANCER_NAMES.keys()
    if unknown:
        sys.exit(f"Unexpected cancer types in selected_samples.csv: {unknown}")

    cancer_codes = sorted(CANCER_NAMES)                      # ids 1..33 in alphabetical code order
    cancer_id = {code: i + 1 for i, code in enumerate(cancer_codes)}
    sample_db_id = {s["sample_id"]: i + 1 for i, s in enumerate(samples)}   # patient_id == sample id

    # --- mutation records ----------------------------------------------------
    # One pass over the MAF collects genes, distinct variants and per-sample links.
    print("Reading mutations_2000.maf.gz (about a minute)...")
    gene_chromosomes = defaultdict(Counter)       # symbol -> chromosome counts
    variant_id = {}                               # (symbol, pos, ref, alt) -> mutation_id
    variants = []                                 # rows for the mutations table (gene symbol for now)
    sample_variant_af = {}                        # (sample db id, mutation_id) -> best allele frequency
    records = duplicates = long_refs = 0

    with gzip.open(find(args.data, "mutations_2000.maf.gz"), "rt", newline="") as f:
        reader = csv.reader((line for line in f if not line.startswith("#")), delimiter="\t")
        header = next(reader)
        col = {name: i for i, name in enumerate(header)}
        for row in reader:
            records += 1
            symbol = row[col["Hugo_Symbol"]]
            chromosome = row[col["Chromosome"]]
            position = int(row[col["Start_Position"]])
            ref = row[col["Reference_Allele"]]
            alt = row[col["Tumor_Seq_Allele2"]]
            gene_chromosomes[symbol][chromosome] += 1

            key = (symbol, position, ref, alt)
            if key not in variant_id:
                variant_id[key] = len(variants) + 1
                if len(ref) > 255:
                    long_refs += 1
                variants.append((symbol, position, ref if len(ref) <= 255 else None, alt,
                                 row[col["Variant_Classification"]]))

            depth = int(row[col["t_depth"]] or 0)
            alt_count = int(row[col["t_alt_count"]] or 0)
            af = round(alt_count / depth, 4) if depth > 0 else None
            link = (sample_db_id[row[col["sample_id"]]], variant_id[key])
            if link in sample_variant_af:
                duplicates += 1
                old = sample_variant_af[link]
                if af is not None and (old is None or af > old):
                    sample_variant_af[link] = af
            else:
                sample_variant_af[link] = af

    # MySQL compares gene_symbol without case (utf8mb4_unicode_ci), so "C15ORF37" and
    # "C15orf37" would clash on its UNIQUE key. Spellings that differ only in case become
    # one gene, named with the spelling used most often.
    spellings = defaultdict(Counter)
    for symbol, chromosomes in gene_chromosomes.items():
        spellings[symbol.upper()][symbol] = sum(chromosomes.values())
    canonical = {}
    for group in spellings.values():
        main_spelling = group.most_common(1)[0][0]
        for spelling in group:
            canonical[spelling] = main_spelling
    merged_chromosomes = defaultdict(Counter)
    for symbol, chromosomes in gene_chromosomes.items():
        merged_chromosomes[canonical[symbol]].update(chromosomes)
    case_merged = len(gene_chromosomes) - len(merged_chromosomes)
    gene_chromosomes = merged_chromosomes

    gene_symbols = sorted(gene_chromosomes)
    gene_id = {s: i + 1 for i, s in enumerate(gene_symbols)}
    for spelling, main_spelling in canonical.items():
        gene_id[spelling] = gene_id[main_spelling]
    multi_chromosome = [s for s in gene_symbols if len(gene_chromosomes[s]) > 1]

    # The mutations table's UNIQUE key is (gene, position, first 50 characters of each
    # allele, assembly), compared without case. Re-check the variants against exactly that
    # key now that genes are merged, and point any duplicate at the first copy.
    final_id, kept, seen = {}, [], {}
    for old_id, (sym, pos, ref, alt, kind) in enumerate(variants, start=1):
        key = (gene_id[sym], pos, (ref or "")[:50].upper(), alt[:50].upper())
        if key in seen:
            final_id[old_id] = seen[key]
        else:
            kept.append((sym, pos, ref, alt, kind))
            seen[key] = final_id[old_id] = len(kept)
    variants_merged = len(variants) - len(kept)
    variants = kept
    remapped = {}
    for (sid, mid), af in sample_variant_af.items():
        link = (sid, final_id[mid])
        if link not in remapped or (af is not None and (remapped[link] is None or af > remapped[link])):
            remapped[link] = af
    sample_variant_af = remapped

    # --- synthetic clinical fields -------------------------------------------
    oncologists_by_group = defaultdict(list)
    staff_rows, professional_rows = [], []
    user_no = 0
    for first, last, speciality, institution, group in ONCOLOGISTS:
        user_no += 1
        staff_rows.append((user_no, f"{first.lower()}.{last.lower()}", f"{first.lower()}.{last.lower()}@cancerinsight.test",
                           DEMO_PASSWORD_HASH, "professional", "2025-02-03 09:00:00"))
        professional_rows.append((user_no, first, last, "oncologist", institution, speciality))
        oncologists_by_group[group].append(user_no)
    for first, last, speciality, institution in RESEARCHERS:
        user_no += 1
        staff_rows.append((user_no, f"{first.lower()}.{last.lower()}", f"{first.lower()}.{last.lower()}@cancerinsight.test",
                           DEMO_PASSWORD_HASH, "professional", "2025-02-03 09:00:00"))
        professional_rows.append((user_no, first, last, "researcher", institution, speciality))

    patient_rows, diagnosis_rows, map_rows = [], [], []
    group_turn = Counter()
    for s in samples:
        pid = sample_db_id[s["sample_id"]]
        diagnosed = dt.date(2025, 6, 1) + dt.timedelta(days=rng.randrange(0, 365))
        age = rng.randint(30, 85)
        birthdate = diagnosed - dt.timedelta(days=age * 365 + rng.randrange(0, 365))
        patient_rows.append((pid, None, rng.choice(FIRST_NAMES), rng.choice(LAST_NAMES),
                             birthdate.isoformat(), "unknown", "Unknown", None))
        diagnosis_rows.append((pid, pid, cancer_id[s["cancer_type"]], diagnosed.isoformat(), "unknown", "active"))

        # alternate between the oncologists of the cancer's organ group
        group = GROUP_OF[s["cancer_type"]]
        doctors = oncologists_by_group[group]
        doctor = doctors[group_turn[group] % len(doctors)]
        group_turn[group] += 1
        map_rows.append({"sample_code": s["sample_id"], "patient_id": pid, "sample_id": pid,
                         "cancer_code": s["cancer_type"], "cancer_type_id": cancer_id[s["cancer_type"]],
                         "doctor_user_id": doctor, "date_of_diagnosis": diagnosed.isoformat()})

    # --- write the SQL -------------------------------------------------------
    target = OUT / "01_tcga_data.sql"
    print(f"Writing {target.relative_to(ROOT)}...")
    with open(target, "w", encoding="utf-8", newline="\n") as handle:
        out = SqlWriter(handle)
        handle.write("-- Generated by database/build/import_tcga.py. Do not edit; rebuild instead.\n"
                     "-- Load after database/schema.sql. Synthetic fields are listed in the script.\n"
                     "USE cancerinsight;\n"
                     "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET autocommit = 0;\n")

        out.comment("demo staff (password: see DEMO_PASSWORD in the build script)")
        out.insert("users", ["user_id", "username", "email", "password_hash", "role", "created_at"], staff_rows)
        out.insert("professionals", ["user_id", "first_name", "last_name", "professional_type", "institution", "speciality"],
                   professional_rows)

        out.comment("33 TCGA cancer types: code in cancer_name, full name in description")
        out.insert("cancer_types", ["cancer_type_id", "cancer_name", "description"],
                   ((cancer_id[c], c, CANCER_NAMES[c]) for c in cancer_codes))

        out.comment("genes found in the mutation records")
        out.insert("genes", ["gene_id", "gene_symbol", "chromosome"],
                   ((gene_id[s], s, gene_chromosomes[s].most_common(1)[0][0]) for s in gene_symbols))

        out.comment("distinct variants (GRCh37)")
        out.insert("mutations", ["mutation_id", "gene_id", "genomic_position", "original_allele", "altered_allele",
                                 "mutation_type", "clinical_significance", "genome_assembly"],
                   ((i + 1, gene_id[sym], pos, ref, alt, kind, "unknown", "GRCh37")
                    for i, (sym, pos, ref, alt, kind) in enumerate(variants)))

        out.comment("patients (synthetic names, birthdates)")
        out.insert("patients", ["patient_id", "user_id", "first_name", "last_name", "birthdate", "sex", "country", "photo_path"],
                   patient_rows)
        out.insert("patient_diagnoses", ["diagnosis_id", "patient_id", "cancer_type_id", "date_of_diagnosis", "stage", "status"],
                   diagnosis_rows)

        out.comment("one tumour sample per patient, linked to the diagnosis")
        out.insert("samples", ["sample_id", "sample_code", "patient_id", "date_detected"],
                   ((sample_db_id[s["sample_id"]], s["sample_id"], sample_db_id[s["sample_id"]], None) for s in samples))
        out.insert("sample_diagnoses", ["sample_id", "diagnosis_id"],
                   ((sample_db_id[s["sample_id"]], sample_db_id[s["sample_id"]]) for s in samples))

        out.comment("which sample carries which variant")
        links = out.insert("patient_mutations", ["sample_id", "mutation_id", "allele_frequency"],
                           ((sid, mid, af) for (sid, mid), af in sorted(sample_variant_af.items())))

        handle.write("\nCOMMIT;\nSET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n")

    with open(OUT / "import_map.csv", "w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=list(map_rows[0]))
        writer.writeheader()
        writer.writerows(map_rows)

    print(f"""
Done.
  patients / samples        {len(samples):,}
  cancer types              {len(cancer_codes)}
  genes                     {len(gene_symbols):,}
  distinct variants         {len(variants):,}
  mutation records read     {records:,}
  sample-variant links      {links:,}  ({duplicates:,} repeated records merged)
  long alleles stored NULL  {long_refs}
  multi-chromosome symbols  {', '.join(multi_chromosome) or 'none'} (stored on their most common chromosome)
  case-only symbol clashes  {case_merged} merged (e.g. C15ORF37 / C15orf37), {variants_merged} variants merged with them
  demo logins               {len(ONCOLOGISTS)} oncologists, {len(RESEARCHERS)} researchers, password {DEMO_PASSWORD}
""")


if __name__ == "__main__":
    main()
