from fastapi import FastAPI, UploadFile, File
from fastapi.middleware.cors import CORSMiddleware
import face_recognition
from PIL import Image
import numpy as np
import os
from typing import List

app = FastAPI()

# ===== CORS agar bisa diakses dari Flutter/Frontend =====
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

# ===== Folder tempat simpan foto referensi =====
FACES_DIR = "faces"
known_face_encodings: List[np.ndarray] = []
known_face_names: List[str] = []

# ===== Fungsi load semua wajah di folder faces =====
def load_known_faces():
    global known_face_encodings, known_face_names
    known_face_encodings = []
    known_face_names = []

    if not os.path.exists(FACES_DIR):
        os.makedirs(FACES_DIR)

    for filename in os.listdir(FACES_DIR):
        if filename.lower().endswith((".jpg", ".jpeg", ".png")):
            path = os.path.join(FACES_DIR, filename)
            try:
                image = face_recognition.load_image_file(path)
                encodings = face_recognition.face_encodings(image)
                if encodings:
                    known_face_encodings.append(encodings[0])
                    known_face_names.append(os.path.splitext(filename)[0])
                    print(f"[INFO] Loaded face: {filename}")
                else:
                    print(f"[WARN] No face found in {filename}, skipped.")
            except Exception as e:
                print(f"[ERROR] Loading {filename}: {e}")

# Load wajah saat startup
load_known_faces()

# ===== Endpoint verify face =====
@app.post("/api/verify-face")
async def verify_face(image: UploadFile = File(...)):
    try:
        # Debug info file upload
        print(f"[DEBUG] Received file: {image.filename}, type: {image.content_type}")

        # Buka dan convert ke RGB
        img = Image.open(image.file).convert("RGB")
        image_np = np.array(img)

        # Cari wajah di foto yang dikirim
        face_locations = face_recognition.face_locations(image_np)
        face_encodings = face_recognition.face_encodings(image_np, face_locations)

        if not face_encodings:
            return {"success": False, "message": "No face found in the uploaded image"}

        # Cocokkan dengan wajah yang dikenal
        results = face_recognition.compare_faces(known_face_encodings, face_encodings[0], tolerance=0.5)
        if True in results:
            index = results.index(True)
            user_name = known_face_names[index]
            return {"success": True, "user": user_name}
        else:
            return {"success": False, "message": "Face not recognized"}

    except Exception as e:
        return {"success": False, "message": str(e)}

# ===== Endpoint reload faces tanpa restart server =====
@app.post("/reload-faces")
def reload_faces():
    load_known_faces()
    return {"success": True, "loaded_faces": known_face_names}
