"""Parallel InsightFace / ONNX recognizer for MNHS Attendance.

This deliberately uses the same reference-photo folder and JSON response shape
as recognize_face.py.  It can therefore be evaluated or enabled without
changing students, attendance records, the PHP portal, or the Android app.
"""
import hashlib
import os
import pickle
import time

import cv2
import numpy as np
from insightface.app import FaceAnalysis
from PIL import Image, ImageOps

KNOWN_FACES_DIR = r"C:\Biometric_Capstone\known_faces"
MODEL_ROOT = os.path.join(os.path.dirname(__file__), "insightface-models")
CACHE_PATH = os.path.join(os.path.dirname(__file__), "face_embedding_cache_onnx.pkl")
CACHE_VERSION = 1
MATCH_SIMILARITY = 0.46
RUNNER_UP_MARGIN = 0.045
MIN_FACE_EDGE = 42

_app = None
_cached_key = None
_cached_embeddings = np.empty((0, 512), dtype=np.float32)
_cached_ids = []
_next_fingerprint_check = 0.0


def model():
    """Load ONNX models once. CPU is the safe default for the school laptop."""
    global _app
    if _app is None:
        _app = FaceAnalysis(
            name="buffalo_s",
            root=MODEL_ROOT,
            providers=["CPUExecutionProvider"],
        )
        _app.prepare(ctx_id=0, det_size=(320, 320))
    return _app


def load_bgr(path):
    with Image.open(path) as image:
        rgb = np.asarray(ImageOps.exif_transpose(image).convert("RGB"))
    return cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)


def face_area(face):
    left, top, right, bottom = [float(value) for value in face.bbox]
    return max(0.0, right - left) * max(0.0, bottom - top)


def embedding_for_reference(path):
    try:
        faces = model().get(load_bgr(path))
    except Exception:
        return None
    if not faces:
        return None
    candidate = max(faces, key=face_area)
    embedding = getattr(candidate, "normed_embedding", None)
    if embedding is None:
        return None
    return np.asarray(embedding, dtype=np.float32)


def reference_entries(references=None):
    entries = []
    if isinstance(references, list) and references:
        for reference in references:
            if not isinstance(reference, dict):
                continue
            student_id = str(reference.get("student_id", "")).strip()
            path = str(reference.get("path", "")).strip()
            if student_id and path and os.path.isfile(path):
                entries.append((student_id, path))
        return sorted(entries)
    if not os.path.isdir(KNOWN_FACES_DIR):
        return entries
    for name in sorted(os.listdir(KNOWN_FACES_DIR)):
        if name.lower().endswith((".jpg", ".jpeg", ".png")):
            entries.append((os.path.splitext(name)[0].split("_")[0], os.path.join(KNOWN_FACES_DIR, name)))
    return entries


def fingerprint(entries):
    records = []
    for student_id, path in entries:
        try:
            records.append(f"{student_id}:{path}:{os.path.getmtime(path)}:{os.path.getsize(path)}")
        except OSError:
            continue
    return hashlib.sha256((str(CACHE_VERSION) + "|" + "|".join(records)).encode()).hexdigest()


def known_faces(references=None):
    global _cached_key, _cached_embeddings, _cached_ids, _next_fingerprint_check
    if len(_cached_ids) and time.monotonic() < _next_fingerprint_check:
        return _cached_embeddings, _cached_ids
    entries = reference_entries(references)
    key = fingerprint(entries)
    _next_fingerprint_check = time.monotonic() + 8.0
    if key == _cached_key:
        return _cached_embeddings, _cached_ids
    try:
        with open(CACHE_PATH, "rb") as file:
            cache = pickle.load(file)
        if cache.get("fingerprint") == key:
            _cached_key = key
            _cached_embeddings = np.asarray(cache["embeddings"], dtype=np.float32)
            _cached_ids = list(cache["ids"])
            return _cached_embeddings, _cached_ids
    except Exception:
        pass

    embeddings, ids = [], []
    for student_id, path in entries:
        embedding = embedding_for_reference(path)
        if embedding is None:
            continue
        embeddings.append(embedding)
        ids.append(student_id)
    matrix = np.asarray(embeddings, dtype=np.float32)
    if matrix.size == 0:
        matrix = np.empty((0, 512), dtype=np.float32)
    with open(CACHE_PATH, "wb") as file:
        pickle.dump({"fingerprint": key, "embeddings": matrix, "ids": ids}, file)
    _cached_key, _cached_embeddings, _cached_ids = key, matrix, ids
    return matrix, ids


def refresh_known_faces(references=None):
    global _next_fingerprint_check
    _next_fingerprint_check = 0.0
    return known_faces(references)


def best_match(embeddings, ids, candidate):
    if len(ids) == 0:
        return None
    similarities = np.asarray(embeddings @ candidate, dtype=np.float32)
    per_student = {}
    for index, similarity in enumerate(similarities):
        student_id = ids[index]
        per_student[student_id] = max(per_student.get(student_id, -1.0), float(similarity))
    ranked = sorted(per_student.items(), key=lambda item: item[1], reverse=True)
    best_id, best_similarity = ranked[0]
    runner_up = ranked[1][1] if len(ranked) > 1 else -1.0
    if best_similarity >= MATCH_SIMILARITY and best_similarity - runner_up >= RUNNER_UP_MARGIN:
        return best_id, best_similarity
    return None


def result_box(face):
    left, top, right, bottom = [int(round(value)) for value in face.bbox]
    return {"top": top, "right": right, "bottom": bottom, "left": left}


def recognize(image_path, references=None):
    if not image_path or not os.path.isfile(image_path):
        return {"match": False, "faces": [], "matches": [], "message": "No camera frame received."}
    try:
        faces = model().get(load_bgr(image_path))
    except Exception:
        return {"match": False, "faces": [], "matches": [], "message": "ONNX recognition could not process this frame."}
    embeddings, ids = known_faces(references)
    detected, matches, seen = [], [], set()
    for face in faces:
        box = result_box(face)
        if box["right"] - box["left"] < MIN_FACE_EDGE or box["bottom"] - box["top"] < MIN_FACE_EDGE:
            continue
        detected.append({"box": box})
        candidate = getattr(face, "normed_embedding", None)
        if candidate is None:
            continue
        match = best_match(embeddings, ids, np.asarray(candidate, dtype=np.float32))
        if not match or match[0] in seen:
            continue
        seen.add(match[0])
        student_id, similarity = match
        matches.append({
            "student_id": student_id,
            "confidence": round(similarity, 3),
            "distance": round(1.0 - similarity, 3),
            "box": box,
            "engine": "insightface-onnx",
        })
    return {
        "match": bool(matches),
        "faces": detected,
        "detected_faces": detected,
        "unregistered_faces": [],
        "matches": matches,
        "message": None if matches else ("Face not recognized." if detected else "No face detected."),
    }
