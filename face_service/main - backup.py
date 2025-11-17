# main.py (baseline)
from fastapi import FastAPI, UploadFile, File
from fastapi.middleware.cors import CORSMiddleware
import face_recognition as fr
from PIL import Image, ImageOps
import numpy as np
import io, os

app = FastAPI()
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"], allow_methods=["*"], allow_headers=["*"],
)

FACES_DIR = "faces"
known_enc = []
known_names = []

def load_known():
    global known_enc, known_names
    known_enc, known_names = [], []
    os.makedirs(FACES_DIR, exist_ok=True)
    for fn in os.listdir(FACES_DIR):
        if fn.lower().endswith((".jpg",".jpeg",".png")):
            img = fr.load_image_file(os.path.join(FACES_DIR, fn))
            encs = fr.face_encodings(img)
            if encs:
                known_enc.append(encs[0])
                known_names.append(os.path.splitext(fn)[0])

load_known()

@app.get("/health")
def health(): return {"ok": True, "faces": len(known_names)}

@app.post("/verify-face")
async def verify_face(image: UploadFile = File(...)):
    raw = await image.read()
    pil = Image.open(io.BytesIO(raw))
    pil = ImageOps.exif_transpose(pil).convert("RGB")
    img = np.array(pil)

    locs = fr.face_locations(img, model="hog", number_of_times_to_upsample=0)
    if not locs:
        return {"success": False, "message": "No face found"}

    encs = fr.face_encodings(img, known_face_locations=[locs[0]])
    if not encs:
        return {"success": False, "message": "No face encoding"}

    if not known_enc:
        return {"success": False, "message": "No enrolled faces"}

    dists = fr.face_distance(known_enc, encs[0])
    i = int(np.argmin(dists))
    if dists[i] <= 0.58:
        return {"success": True, "user": known_names[i]}
    return {"success": False, "message": "Face not recognized"}
