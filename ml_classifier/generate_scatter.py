"""
generate_scatter.py
====================
يقرأ الكتب من قاعدة البيانات الحقيقية
ويحوّلها لبيانات PCA جاهزة للرسم.

الاستخدام من PHP:
    python3 generate_scatter.py --host 127.0.0.1 --port 3307 --db library_db --user root --pass ""

المخرج: JSON array جاهز لـ Chart.js
"""

import sys
import json
import argparse
import joblib
import os
import numpy as np

# ★ إصلاح encoding على Windows — يمنع خطأ UnicodeEncodeError
if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

# ── مسار النموذج ──────────────────────────────────────────
BASE_DIR   = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(BASE_DIR, "model.pkl")
VEC_PATH   = os.path.join(BASE_DIR, "vectorizer.pkl")

CAT_NAMES = {
    1:"برمجة", 2:"تاريخ", 3:"علوم", 4:"أدب", 5:"عام",
    6:"فانتازيا", 7:"رعب", 8:"غموض وتشويق", 9:"خيال علمي",
    10:"سيرة ذاتية", 11:"تطوير الذات", 12:"ديني وإسلامي",
    13:"سياسة واقتصاد",
}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', default='3307', type=int)
    parser.add_argument('--db',   default='library_db')
    parser.add_argument('--user', default='root')
    parser.add_argument('--pw',   default='')
    args = parser.parse_args()

    # ── الاتصال بـ MySQL ──────────────────────────────────
    try:
        import pymysql
    except ImportError:
        # إذا pymysql غير موجود → إرجاع بيانات فارغة
        sys.stdout.write(json.dumps({"error": "pymysql not installed", "points": [], "stats": {}}) + "\n")
        sys.stdout.flush()
        return

    try:
        conn = pymysql.connect(
            host=args.host, port=args.port,
            database=args.db, user=args.user, password=args.pw,
            charset='utf8mb4'
        )
        cursor = conn.cursor()
        cursor.execute("""
            SELECT b.id, b.title, b.author, b.description, b.category_id,
                   c.name as cat_name
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
            ORDER BY b.id ASC
        """)
        books = cursor.fetchall()
        conn.close()
    except Exception as e:
        sys.stdout.write(json.dumps({"error": str(e), "points": [], "stats": {}}) + "\n"); sys.stdout.flush()
        return

    if not books:
        sys.stdout.write(json.dumps({"error": "no books", "points": [], "stats": {}}) + "\n"); sys.stdout.flush()
        return

    # ── تحميل النموذج ────────────────────────────────────
    if not os.path.exists(VEC_PATH) or not os.path.exists(MODEL_PATH):
        sys.stdout.write(json.dumps({"error": "model not found", "points": [], "stats": {}}) + "\n"); sys.stdout.flush()
        return

    vectorizer = joblib.load(VEC_PATH)
    model      = joblib.load(MODEL_PATH)

    # ── تحضير النصوص ─────────────────────────────────────
    texts    = []
    book_ids = []
    for book in books:
        bid, title, author, desc, cat_id, cat_name = book
        text = ((title or '') + ' ' + (desc or '')).strip()
        texts.append(text)
        book_ids.append(bid)

    # ── TF-IDF ───────────────────────────────────────────
    X = vectorizer.transform(texts)

    # ── PCA إلى بُعدين ────────────────────────────────────
    from sklearn.decomposition import PCA
    n_components = min(2, X.shape[0], X.shape[1])
    pca = PCA(n_components=n_components, random_state=42)

    X_dense = X.toarray()
    coords  = pca.fit_transform(X_dense)

    # إذا كتاب واحد فقط → إرجاع (0,0)
    if coords.shape[1] < 2:
        coords = np.hstack([coords, np.zeros((coords.shape[0], 1))])

    # ── حساب ML predictions ──────────────────────────────
    ml_preds = model.predict(X)
    ml_proba = model.predict_proba(X)
    ml_conf  = [round(float(max(p)) * 100, 1) for p in ml_proba]

    # ── keyword scoring بسيط ──────────────────────────────
    kw_scores = []
    kw_preds  = []
    kws = {
        1:['software','programming','python','java','sql','code','database','javascript','برمجة','كود'],
        2:['history','war','ancient','civilization','empire','تاريخ','حرب','حضارة'],
        3:['science','math','physics','biology','chemistry','علوم','فيزياء','رياضيات'],
        4:['novel','story','literature','poetry','رواية','قصة','أدب','شعر'],
        5:['general','عام'],
        6:['fantasy','magic','dragon','wizard','فانتازيا','سحر','تنين'],
        7:['horror','ghost','terror','evil','رعب','مخيف','شبح'],
        8:['mystery','thriller','detective','crime','غموض','تشويق','محقق'],
        9:['science fiction','sci-fi','space','robot','future','خيال علمي','فضاء'],
        10:['autobiography','biography','memoir','سيرة','ذاتية','مذكرات'],
        11:['self help','success','motivation','habits','rich','wealth','تطوير','نجاح','ثروة'],
        12:['islam','quran','hadith','prophet','إسلام','قرآن','حديث','نبي'],
        13:['politics','economy','government','democracy','سياسة','اقتصاد','حكومة'],
    }
    for text in texts:
        t = text.lower()
        sc = {i: sum(1 for w in ws if w in t) for i, ws in kws.items()}
        best = max(sc, key=sc.get)
        kw_preds.append(best if sc[best] > 0 else 5)
        kw_scores.append(sc[best])

    # ── بناء النتيجة ──────────────────────────────────────
    points = []
    kw_correct = 0
    ml_correct = 0

    for i, book in enumerate(books):
        bid, title, author, desc, cat_id, cat_name = book
        cat_id = int(cat_id) if cat_id else 5

        kw_match = (kw_preds[i] == cat_id)
        ml_match = (int(ml_preds[i]) == cat_id)
        if kw_match: kw_correct += 1
        if ml_match: ml_correct += 1

        points.append({
            "id":       int(bid),
            "title":    title or '',
            "author":   author or '',
            "x":        round(float(coords[i, 0]), 4),
            "y":        round(float(coords[i, 1]), 4),
            "cat_id":   cat_id,
            "cat_name": cat_name or CAT_NAMES.get(cat_id, 'عام'),
            "ml_cat":   CAT_NAMES.get(int(ml_preds[i]), 'عام'),
            "ml_conf":  ml_conf[i],
            "ml_match": ml_match,
            "kw_cat":   CAT_NAMES.get(kw_preds[i], 'عام'),
            "kw_score": kw_scores[i],
            "kw_match": kw_match,
        })

    total = len(books)
    result = {
        "points": points,
        "stats": {
            "total":      total,
            "kw_correct": kw_correct,
            "ml_correct": ml_correct,
            "kw_acc":     round(kw_correct / total * 100, 1) if total else 0,
            "ml_acc":     round(ml_correct / total * 100, 1) if total else 0,
        }
    }
    # ★ ensure_ascii=True يتجنب كل مشاكل encoding على Windows
    output_json = json.dumps(result, ensure_ascii=True)
    sys.stdout.write(output_json + '\n')
    sys.stdout.flush()

if __name__ == "__main__":
    main()
