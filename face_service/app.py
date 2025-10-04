from fastapi import FastAPI, File, UploadFile
import face_recognition
import numpy as np

app = FastAPI()

# database sederhana (sementara di memori, nanti bisa disambung ke Laravel/DB)
known_faces = {}
known_names = []

@app.post("/register_face/")
async def register_face(name: str, file: UploadFile = File(...)):
    # Baca gambar upload
    img = face_recognition.load_image_file(file.file)
    encodings = face_recognition.face_encodings(img)

    if len(encodings) == 0:
        return {"status": "error", "message": "Tidak ada wajah terdeteksi"}

    encoding = encodings[0]
    known_faces[name] = encoding
    known_names.append(name)

    return {"status": "success", "message": f"Wajah {name} berhasil didaftarkan"}

@app.post("/recognize_face/")
async def recognize_face(file: UploadFile = File(...)):
    img = face_recognition.load_image_file(file.file)
    encodings = face_recognition.face_encodings(img)

    if len(encodings) == 0:
        return {"status": "error", "message": "Tidak ada wajah terdeteksi"}

    face_to_check = encodings[0]

    for name, known_encoding in known_faces.items():
        matches = face_recognition.compare_faces([known_encoding], face_to_check)
        if matches[0]:
            return {"status": "success", "recognized_as": name}

    return {"status": "error", "message": "Wajah tidak dikenali"}
