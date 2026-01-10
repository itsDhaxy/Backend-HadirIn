# main.py — Vector Store + auto-migrate from faces + reload-from-faces
from fastapi import FastAPI, UploadFile, File, Form, HTTPException
from fastapi.middleware.cors import CORSMiddleware
import face_recognition as fr
from PIL import Image, ImageOps, ImageFile
import numpy as np
from pathlib import Path
import io, os, time, re
from typing import Dict, List, Tuple
from pydantic import BaseModel

try:
    from pillow_heif import register_heif_opener
    register_heif_opener()
except Exception:
    pass

# Optional fallback for wider image format support.
try:
    import imageio.v3 as iio
    _HAS_IMAGEIO = True
except Exception:
    iio = None
    _HAS_IMAGEIO = False

app = FastAPI()
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"], allow_methods=["*"], allow_headers=["*"],
)

# Allow very large images and avoid truncated-image errors.
Image.MAX_IMAGE_PIXELS = None
ImageFile.LOAD_TRUNCATED_IMAGES = True

# ===== Paths (absolute) =====
BASE_DIR = Path(__file__).resolve().parent
EMB_DIR  = BASE_DIR / "embeddings"      # tempat vektor .npy
FACES_DIR = BASE_DIR / "faces"          # opsional (kalau mau drop foto manual)
EMB_DIR.mkdir(parents=True, exist_ok=True)
FACES_DIR.mkdir(parents=True, exist_ok=True)

# ===== Tuning (bisa lewat ENV) =====
TOLERANCE   = float(os.getenv("FACE_TOLERANCE", "0.52"))   # 0.50–0.54 makin ketat
MARGIN_GAP  = float(os.getenv("FACE_MARGIN_GAP","0.06"))   # beda min best vs second
UPSAMPLE    = int(os.getenv("FACE_UPSAMPLE",    "0"))      # 0 cepat, 1 lebih teliti
MODEL       = os.getenv("FACE_MODEL", "hog")               # "hog" CPU; "cnn" kalau ada CUDA
ENC_JITTERS = int(os.getenv("FACE_JITTERS",     "2"))      # jitter buat robust
MAX_WIDTH   = int(os.getenv("FACE_MAX_WIDTH",   "800"))    # resize foto masuk untuk speed
AUTO_MIGRATE_FACES = os.getenv("AUTO_MIGRATE_FACES", "0") == "1"  # auto-scan faces/ on startup

# ===== In-memory store: name -> list[np.ndarray] =====
emb_by_person: Dict[str, List[np.ndarray]] = {}

# ---------- Utilities ----------
def _slug(s: str) -> str:
    s = re.sub(r'[^a-z0-9\-_. ]+', '', s.strip().lower())
    return re.sub(r'\s+', '_', s) or f"user_{int(time.time())}"

def _resize_np(img_np: np.ndarray, max_w: int) -> Tuple[np.ndarray, float]:
    h, w = img_np.shape[:2]
    if w <= max_w:
        return img_np, 1.0
    scale = max_w / float(w)
    new_w = max_w
    new_h = int(h * scale)
    pil = Image.fromarray(img_np)
    pil = pil.resize((new_w, new_h), Image.BILINEAR)
    return np.asarray(pil, dtype=np.uint8), scale

def _normalize_uint8(img: np.ndarray) -> np.ndarray:
    if img.dtype == np.uint8:
        return img
    img = img.astype(np.float32, copy=False)
    if img.size == 0:
        return np.zeros_like(img, dtype=np.uint8)
    maxv = float(np.max(img))
    if maxv <= 1.0:
        img = img * 255.0
    elif maxv > 255.0:
        img = img * (255.0 / maxv)
    return np.clip(img, 0, 255).astype(np.uint8)

