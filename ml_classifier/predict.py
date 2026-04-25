"""
predict.py
===========
يُستدعى من PHP عبر shell_exec لتصنيف كتاب واحد.



المخرج (JSON):
    {"category_id": 11, "category_name": "تطوير الذات", "confidence": 0.87}
"""

import sys
import json
import os
import joblib

# مسار النموذج
BASE_DIR  = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(BASE_DIR, "model.pkl")
VEC_PATH   = os.path.join(BASE_DIR, "vectorizer.pkl")

# خريطة الفئات
CATEGORIES = {
    1:  "برمجة",
    2:  "تاريخ",
    3:  "علوم",
    4:  "أدب",
    5:  "عام",
    6:  "فانتازيا",
    7:  "رعب",
    8:  "غموض وتشويق",
    9:  "خيال علمي",
    10: "سيرة ذاتية",
    11: "تطوير الذات",
    12: "ديني وإسلامي",
    13: "سياسة واقتصاد",
}

def predict(title, description=""):
    # تحميل النموذج
    if not os.path.exists(MODEL_PATH) or not os.path.exists(VEC_PATH):
        return {"category_id": 5, "category_name": "عام",
                "confidence": 0.0, "error": "model not found"}

    model      = joblib.load(MODEL_PATH)
    vectorizer = joblib.load(VEC_PATH)

    # تحضير النص
    text = (title + " " + description).strip()
    if not text:
        return {"category_id": 5, "category_name": "عام", "confidence": 0.0}

    # تحويل النص وتنبؤ
    X        = vectorizer.transform([text])
    cat_id   = int(model.predict(X)[0])
    proba    = model.predict_proba(X)[0]
    confidence = float(max(proba))

    return {
        "category_id":   cat_id,
        "category_name": CATEGORIES.get(cat_id, "عام"),
        "confidence":    round(confidence, 3),
    }

if __name__ == "__main__":
    title       = sys.argv[1] if len(sys.argv) > 1 else ""
    description = sys.argv[2] if len(sys.argv) > 2 else ""

    result = predict(title, description)
    # مخرج JSON نظيف — PHP يقرأه
    print(json.dumps(result, ensure_ascii=False))
