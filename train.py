"""
train.py
=========
ضعه في: library/train.py
شغّله من CMD داخل مجلد library:
    python train.py

يقرأ كتبك من قاعدة البيانات ويدرّب النموذج.
"""

import sys
import os
import json

if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

def check_libraries():
    missing = []
    try:
        import pymysql
    except ImportError:
        missing.append("pymysql")
    try:
        import sklearn
    except ImportError:
        missing.append("scikit-learn")
    try:
        import joblib
    except ImportError:
        missing.append("joblib")
    try:
        import numpy
    except ImportError:
        missing.append("numpy")

    if missing:
        print("=" * 50)
        print("خطأ: المكتبات التالية غير مثبتة:")
        for lib in missing:
            print(f"  pip install {lib}")
        print("=" * 50)
        sys.exit(1)

check_libraries()

import pymysql
import joblib
import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.ensemble import RandomForestClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import train_test_split, cross_val_score
from sklearn.metrics import classification_report
from sklearn.pipeline import Pipeline


DB_CONFIG = {
    'host':     '127.0.0.1',
    'port':     3307,          
    'database': 'library_db',
    'user':     'root',
    'password': '',
    'charset':  'utf8mb4',
}

OUTPUT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'ml_classifier')

CATEGORIES = {
    1:  "برمجة وتقنية",
    2:  "تاريخ وحضارات",
    3:  "علوم وطبيعة",
    4:  "أدب وروايات",
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


def fetch_books():
    print("\n📡 الاتصال بقاعدة البيانات...")
    try:
        conn = pymysql.connect(**DB_CONFIG)
        cursor = conn.cursor()

        cursor.execute("""
            SELECT 
                b.id,
                b.title,
                COALESCE(b.description, '') as description,
                b.category_id,
                c.name as category_name
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE b.category_id IS NOT NULL
            ORDER BY b.id ASC
        """)
        books = cursor.fetchall()
        conn.close()

        print(f"✅ تم جلب {len(books)} كتاب من قاعدة البيانات")
        return books

    except Exception as e:
        print(f"❌ خطأ في الاتصال: {e}")
        print("تأكد من:")
        print("  1. XAMPP يعمل")
        print("  2. إعدادات DB_CONFIG صحيحة في أعلى الملف")
        sys.exit(1)


def prepare_data(books):
    print("\n🔧 تحضير البيانات...")

    texts  = []
    labels = []
    skipped = 0

    cat_counts = {}

    for book_id, title, desc, cat_id, cat_name in books:
        text = f"{title} {title} {desc}".strip()

        if len(text) < 3:
            skipped += 1
            continue

        texts.append(text)
        labels.append(int(cat_id))

        cat_name_str = cat_name or CATEGORIES.get(int(cat_id), "عام")
        cat_counts[cat_name_str] = cat_counts.get(cat_name_str, 0) + 1

    print(f"\n📊 توزيع الكتب على التصنيفات:")
    for cat, count in sorted(cat_counts.items(), key=lambda x: -x[1]):
        bar = "█" * count
        print(f"  {cat:<20} {bar} ({count})")

    if skipped > 0:
        print(f"\n⚠️  تم تخطي {skipped} كتاب (بدون نص)")

    return texts, labels


def train_model(texts, labels):
    total = len(texts)
    unique_cats = len(set(labels))

    print(f"\n🧠 بدء التدريب...")
    print(f"   إجمالي الكتب:     {total}")
    print(f"   عدد التصنيفات:    {unique_cats}")

    if total < 30:
        print(f"\n⚠️  تحذير: {total} كتاب قليلة جداً للتدريب!")
        print("   النتائج ستكون غير موثوقة.")
        print("   يُنصح بـ 50+ كتاب على الأقل.")
        print("   سيستمر التدريب لكن الدقة ستكون منخفضة.\n")


    vectorizer = TfidfVectorizer(
        max_features=3000,     
        min_df=1,              
        ngram_range=(1, 2),    
        sublinear_tf=True,     
        analyzer='word',
        token_pattern=r'(?u)\b\w+\b',  
    )

    X = vectorizer.fit_transform(texts)
    y = np.array(labels)

    if total >= 100:
        model = RandomForestClassifier(
            n_estimators=300,
            max_depth=None,
            min_samples_split=2,
            random_state=42,
            n_jobs=-1,
        )
        model_name = "RandomForest"
    else:
        model = LogisticRegression(
            C=5.0,
            max_iter=2000,
            random_state=42,
            solver='lbfgs',
            class_weight='balanced',
        )
        model_name = "LogisticRegression (balanced)"

    print(f"   الخوارزمية: {model_name}")

    if total >= 20:
        from collections import Counter
        label_counts = Counter(y.tolist())
        rare = [c for c, cnt in label_counts.items() if cnt < 2]

        if rare:
            rare_names = [CATEGORIES.get(c, str(c)) for c in rare]
            print(f"\n⚠️  تصنيفات بكتاب واحد فقط (لن تُستخدم stratify):")
            for n in rare_names:
                print(f"     - {n}")
            use_stratify = None
        else:
            use_stratify = y

        X_train, X_test, y_train, y_test = train_test_split(
            X, y,
            test_size=0.2,
            random_state=42,
            stratify=use_stratify
        )

        print(f"\n   كتب التدريب:  {len(y_train)}")
        print(f"   كتب الاختبار: {len(y_test)}")

        model.fit(X_train, y_train)

        
        train_acc = model.score(X_train, y_train)
        test_acc  = model.score(X_test, y_test)

        print(f"\n📈 النتائج:")
        print(f"   دقة التدريب:  {train_acc * 100:.1f}%")
        print(f"   دقة الاختبار: {test_acc  * 100:.1f}%")

      
        y_pred = model.predict(X_test)
        cat_labels = [CATEGORIES.get(c, str(c)) for c in sorted(set(y))]
        print(f"\n📋 تفاصيل دقة كل تصنيف:")
        print(classification_report(
            y_test, y_pred,
            labels=sorted(set(y)),
            target_names=[CATEGORIES.get(c, str(c)) for c in sorted(set(y))],
            zero_division=0
        ))

       
        if total >= 50:
            print("🔄 Cross-Validation (5-fold)...")
            cv_scores = cross_val_score(model, X, y, cv=5)
            print(f"   متوسط الدقة: {cv_scores.mean() * 100:.1f}% (±{cv_scores.std() * 100:.1f}%)")

    else:
      
        print("\n⚠️  بيانات قليلة — التدريب على كل البيانات بدون اختبار")
        model.fit(X, y)

    
    print("\n🔁 التدريب النهائي على كامل البيانات...")
    model.fit(X, y)

    return vectorizer, model


def save_model(vectorizer, model):
    print(f"\n💾 حفظ النموذج في: {OUTPUT_DIR}")

    os.makedirs(OUTPUT_DIR, exist_ok=True)

    vec_path   = os.path.join(OUTPUT_DIR, 'vectorizer.pkl')
    model_path = os.path.join(OUTPUT_DIR, 'model.pkl')

    joblib.dump(vectorizer, vec_path)
    joblib.dump(model,      model_path)

    info = {
        'categories':     CATEGORIES,
        'vectorizer':     str(type(vectorizer).__name__),
        'model':          str(type(model).__name__),
        'max_features':   3000,
        'ngram_range':    [1, 2],
    }
    info_path = os.path.join(OUTPUT_DIR, 'model_info.json')
    with open(info_path, 'w', encoding='utf-8') as f:
        json.dump(info, f, ensure_ascii=False, indent=2)

    print(f"   ✅ vectorizer.pkl")
    print(f"   ✅ model.pkl")
    print(f"   ✅ model_info.json")


def quick_test(vectorizer, model):
    print("\n🧪 اختبار سريع:")

    test_cases = [
        ("Think and Grow Rich", "success wealth motivation habits"),
        ("1984",                "dystopian future totalitarian government"),
        ("Harry Potter",        "magic wizard fantasy dragon spell"),
        ("Python Programming",  "code software database algorithms"),
        ("الجريمة والعقاب",    "رواية قصة جريمة محقق"),
        ("تاريخ الإسلام",      "نبي إسلام قرآن حديث دين"),
    ]

    for title, desc in test_cases:
        text  = f"{title} {desc}"
        X     = vectorizer.transform([text])
        cat   = int(model.predict(X)[0])
        proba = model.predict_proba(X)[0]
        conf  = round(float(max(proba)) * 100, 1)
        print(f"   '{title}' → {CATEGORIES.get(cat, '?')} ({conf}%)")


if __name__ == "__main__":
    print("=" * 55)
    print("  🤖 تدريب نموذج تصنيف الكتب")
    print("=" * 55)

    books = fetch_books()

    if len(books) == 0:
        print("❌ لا توجد كتب في قاعدة البيانات!")
        sys.exit(1)

    texts, labels = prepare_data(books)

    if len(set(labels)) < 2:
        print("❌ تحتاج كتب من تصنيفين مختلفين على الأقل!")
        sys.exit(1)

    vectorizer, model = train_model(texts, labels)
    save_model(vectorizer, model)
    quick_test(vectorizer, model)

    print("\n" + "=" * 55)
    print("  ✅ انتهى التدريب بنجاح!")
    print("  الآن predict.py جاهز للاستخدام")
    print("=" * 55)