def _to_rgb_uint8(raw: bytes) -> np.ndarray:
    try:
        pil = Image.open(io.BytesIO(raw))
        pil = ImageOps.exif_transpose(pil)
        if pil.mode != "RGB":
            pil = pil.convert("RGB")
        img = np.asarray(pil)
    except Exception:
        if not _HAS_IMAGEIO:
            raise
        img = iio.imread(raw)

    if img.ndim == 2:
        img = np.stack([img, img, img], axis=-1)
    elif img.ndim == 3 and img.shape[2] > 3:
        img = img[:, :, :3]

    img = _normalize_uint8(img)
    return np.ascontiguousarray(img, dtype=np.uint8)

def _encode_image_bytes(raw: bytes, jitters: int = 1):
    img = _to_rgb_uint8(raw)
    img, _ = _resize_np(img, MAX_WIDTH)
    img = np.ascontiguousarray(img, dtype=np.uint8)
    try:
        locs = fr.face_locations(img, model=MODEL, number_of_times_to_upsample=UPSAMPLE)
    except RuntimeError as e:
        print(f"[ENCODE][ERROR] {e} dtype={img.dtype} shape={img.shape}")
        raise
    if not locs:
        return None
    encs = fr.face_encodings(img, known_face_locations=[locs[0]], num_jitters=jitters)
    return encs[0] if encs else None

# ---------- Load/Reload ----------
def load_embeddings():
    global emb_by_person
    emb_by_person = {}
    persons = [p for p in EMB_DIR.iterdir() if p.is_dir()]
    for p in persons:
        vecs = []
        for f in p.iterdir():
            if f.suffix.lower() == ".npy":
                try:
                    vecs.append(np.load(str(f)))
                except Exception as e:
                    print(f"[LOAD][ERROR] {f}: {e}")
        if vecs:
            emb_by_person[p.name] = vecs
    print(f"[SUMMARY] persons={len(emb_by_person)} names={list(emb_by_person.keys())}")

# (opsional) migrasi foto lama di faces/ -> embeddings/
def migrate_faces_to_embeddings():
    created = 0
    persons = [p for p in FACES_DIR.iterdir() if p.is_dir()]
    for p in persons:
        out_dir = EMB_DIR / p.name
        out_dir.mkdir(parents=True, exist_ok=True)
        for imgp in p.iterdir():
            if imgp.suffix.lower() not in (".jpg", ".jpeg", ".png"):
                continue
            target = out_dir / f"{imgp.stem}.npy"
            if target.exists():
                continue
            try:
                raw = imgp.read_bytes()
                enc = _encode_image_bytes(raw, jitters=ENC_JITTERS)
                if enc is not None:
                    np.save(str(target), enc)
                    created += 1
            except Exception as e:
                print(f"[MIGRATE][ERROR] {p.name}/{imgp.name}: {e}")
    return {"success": True, "created": created, "emb_dir": str(EMB_DIR)}

# load saat startup
load_embeddings()
if AUTO_MIGRATE_FACES:
    try:
        res = migrate_faces_to_embeddings()
        load_embeddings()
        print(f"[AUTO] Migrated faces/ → embeddings/ on startup. created={res.get('created',0)}")
    except Exception as e:
        print(f"[AUTO][ERROR] migrate on startup: {e}")

# ---------- Matching ----------
def _best_match(cand: np.ndarray):
    best_name, best, second = None, 1e9, 1e9
    for name, vecs in emb_by_person.items():
        d = np.linalg.norm(np.array(vecs) - cand, axis=1)  # Euclidean
        dmin = float(np.min(d))
        if dmin < best:
            second = best
            best = dmin
            best_name = name
        elif dmin < second:
            second = dmin
    return best_name or "", best, second

class DeleteEmbeddingsRequest(BaseModel):
    name: str
    delete_faces: bool = False

# ---------- Endpoints ----------
@app.get("/health")
def health():
    return {
        "ok": True,
        "persons": len(emb_by_person),
        "names": list(emb_by_person.keys()),
        "config": dict(
            tolerance=TOLERANCE, margin_gap=MARGIN_GAP,
            upsample=UPSAMPLE, model=MODEL, max_width=MAX_WIDTH, jitter=ENC_JITTERS,
            auto_migrate_faces=AUTO_MIGRATE_FACES,
        )
    }

