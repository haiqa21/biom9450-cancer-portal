import joblib
import pandas as pd
from sqlalchemy import create_engine
from sklearn.ensemble import RandomForestClassifier
from sklearn.dummy import DummyClassifier
from sklearn.model_selection import StratifiedGroupKFold, cross_val_score

engine = create_engine("mysql+pymysql://root:@localhost/cancerinsight");