@app.get("/faces")
def faces():
    return {"count": len(emb_by_person), "names": list(emb_by_person.keys())}

@app.post("/reload-emb")
def reload_emb():
    load_embeddings()
    return {"success": True, "count": len(emb_by_person)}

# Trigger manual: scan faces/ -> embeddings/ lalu reload
@app.post("/reload-from-faces")
def reload_from_faces():
    res = migrate_faces_to_embeddings()
    load_embeddings()
    return {"success": True, "created": res.get("created", 0), "count": len(emb_by_person)}

@app.post("/delete-embeddings")
def delete_embeddings(payload: DeleteEmbeddingsRequest):
    try:
        person = _slug(payload.name)
        deleted = 0

        emb_dir = EMB_DIR / person
        if emb_dir.exists():
            for f in emb_dir.iterdir():
                if f.is_file() and f.suffix.lower() == ".npy":
                    f.unlink()
                    deleted += 1
            if not any(emb_dir.iterdir()):
                emb_dir.rmdir()

        if payload.delete_faces:
            faces_dir = FACES_DIR / person
            if faces_dir.exists():
                for f in faces_dir.iterdir():
                    if f.is_file():
                        f.unlink()
                if not any(faces_dir.iterdir()):
                    faces_dir.rmdir()

        load_embeddings()
        return {"success": True, "person": person, "deleted": deleted}
    except Exception as e:
        return {"success": False, "message": str(e)}

# Enroll: terima foto → encode → simpan .npy
@app.post("/enroll")
async def enroll(name: str = Form(...), image: UploadFile = File(...)):
    raw = await image.read()
    if not raw:
        return {"success": False, "message": "Empty file"}
    try:
        enc = _encode_image_bytes(raw, jitters=ENC_JITTERS)
    except Exception as e:
        msg = str(e)
        print(f"[ENROLL][ERROR] {msg}")
        if "Unsupported image type" in msg:
            return {"success": False, "message": "Format gambar tidak didukung. Gunakan JPG/PNG 8-bit."}
        return {"success": False, "message": f"Invalid image: {type(e).__name__}"}
    if enc is None:
        return {"success": False, "message": "No face found/encoded"}

    person = _slug(name)
    d = EMB_DIR / person
    d.mkdir(parents=True, exist_ok=True)
    path = d / f"{int(time.time()*1000)}.npy"
    np.save(str(path), enc)

    load_embeddings()  # refresh cache
    return {"success": True, "person": person, "saved": str(path)}

# Verify (dua path kompatibel)
async def _verify_core(upload: UploadFile):
    raw = await upload.read()
    if not raw:
        return {"success": False, "message": "Empty file"}

    try:
        enc = _encode_image_bytes(raw, jitters=1)  # verifikasi ringan
    except Exception as e:
        msg = str(e)
        print(f"[VERIFY][ERROR] {msg}")
        if "Unsupported image type" in msg:
            return {"success": False, "message": "Format gambar tidak didukung. Gunakan JPG/PNG 8-bit."}
        return {"success": False, "message": f"Invalid image: {type(e).__name__}"}
    if enc is None:
        return {"success": False, "message": "No face found/encoded"}

    if not emb_by_person:
        return {"success": False, "message": "No enrolled vectors"}

    name, best, second = _best_match(enc)

    if best > TOLERANCE:
        return {"success": False, "message": "Gagal: Wajah Tidak Dikenali", "best": best, "tol": TOLERANCE}

    if (second - best) < MARGIN_GAP:
        return {"success": False, "message": "Ambiguous (gap too small)", "best": best, "second": second, "gap": second - best}

    return {"success": True, "user": name, "distance": best, "gap": second - best, "tolerance": TOLERANCE}

@app.post("/verify-face")
async def verify_face(image: UploadFile = File(...)):
    try:
        return await _verify_core(image)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/api/verify-face")
async def verify_face_api(image: UploadFile = File(...)):
    try:
        return await _verify_core(image)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
